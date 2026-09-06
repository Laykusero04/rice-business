<?php

/**
 * Stock lot helpers — priced inventory batches per product.
 */

/** Remaining at or below this is treated as none / unsalable crumb (kg or unit). */
const LOT_NONE_THRESHOLD = 0.05;

/** Remaining below this (but above none) is flagged LOW. */
const LOT_LOW_THRESHOLD = 1.0;

function isLotUnsalable(float $remaining): bool
{
    return round($remaining, 2) < LOT_NONE_THRESHOLD;
}

function isLotLow(float $remaining): bool
{
    $remaining = round($remaining, 2);
    return $remaining >= LOT_NONE_THRESHOLD && $remaining < LOT_LOW_THRESHOLD;
}

/**
 * @param array{
 *   total_cost?:float|null,
 *   weighed_kg?:float|null,
 *   mill_name?:string|null,
 *   lot_kind?:string
 * } $options
 */
/**
 * Return the next global label (Batch-N).
 * One label per purchase (whole buy), shared by all product lines.
 * Call inside an open transaction. Does not run DDL (avoids implicit commit).
 */
function allocateNextBatchLabel(PDO $pdo): string
{
    $row = $pdo->query('SELECT next_batch_no FROM batch_counters WHERE id = 1 FOR UPDATE')->fetch();
    if (!$row) {
        $maxStmt = $pdo->query(
            "SELECT COALESCE(MAX(
                CASE
                  WHEN batch_label REGEXP '^Batch-[0-9]+$' THEN CAST(SUBSTRING(batch_label, 7) AS UNSIGNED)
                  ELSE 0
                END
             ), 0) AS max_no
             FROM purchases"
        );
        $maxNo = (int) ($maxStmt->fetch()['max_no'] ?? 0);
        $start = $maxNo + 1;
        $pdo->prepare('INSERT INTO batch_counters (id, next_batch_no) VALUES (1, ?)')
            ->execute([$start + 1]);
        return 'Batch-' . $start;
    }

    $current = (int) $row['next_batch_no'];
    if ($current < 1) {
        $current = 1;
    }
    $pdo->prepare('UPDATE batch_counters SET next_batch_no = ? WHERE id = 1')
        ->execute([$current + 1]);

    return 'Batch-' . $current;
}

/**
 * Preview next Batch-N without consuming the counter.
 */
function peekNextBatchLabel(PDO $pdo): string
{
    try {
        $row = $pdo->query('SELECT next_batch_no FROM batch_counters WHERE id = 1')->fetch();
        $n = $row ? max(1, (int) $row['next_batch_no']) : 1;
    } catch (PDOException $e) {
        $n = 1;
    }

    return 'Batch-' . $n;
}

/**
 * Optional: which sell product this purchase is intended for.
 */
function ensurePurchaseForProductColumn(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->query('SELECT for_product_id FROM purchases LIMIT 1');
        $ready = true;
        return;
    } catch (PDOException $e) {
        // missing
    }
    try {
        $pdo->exec(
            'ALTER TABLE purchases
             ADD COLUMN for_product_id INT UNSIGNED NULL AFTER batch_label'
        );
    } catch (PDOException $e) {
        // concurrent
    }
    $ready = true;
}

function createStockLot(
    PDO $pdo,
    int $productId,
    float $quantity,
    float $buyingPrice,
    string $purchasedAt,
    ?int $purchaseItemId = null,
    ?string $notes = null,
    array $options = []
): int {
    $quantity = round($quantity, 2);
    $buyingPrice = round($buyingPrice, 2);

    if ($quantity <= 0) {
        throw new RuntimeException('lot_qty');
    }

    $totalCost = array_key_exists('total_cost', $options) && $options['total_cost'] !== null
        ? round((float) $options['total_cost'], 2)
        : round($quantity * $buyingPrice, 2);
    $weighedKg = array_key_exists('weighed_kg', $options) && $options['weighed_kg'] !== null
        ? round((float) $options['weighed_kg'], 2)
        : null;
    $millName = isset($options['mill_name']) ? trim((string) $options['mill_name']) : null;
    if ($millName === '') {
        $millName = null;
    }
    $lotKind = trim((string) ($options['lot_kind'] ?? 'purchase'));
    if ($lotKind === '') {
        $lotKind = 'purchase';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO stock_lots
         (product_id, purchase_item_id, buying_price, quantity_original, quantity_remaining,
          purchased_at, notes, mill_name, total_cost, weighed_kg, lot_kind)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $productId,
        $purchaseItemId,
        $buyingPrice,
        $quantity,
        $quantity,
        $purchasedAt,
        $notes,
        $millName,
        $totalCost,
        $weighedKg,
        $lotKind,
    ]);

    return (int) $pdo->lastInsertId();
}

