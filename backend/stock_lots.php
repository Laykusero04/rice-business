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

function createStockLot(
    PDO $pdo,
    int $productId,
    float $quantity,
    float $buyingPrice,
    string $purchasedAt,
    ?int $purchaseItemId = null,
    ?string $notes = null
): int {
    $quantity = round($quantity, 2);
    $buyingPrice = round($buyingPrice, 2);

    if ($quantity <= 0) {
        throw new RuntimeException('lot_qty');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO stock_lots
         (product_id, purchase_item_id, buying_price, quantity_original, quantity_remaining, purchased_at, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $productId,
        $purchaseItemId,
        $buyingPrice,
        $quantity,
        $quantity,
        $purchasedAt,
        $notes,
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

    $remaining = round((float) $lot['quantity_remaining'], 2);

    // Tiny overshoot from peso→kg rounding: use exact remaining
    if ($quantity > $remaining && ($quantity - $remaining) <= LOT_NONE_THRESHOLD + 0.0001) {
        $quantity = $remaining;
    }

    if ($remaining + 0.0001 < $quantity || $quantity <= 0) {
        throw new RuntimeException('lot_stock');
    }

    $update = $pdo->prepare(
        'UPDATE stock_lots
         SET quantity_remaining = quantity_remaining - ?
         WHERE id = ? AND quantity_remaining >= ?'
    );
    $update->execute([$quantity, $lotId, $quantity]);

    if ($update->rowCount() === 0) {
        throw new RuntimeException('lot_stock');
    }

    $lot['quantity_remaining'] = $remaining;
    $lot['_deducted'] = $quantity;

    return $lot;
}

function restoreStockLot(PDO $pdo, int $lotId, float $quantity): void
{
    $quantity = round($quantity, 2);
    if ($quantity <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE stock_lots SET quantity_remaining = quantity_remaining + ? WHERE id = ?'
    );
    $stmt->execute([$quantity, $lotId]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('lot_missing');
    }
}

/**
 * Zero out leftover on a batch (empty sack / waste) and reduce product stock.
 *
 * @return array{product_id:int, quantity:float, unit:string}
 */
function writeOffStockLot(PDO $pdo, int $lotId): array
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

    $pdo->prepare(
        'UPDATE stock_lots SET quantity_remaining = 0 WHERE id = ?'
    )->execute([$lotId]);

    $pdo->prepare(
        'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
    )->execute([$remaining, $productId, $remaining]);

    return [
        'product_id' => $productId,
        'quantity' => $remaining,
        'unit' => (string) ($lot['unit'] ?? 'kg'),
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
    $sql = 'SELECT sl.*, p.name AS product_name, p.product_type, p.unit, p.kg_per_sack
            FROM stock_lots sl
            INNER JOIN products p ON p.id = sl.product_id
            WHERE sl.quantity_remaining > 0';
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
                WHERE (sl.quantity_remaining + COALESCE(reserved.qty, 0)) > 0';
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
        'SELECT COALESCE(SUM(quantity_remaining), 0) FROM stock_lots WHERE product_id = ?'
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
            'Product stock sync'
        );
        return;
    }

    // Decrease: deduct from oldest lots first
    $need = abs($delta);
    $lotsStmt = $pdo->prepare(
        'SELECT id, quantity_remaining FROM stock_lots
         WHERE product_id = ? AND quantity_remaining > 0
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
 * Human-readable batch label for UI (sack price for rice).
 */
function formatLotLabel(array $lot): string
{
    $remaining = round((float) $lot['quantity_remaining'], 2);
    $buyingPrice = (float) $lot['buying_price'];
    $unit = $lot['unit'] ?? 'kg';
    $productType = $lot['product_type'] ?? 'RICE';
    $kgPerSack = (float) ($lot['kg_per_sack'] ?? 25);
    if ($kgPerSack <= 0) {
        $kgPerSack = 25;
    }

    $date = $lot['purchased_at'] ?? '';
    $batchNote = trim((string) ($lot['notes'] ?? ''));
    // Skip generic system notes in the leading label
    $skipNotes = ['Opening stock', 'Product stock sync'];
    $showBatch = $batchNote !== '' && !in_array($batchNote, $skipNotes, true)
        && !preg_match('/^Purchase #\d+$/', $batchNote);

    $statusPrefix = '';
    if (isLotUnsalable($remaining)) {
        $statusPrefix = 'NONE · ';
    } elseif (isLotLow($remaining)) {
        $statusPrefix = 'LOW · ';
    }

    if ($productType === 'RICE') {
        $sackPrice = round($buyingPrice * $kgPerSack, 2);
        $qtyLabel = number_format($remaining, 2) . ' kg left';
        $core = '₱' . number_format($sackPrice, 2) . '/sack · ' . $qtyLabel
            . ($date !== '' ? ' · ' . $date : '');
    } else {
        $decimals = $unit === 'pc' ? 0 : 2;
        $core = '₱' . number_format($buyingPrice, 2) . '/' . $unit . ' · '
            . number_format($remaining, $decimals) . ' ' . $unit . ' left'
            . ($date !== '' ? ' · ' . $date : '');
    }

    if ($showBatch) {
        return $statusPrefix . $batchNote . ' · ' . $core;
    }

    return $statusPrefix . $core;
}
