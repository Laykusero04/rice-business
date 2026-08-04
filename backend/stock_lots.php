<?php

/**
 * Stock lot helpers — priced inventory stacks per product.
 */

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

    if ((float) $lot['quantity_remaining'] + 0.0001 < $quantity) {
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
 * Human-readable stack label for UI (sack price for rice).
 */
function formatLotLabel(array $lot): string
{
    $remaining = (float) $lot['quantity_remaining'];
    $buyingPrice = (float) $lot['buying_price'];
    $unit = $lot['unit'] ?? 'kg';
    $productType = $lot['product_type'] ?? 'RICE';
    $kgPerSack = (float) ($lot['kg_per_sack'] ?? 25);
    if ($kgPerSack <= 0) {
        $kgPerSack = 25;
    }

    $date = $lot['purchased_at'] ?? '';

    if ($productType === 'RICE') {
        $sackPrice = round($buyingPrice * $kgPerSack, 2);
        $qtyLabel = number_format($remaining, 2) . ' kg left';
        return '₱' . number_format($sackPrice, 2) . '/sack · ' . $qtyLabel
            . ($date !== '' ? ' · ' . $date : '');
    }

    $decimals = $unit === 'pc' ? 0 : 2;
    return '₱' . number_format($buyingPrice, 2) . '/' . $unit . ' · '
        . number_format($remaining, $decimals) . ' ' . $unit . ' left'
        . ($date !== '' ? ' · ' . $date : '');
}