function deductStockLot(PDO $pdo, int $lotId, float $quantity): array
{
    $quantity = round($quantity, 2);
    if ($quantity <= 0) {
        throw new RuntimeException('lot_qty');
    }

    $stmt = $pdo->prepare('SELECT * FROM stock_lots WHERE id = ? FOR UPDATE');
    $stmt->execute([$lotId]);
    $lot = $stmt->fetch();

    if (!$lot) {
        throw new RuntimeException('lot_missing');
    }

    if (!empty($lot['closed_at'])) {
        throw new RuntimeException('lot_closed');
    }

    $remaining = round((float) $lot['quantity_remaining'], 2);

    // Soft deduct: tingi kg is often inaccurate — never block the sale.
    // Reduce remaining when possible; keep sale qty as requested for money.
    $take = $remaining > 0 ? min($quantity, $remaining) : 0.0;
    $take = round($take, 2);

    if ($take > 0) {
        $update = $pdo->prepare(
            'UPDATE stock_lots
             SET quantity_remaining = quantity_remaining - ?
             WHERE id = ? AND quantity_remaining >= ?'
        );
        $update->execute([$take, $lotId, $take]);
        if ($update->rowCount() === 0) {
            $take = 0.0;
        }
    }

    $lot['quantity_remaining'] = $remaining;
    $lot['_deducted'] = $quantity;
    $lot['_stock_taken'] = $take;

    return $lot;
}

function restoreStockLot(PDO $pdo, int $lotId, float $quantity): void
{
    $quantity = round($quantity, 2);
    if ($quantity <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE stock_lots SET quantity_remaining = quantity_remaining + ?, closed_at = NULL WHERE id = ?'
    );
    $stmt->execute([$quantity, $lotId]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('lot_missing');
    }
}

/**
 * Zero out leftover on a batch (empty sack / waste / close) and reduce product stock.
 *
 * @return array{product_id:int, quantity:float, unit:string, cost_amount:float, buying_price:float}
 */
function writeOffStockLot(PDO $pdo, int $lotId, bool $markClosed = true): array
{
    $stmt = $pdo->prepare(
        'SELECT sl.*, p.unit, p.stock
         FROM stock_lots sl
         INNER JOIN products p ON p.id = sl.product_id
         WHERE sl.id = ?
         FOR UPDATE'
    );
    $stmt->execute([$lotId]);
    $lot = $stmt->fetch();

    if (!$lot) {
        throw new RuntimeException('lot_missing');
    }

    $remaining = round((float) $lot['quantity_remaining'], 2);
    if ($remaining <= 0) {
        throw new RuntimeException('lot_empty');
    }

    $productId = (int) $lot['product_id'];
    $productStock = round((float) $lot['stock'], 2);
    if ($productStock + 0.0001 < $remaining) {
        throw new RuntimeException('stock');
    }

    $buyingPrice = (float) $lot['buying_price'];
    $costAmount = round($remaining * $buyingPrice, 2);

    if ($markClosed) {
        $pdo->prepare(
            'UPDATE stock_lots SET quantity_remaining = 0, closed_at = NOW() WHERE id = ?'
        )->execute([$lotId]);
    } else {
        $pdo->prepare(
            'UPDATE stock_lots SET quantity_remaining = 0 WHERE id = ?'
        )->execute([$lotId]);
    }

    $pdo->prepare(
        'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
    )->execute([$remaining, $productId, $remaining]);

    return [
        'product_id' => $productId,
        'quantity' => $remaining,
        'unit' => (string) ($lot['unit'] ?? 'kg'),
        'cost_amount' => $costAmount,
        'buying_price' => $buyingPrice,
    ];
}

