<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/inventory.php');
    exit;
}

$mode = trim((string) ($_POST['mode'] ?? 'adjust'));
if (!in_array($mode, ['count', 'adjust', 'writeoff'], true)) {
    $mode = 'adjust';
}

$redirect = trim((string) ($_POST['redirect'] ?? ''));
if ($redirect === '' || !str_starts_with($redirect, '/rice-business/frontend/')) {
    $redirect = '/rice-business/frontend/inventory.php';
}
$queryJoin = str_contains($redirect, '?') ? '&' : '?';

$productId = (int) ($_POST['product_id'] ?? 0);
$lotId = (int) ($_POST['stock_lot_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

try {
    $pdo->beginTransaction();

    if ($mode === 'count') {
        $rawQty = $_POST['quantity'] ?? $_POST['physical_qty'] ?? null;
        if ($productId <= 0 || $rawQty === null || $rawQty === '') {
            throw new RuntimeException('invalid');
        }
        $physicalQty = (float) $rawQty;
        if ($physicalQty < 0) {
            throw new RuntimeException('invalid');
        }

        $notes = $notes !== '' ? $notes : 'Physical count';

        $stmt = $pdo->prepare('SELECT id, unit, stock FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            throw new RuntimeException('product');
        }

        $unit = $product['unit'] ?? 'kg';
        $oldStock = round((float) $product['stock'], 2);
        $delta = setPhysicalProductStock($pdo, $productId, $physicalQty);

        if (abs($delta) >= 0.001) {
            $absQty = abs($delta);
            $qtyFormatted = $unit === 'pc'
                ? (string) ((int) round($absQty))
                : number_format($absQty, 2);
            $physicalFormatted = $unit === 'pc'
                ? (string) ((int) round($physicalQty))
                : number_format($physicalQty, 2);
            $oldFormatted = $unit === 'pc'
                ? (string) ((int) round($oldStock))
                : number_format($oldStock, 2);

            $pdo->prepare(
                'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $productId,
                'ADJUSTMENT',
                $absQty,
                'COUNT-' . date('YmdHis'),
                $notes . ' (set to ' . $physicalFormatted . ' ' . $unit
                    . ', was ' . $oldFormatted
                    . '; ' . ($delta > 0 ? '+' : '-') . $qtyFormatted . ')',
            ]);
        }

        $pdo->commit();
        header('Location: ' . $redirect . $queryJoin . 'success=counted');
        exit;
    }

    if ($mode === 'writeoff') {
        $reason = trim((string) ($_POST['reason'] ?? 'writeoff'));
        if (!in_array($reason, ['writeoff', 'close'], true)) {
            $reason = 'writeoff';
        }

        if ($lotId <= 0) {
            throw new RuntimeException('lot');
        }

        $result = writeOffStockLot($pdo, $lotId, true);

        if ($productId > 0 && (int) $result['product_id'] !== $productId) {
            throw new RuntimeException('lot');
        }

        $unit = $result['unit'];
        $qty = $result['quantity'];
        $qtyFormatted = $unit === 'pc'
            ? (string) ((int) round($qty))
            : number_format($qty, 2);

        $ref = strtoupper($reason) . '-' . $lotId . '-' . date('YmdHis');
        $noteVerb = $reason === 'close' ? 'Close batch' : 'Write-off leftover (empty sack / waste)';
        if ($notes !== '') {
            $noteVerb .= ' — ' . $notes;
        }

        $pdo->prepare(
            'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $result['product_id'],
            'ADJUSTMENT',
            $qty,
            $ref,
            $noteVerb . ' (-' . $qtyFormatted . ' ' . $unit . ', batch #' . $lotId . ')',
        ]);

        recordLotWriteoff(
            $pdo,
            $lotId,
            $qty,
            (float) $result['cost_amount'],
            $reason,
            $ref
        );

        $pdo->commit();
        $success = $reason === 'close' ? 'closed' : 'writeoff';
        header('Location: ' . $redirect . $queryJoin . 'success=' . $success);
        exit;
    }

    $rawQty = $_POST['quantity'] ?? '';
    $quantity = (float) $rawQty;

    if ($productId <= 0 || $rawQty === '' || $quantity == 0.0) {
        throw new RuntimeException('invalid');
    }

    $notes = $notes !== '' ? $notes : 'Manual stock adjustment';

    $stmt = $pdo->prepare('SELECT id, unit, stock, buying_price FROM products WHERE id = ? FOR UPDATE');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new RuntimeException('product');
    }

    $unit = $product['unit'] ?? 'kg';
    if ($unit === 'pc') {
        $isWhole = abs($quantity - round($quantity)) < 0.0001;
        if (!$isWhole) {
            throw new RuntimeException('invalid');
        }
    }

    $newStock = (float) $product['stock'] + $quantity;
    if ($newStock < 0) {
        throw new RuntimeException('stock');
    }

    $absQty = abs($quantity);

    if ($quantity < 0) {
        if ($lotId <= 0) {
            throw new RuntimeException('lot');
        }
        $lot = deductStockLot($pdo, $lotId, $absQty);
        if ((int) $lot['product_id'] !== $productId) {
            throw new RuntimeException('lot');
        }
    } elseif ($lotId > 0) {
        $lotStmt = $pdo->prepare('SELECT * FROM stock_lots WHERE id = ? FOR UPDATE');
        $lotStmt->execute([$lotId]);
        $lot = $lotStmt->fetch();
        if (!$lot || (int) $lot['product_id'] !== $productId) {
            throw new RuntimeException('lot');
        }
        $pdo->prepare(
            'UPDATE stock_lots
             SET quantity_remaining = quantity_remaining + ?,
                 quantity_original = quantity_original + ?
             WHERE id = ?'
        )->execute([$absQty, $absQty, $lotId]);
    } else {
        createStockLot(
            $pdo,
            $productId,
            $absQty,
            (float) $product['buying_price'],
            date('Y-m-d'),
            null,
            'Inventory adjustment',
            ['lot_kind' => 'adjustment']
        );
    }

    $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')
        ->execute([$newStock, $productId]);

    $qtyFormatted = $unit === 'pc'
        ? (string) ((int) round($absQty))
        : number_format($absQty, 2);
    $lotNote = $lotId > 0 ? ' (batch #' . $lotId . ')' : '';

    $pdo->prepare(
        'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $productId,
        'ADJUSTMENT',
        $absQty,
        'ADJUST-' . date('YmdHis'),
        $notes . ' (' . ($quantity > 0 ? '+' : '-') . $qtyFormatted . ' ' . $unit . ')' . $lotNote,
    ]);

    $pdo->commit();
    header('Location: ' . $redirect . $queryJoin . 'success=adjusted');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = match ($e->getMessage()) {
        'stock', 'lot_stock' => 'stock',
        'invalid', 'product' => 'invalid',
        'lot', 'lot_missing', 'lot_mismatch', 'lot_empty' => 'lot',
        default => 'save',
    };
    header('Location: ' . $redirect . $queryJoin . 'error=' . $code);
}

exit;