function recordLotWriteoff(
    PDO $pdo,
    int $lotId,
    float $quantity,
    float $costAmount,
    string $reason,
    ?string $movementReference = null
): void {
    $pdo->prepare(
        'INSERT INTO stock_lot_writeoffs (stock_lot_id, quantity, cost_amount, reason, movement_reference)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $lotId,
        round($quantity, 2),
        round($costAmount, 2),
        $reason,
        $movementReference,
    ]);
}

/**
 * Rename / relabel a batch (sell-facing note + optional mill/supplier name).
 */
function renameStockLot(PDO $pdo, int $lotId, string $notes, ?string $millName = null): void
{
    $notes = trim($notes);
    $millName = $millName !== null ? trim($millName) : null;
    if ($millName === '') {
        $millName = null;
    }

    $stmt = $pdo->prepare(
        'UPDATE stock_lots SET notes = ?, mill_name = ? WHERE id = ?'
    );
    $stmt->execute([
        $notes !== '' ? $notes : null,
        $millName,
        $lotId,
    ]);

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT id FROM stock_lots WHERE id = ?');
        $check->execute([$lotId]);
        if (!$check->fetch()) {
            throw new RuntimeException('lot_missing');
        }
    }
}

/**
 * Move an open batch to another sellable product (rebrand / rename destination).
 */
function reassignStockLot(PDO $pdo, int $lotId, int $newProductId): void
{
    $stmt = $pdo->prepare(
        'SELECT sl.*, p.stock AS old_stock, p.status AS old_status
         FROM stock_lots sl
         INNER JOIN products p ON p.id = sl.product_id
         WHERE sl.id = ?
         FOR UPDATE'
    );
    $stmt->execute([$lotId]);
    $lot = $stmt->fetch();

    if (!$lot) {
        throw new RuntimeException('lot_missing');
    }

    $remaining = round((float) $lot['quantity_remaining'], 2);
    if ($remaining <= 0) {
        throw new RuntimeException('lot_empty');
    }

    $oldProductId = (int) $lot['product_id'];
    if ($oldProductId === $newProductId) {
        return;
    }

    $newStmt = $pdo->prepare('SELECT id, stock, status, buying_price FROM products WHERE id = ? FOR UPDATE');
    $newStmt->execute([$newProductId]);
    $newProduct = $newStmt->fetch();

    if (!$newProduct || ($newProduct['status'] ?? '') !== 'active') {
        throw new RuntimeException('product');
    }

    $oldStock = round((float) $lot['old_stock'], 2);
    if ($oldStock + 0.0001 < $remaining) {
        throw new RuntimeException('stock');
    }

    $pdo->prepare('UPDATE stock_lots SET product_id = ? WHERE id = ?')
        ->execute([$newProductId, $lotId]);

    $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?')
        ->execute([$remaining, $oldProductId, $remaining]);

    $pdo->prepare('UPDATE products SET stock = stock + ?, buying_price = ? WHERE id = ?')
        ->execute([$remaining, (float) $lot['buying_price'], $newProductId]);
}

/**
 * Blend source lots into a new sellable lot on the target product.
 *
 * @param list<array{lot_id:int, quantity:float}> $inputs
 * @return array{mix_lot_id:int, quantity:float, total_cost:float, buying_price:float}
 */
function mixStockLots(
    PDO $pdo,
    int $outputProductId,
    array $inputs,
    string $batchLabel,
    float $lossPercent = 0.0,
    ?string $purchasedAt = null
): array {
    $batchLabel = trim($batchLabel);
    if ($batchLabel === '') {
        $batchLabel = 'Mix';
    }
    $lossPercent = max(0.0, min(50.0, $lossPercent));
    $purchasedAt = $purchasedAt ?: date('Y-m-d');

    $productStmt = $pdo->prepare(
        'SELECT id, name, product_type, unit, status, stock FROM products WHERE id = ? FOR UPDATE'
    );
    $productStmt->execute([$outputProductId]);
    $product = $productStmt->fetch();
    if (!$product || ($product['status'] ?? '') !== 'active') {
        throw new RuntimeException('product');
    }

    $normalized = [];
    foreach ($inputs as $input) {
        $lotId = (int) ($input['lot_id'] ?? 0);
        $qty = round((float) ($input['quantity'] ?? 0), 2);
        if ($lotId <= 0 || $qty <= 0) {
            continue;
        }
        if (!isset($normalized[$lotId])) {
            $normalized[$lotId] = 0.0;
        }
        $normalized[$lotId] = round($normalized[$lotId] + $qty, 2);
    }

    if (count($normalized) < 1) {
        throw new RuntimeException('inputs');
    }

    $components = [];
    $totalQtyIn = 0.0;
    $totalCost = 0.0;

    foreach ($normalized as $lotId => $qty) {
        $lot = deductStockLot($pdo, $lotId, $qty);
        $taken = (float) $lot['_deducted'];
        $costAmount = round($taken * (float) $lot['buying_price'], 2);

        $srcProductId = (int) $lot['product_id'];
        $pdo->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
        )->execute([$taken, $srcProductId, $taken]);

        $components[] = [
            'source_lot_id' => $lotId,
            'quantity_used' => $taken,
            'cost_amount' => $costAmount,
            'source_product_id' => $srcProductId,
        ];
        $totalQtyIn = round($totalQtyIn + $taken, 2);
        $totalCost = round($totalCost + $costAmount, 2);
    }

    if ($totalQtyIn <= 0 || $totalCost < 0) {
        throw new RuntimeException('inputs');
    }

    $qtyOut = round($totalQtyIn * (1 - ($lossPercent / 100)), 2);
    if ($qtyOut < LOT_NONE_THRESHOLD) {
        throw new RuntimeException('loss');
    }

    $buyingPrice = round($totalCost / $qtyOut, 2);
    if ($buyingPrice < 0) {
        $buyingPrice = 0.0;
    }

    foreach ($components as &$comp) {
        $comp['cost_share'] = $totalCost > 0
            ? round($comp['cost_amount'] / $totalCost, 6)
            : round(1 / count($components), 6);
    }
    unset($comp);

    $mixLotId = createStockLot(
        $pdo,
        $outputProductId,
        $qtyOut,
        $buyingPrice,
        $purchasedAt,
        null,
        $batchLabel,
        [
            'total_cost' => $totalCost,
            'lot_kind' => 'mix',
        ]
    );

    $compStmt = $pdo->prepare(
        'INSERT INTO stock_lot_components
         (mix_lot_id, source_lot_id, quantity_used, cost_amount, cost_share)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($components as $comp) {
        $compStmt->execute([
            $mixLotId,
            $comp['source_lot_id'],
            $comp['quantity_used'],
            $comp['cost_amount'],
            $comp['cost_share'],
        ]);
    }

    $pdo->prepare(
        'UPDATE products SET stock = stock + ?, buying_price = ? WHERE id = ?'
    )->execute([$qtyOut, $buyingPrice, $outputProductId]);

    return [
        'mix_lot_id' => $mixLotId,
        'quantity' => $qtyOut,
        'total_cost' => $totalCost,
        'buying_price' => $buyingPrice,
        'components' => $components,
        'output_product_id' => $outputProductId,
    ];
}

/**
 * Set product on-hand to a physical count; syncs batches then product.stock.
 * Returns delta applied (target - old stock).
 */
function setPhysicalProductStock(PDO $pdo, int $productId, float $targetStock): float
{
    $targetStock = round($targetStock, 2);
    if ($targetStock < 0) {
        throw new RuntimeException('invalid');
    }

    $stmt = $pdo->prepare('SELECT id, stock, buying_price, unit FROM products WHERE id = ? FOR UPDATE');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new RuntimeException('product');
    }

    $unit = $product['unit'] ?? 'kg';
    if ($unit === 'pc') {
        $isWhole = abs($targetStock - round($targetStock)) < 0.0001;
        if (!$isWhole) {
            throw new RuntimeException('invalid');
        }
        $targetStock = (float) round($targetStock);
    }

    $oldStock = round((float) $product['stock'], 2);
    $delta = round($targetStock - $oldStock, 2);

    syncProductLotsToStock(
        $pdo,
        $productId,
        $targetStock,
        (float) $product['buying_price']
    );

    $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')
        ->execute([$targetStock, $productId]);

    return $delta;
}

/**
 * Reverse lots created by purchase items. Only allowed when untouched.
 *
 * @param list<array{id:int|string}> $purchaseItems rows with id
 */
function reversePurchaseLots(PDO $pdo, array $purchaseItems): void
{
    $find = $pdo->prepare(
        'SELECT id, quantity_original, quantity_remaining FROM stock_lots WHERE purchase_item_id = ? FOR UPDATE'
    );
    $delete = $pdo->prepare('DELETE FROM stock_lots WHERE id = ?');

    foreach ($purchaseItems as $item) {
        $purchaseItemId = (int) $item['id'];
        $find->execute([$purchaseItemId]);
        $lot = $find->fetch();

        if (!$lot) {
            continue;
        }

        $original = round((float) $lot['quantity_original'], 2);
        $remaining = round((float) $lot['quantity_remaining'], 2);

        if (abs($original - $remaining) > 0.001) {
            throw new RuntimeException('lot_used');
        }

        $delete->execute([(int) $lot['id']]);
    }
}

/**
 * Open lots grouped by product_id, ordered oldest first.
 *
 * @return array<int, list<array<string, mixed>>>
 */
function fetchOpenLotsByProduct(PDO $pdo, ?int $extraQtySaleId = null): array
{
    // Include empty batches too — tingi leftovers are unreliable; batch is the sell unit of work.
    $sql = 'SELECT sl.*, p.name AS product_name, p.product_type, p.unit, p.kg_per_sack
            FROM stock_lots sl
            INNER JOIN products p ON p.id = sl.product_id
            WHERE sl.closed_at IS NULL';
    $params = [];

    // When editing a sale, temporarily include qty still reserved on that sale's lots
    if ($extraQtySaleId !== null && $extraQtySaleId > 0) {
        $sql = 'SELECT
                    sl.id,
                    sl.product_id,
                    sl.purchase_item_id,
                    sl.buying_price,
                    sl.quantity_original,
                    sl.quantity_remaining + COALESCE(reserved.qty, 0) AS quantity_remaining,
                    sl.purchased_at,
                    sl.notes,
                    sl.mill_name,
                    sl.total_cost,
                    sl.weighed_kg,
                    sl.lot_kind,
                    sl.closed_at,
                    sl.created_at,
                    p.name AS product_name,
                    p.product_type,
                    p.unit,
                    p.kg_per_sack
                FROM stock_lots sl
                INNER JOIN products p ON p.id = sl.product_id
                LEFT JOIN (
                    SELECT stock_lot_id, SUM(quantity) AS qty
                    FROM sale_items
                    WHERE sale_id = ? AND stock_lot_id IS NOT NULL
                    GROUP BY stock_lot_id
                ) reserved ON reserved.stock_lot_id = sl.id
                WHERE sl.closed_at IS NULL OR COALESCE(reserved.qty, 0) > 0';
        $params[] = $extraQtySaleId;
    }

    $sql .= ' ORDER BY sl.purchased_at ASC, sl.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $byProduct = [];
    foreach ($rows as $row) {
        $pid = (int) $row['product_id'];
        if (!isset($byProduct[$pid])) {
            $byProduct[$pid] = [];
        }
        $byProduct[$pid][] = $row;
    }

    return $byProduct;
}

/**
 * Sync lots after product create/edit sets an absolute stock level.
 */
function syncProductLotsToStock(PDO $pdo, int $productId, float $targetStock, float $buyingPrice): void
{
    $targetStock = round($targetStock, 2);
    $buyingPrice = round($buyingPrice, 2);

    $sumStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(quantity_remaining), 0) FROM stock_lots
         WHERE product_id = ? AND closed_at IS NULL'
    );
    $sumStmt->execute([$productId]);
    $current = round((float) $sumStmt->fetchColumn(), 2);

    $delta = round($targetStock - $current, 2);

    if (abs($delta) < 0.001) {
        return;
    }

    if ($delta > 0) {
        createStockLot(
            $pdo,
            $productId,
            $delta,
            $buyingPrice,
            date('Y-m-d'),
            null,
            'Product stock sync',
            ['lot_kind' => 'adjustment']
        );
        return;
    }

    // Decrease: deduct from oldest lots first
    $need = abs($delta);
    $lotsStmt = $pdo->prepare(
        'SELECT id, quantity_remaining FROM stock_lots
         WHERE product_id = ? AND quantity_remaining > 0 AND closed_at IS NULL
         ORDER BY purchased_at ASC, id ASC
         FOR UPDATE'
    );
    $lotsStmt->execute([$productId]);
    $lots = $lotsStmt->fetchAll();

    foreach ($lots as $lot) {
        if ($need <= 0) {
            break;
        }
        $available = (float) $lot['quantity_remaining'];
        $take = min($available, $need);
        deductStockLot($pdo, (int) $lot['id'], $take);
        $need = round($need - $take, 2);
    }

    if ($need > 0.001) {
        throw new RuntimeException('lot_stock');
    }
}

/**
 * Human-readable batch label for UI — batch name first; kg left is approximate only.
 */
function formatLotLabel(array $lot): string
{
    $buyingPrice = (float) $lot['buying_price'];
    $unit = $lot['unit'] ?? 'kg';
    $productType = $lot['product_type'] ?? 'RICE';
    $kgPerSack = (float) ($lot['kg_per_sack'] ?? 25);
    if ($kgPerSack <= 0) {
        $kgPerSack = 25;
    }

    $date = $lot['purchased_at'] ?? '';
    $batchNote = trim((string) ($lot['notes'] ?? ''));
    $millName = trim((string) ($lot['mill_name'] ?? ''));
    $skipNotes = ['Opening stock', 'Product stock sync'];
    $showBatch = $batchNote !== '' && !in_array($batchNote, $skipNotes, true)
        && !preg_match('/^Purchase #\d+$/', $batchNote);

    $lead = $showBatch ? $batchNote : ('Batch #' . (int) ($lot['id'] ?? 0));
    if ($millName !== '') {
        $lead .= ' (' . $millName . ')';
    }

    $remaining = round((float) ($lot['quantity_remaining'] ?? 0), 2);

    if ($productType === 'RICE') {
        $sacksLeft = $kgPerSack > 0 ? round($remaining / $kgPerSack, 2) : $remaining;
        $sackPrice = round($buyingPrice * $kgPerSack, 2);
        $core = number_format($sacksLeft, 2) . ' sack'
            . ($sacksLeft == 1.0 ? '' : 's')
            . ' · ₱' . number_format($sackPrice, 2) . '/sack'
            . ($date !== '' ? ' · ' . $date : '');
    } else {
        $core = number_format($remaining, 2) . ' ' . $unit
            . ' · ₱' . number_format($buyingPrice, 2) . '/' . $unit
            . ($date !== '' ? ' · ' . $date : '');
    }

    return $lead . ' · ' . $core;
}

/**
 * Ensure stock_lots.source_purchase_id exists (links sell batch → purchase).
 * Run outside an open transaction (DDL can commit).
 */
function ensureSourcePurchaseColumn(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->query('SELECT source_purchase_id FROM stock_lots LIMIT 1');
        $ready = true;
        return;
    } catch (PDOException $e) {
        // Column missing — add it.
    }
    try {
        $pdo->exec(
            'ALTER TABLE stock_lots
             ADD COLUMN source_purchase_id INT UNSIGNED NULL AFTER purchase_item_id'
        );
    } catch (PDOException $e) {
        // Concurrent add or already exists.
    }
    $ready = true;
}

/**
 * Purchases available to fund a sell batch, with cost still free to allocate.
 *
 * @return list<array{id:int,batch_label:string,purchase_date:string,total:float,allocated:float,remaining_cost:float,supplier_name:?string,label:string}>
 */
function fetchPurchaseBatchesForSelect(PDO $pdo): array
{
    ensureSourcePurchaseColumn($pdo);

    $rows = $pdo->query(
        "SELECT p.id,
                COALESCE(NULLIF(TRIM(p.batch_label), ''), CONCAT('Purchase #', p.id)) AS batch_label,
                p.purchase_date,
                p.total,
                s.name AS supplier_name,
                COALESCE((
                  SELECT SUM(sl.total_cost)
                  FROM stock_lots sl
                  WHERE sl.source_purchase_id = p.id
                    AND sl.total_cost IS NOT NULL
                ), 0) AS allocated
         FROM purchases p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         ORDER BY p.id DESC
         LIMIT 100"
    )->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $total = round((float) $row['total'], 2);
        $allocated = round((float) $row['allocated'], 2);
        $remaining = round(max(0, $total - $allocated), 2);
        $batchLabel = (string) $row['batch_label'];
        $supplier = $row['supplier_name'] ?? null;
        $label = $batchLabel
            . ' · ' . ($row['purchase_date'] ?? '')
            . ' · ₱' . number_format($total, 2)
            . ($supplier ? ' · ' . $supplier : '')
            . ' · left ₱' . number_format($remaining, 2);

        $out[] = [
            'id' => (int) $row['id'],
            'batch_label' => $batchLabel,
            'purchase_date' => (string) ($row['purchase_date'] ?? ''),
            'total' => $total,
            'allocated' => $allocated,
            'remaining_cost' => $remaining,
            'supplier_name' => $supplier,
            'label' => $label,
        ];
    }

    return $out;
}

/**
 * Create a sellable batch on a product. Quantity is in kg (or product unit).
 */
function createSellBatch(
    PDO $pdo,
    int $productId,
    float $buyingPrice = 0.0,
    ?string $notes = null,
    ?string $purchasedAt = null,
    float $quantity = 0.0,
    float $totalCost = 0.0,
    ?int $sourcePurchaseId = null,
    ?int $purchaseItemId = null
): int {
    ensureSourcePurchaseColumn($pdo);

    $buyingPrice = round($buyingPrice, 2);
    if ($buyingPrice < 0) {
        $buyingPrice = 0.0;
    }
    $quantity = round($quantity, 2);
    if ($quantity < 0) {
        $quantity = 0.0;
    }
    $totalCost = round($totalCost, 2);
    if ($totalCost < 0) {
        $totalCost = 0.0;
    }
    $notes = $notes !== null ? trim($notes) : '';
    if ($notes === '') {
        $notes = allocateNextBatchLabel($pdo);
    }
    $purchasedAt = $purchasedAt !== null && $purchasedAt !== ''
        ? $purchasedAt
        : date('Y-m-d');

    $sourcePurchaseId = $sourcePurchaseId !== null && $sourcePurchaseId > 0
        ? $sourcePurchaseId
        : null;
    $purchaseItemId = $purchaseItemId !== null && $purchaseItemId > 0
        ? $purchaseItemId
        : null;

    $stmt = $pdo->prepare(
        'INSERT INTO stock_lots
         (product_id, purchase_item_id, source_purchase_id, buying_price, quantity_original, quantity_remaining,
          purchased_at, notes, mill_name, total_cost, weighed_kg, lot_kind)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, \'opening\')'
    );
    $stmt->execute([
        $productId,
        $purchaseItemId,
        $sourcePurchaseId,
        $buyingPrice,
        $quantity,
        $quantity,
        $purchasedAt,
        $notes,
        $totalCost,
    ]);

    $lotId = (int) $pdo->lastInsertId();

    if ($quantity > 0) {
        $pdo->prepare(
            'UPDATE products SET stock = stock + ?, buying_price = CASE WHEN ? > 0 THEN ? ELSE buying_price END WHERE id = ?'
        )->execute([$quantity, $buyingPrice, $buyingPrice, $productId]);
    }

    return $lotId;
}

/**
 * When purchase line names match active products, create sellable stock for those products.
 * Skips lines that already have a stock lot (via purchase_item_id).
 *
 * @return int Number of sell batches created
 */
function linkMatchingPurchaseItemsToStock(
    PDO $pdo,
    int $purchaseId,
    string $batchLabel,
    string $purchaseDate
): int {
    ensureSourcePurchaseColumn($pdo);

    $itemsStmt = $pdo->prepare(
        'SELECT pi.* FROM purchase_items pi WHERE pi.purchase_id = ? ORDER BY pi.id ASC'
    );
    $itemsStmt->execute([$purchaseId]);
    $items = $itemsStmt->fetchAll();

    $findProduct = $pdo->prepare(
        "SELECT id FROM products
         WHERE status = 'active' AND LOWER(TRIM(name)) = LOWER(TRIM(?))
         LIMIT 1"
    );
    $existingLot = $pdo->prepare(
        'SELECT id FROM stock_lots WHERE purchase_item_id = ? LIMIT 1'
    );
    $updateItemProduct = $pdo->prepare(
        'UPDATE purchase_items SET product_id = ? WHERE id = ?'
    );

    $created = 0;
    foreach ($items as $item) {
        $itemId = (int) $item['id'];
        $itemName = trim((string) ($item['item_name'] ?? ''));
        if ($itemName === '') {
            continue;
        }

        $existingLot->execute([$itemId]);
        if ($existingLot->fetch()) {
            continue;
        }

        $findProduct->execute([$itemName]);
        $product = $findProduct->fetch();
        if (!$product) {
            continue;
        }

        $productId = (int) $product['id'];
        $quantity = round((float) $item['quantity'], 2);
        $buyingPrice = round((float) $item['buying_price'], 2);
        $subtotal = round((float) $item['subtotal'], 2);
        if ($quantity <= 0) {
            continue;
        }

        $updateItemProduct->execute([$productId, $itemId]);

        createSellBatch(
            $pdo,
            $productId,
            $buyingPrice,
            $batchLabel !== '' ? $batchLabel : null,
            $purchaseDate,
            $quantity,
            $subtotal,
            $purchaseId,
            $itemId
        );
        $created++;
    }

    return $created;
}

/**
 * Remove unused sell lots that were linked to a purchase via source_purchase_id
 * (manual "Link sell batch" only — skips lots already tied to purchase_items).
 */
function reverseSourcePurchaseLots(PDO $pdo, int $purchaseId): void
{
    ensureSourcePurchaseColumn($pdo);

    $find = $pdo->prepare(
        'SELECT id, product_id, quantity_original, quantity_remaining
         FROM stock_lots
         WHERE source_purchase_id = ?
           AND purchase_item_id IS NULL
         FOR UPDATE'
    );
    $find->execute([$purchaseId]);
    $lots = $find->fetchAll();

    $delete = $pdo->prepare('DELETE FROM stock_lots WHERE id = ?');
    $reduceStock = $pdo->prepare(
        'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
    );

    foreach ($lots as $lot) {
        $original = round((float) $lot['quantity_original'], 2);
        $remaining = round((float) $lot['quantity_remaining'], 2);

        if (abs($original - $remaining) > 0.001) {
            throw new RuntimeException('lot_used');
        }

        $productId = (int) $lot['product_id'];
        $qty = $remaining;
        $delete->execute([(int) $lot['id']]);

        if ($qty > 0 && $productId > 0) {
            $reduceStock->execute([$qty, $productId, $qty]);
            if ($reduceStock->rowCount() === 0) {
                throw new RuntimeException('stock:linked lot');
            }
        }
    }
}
