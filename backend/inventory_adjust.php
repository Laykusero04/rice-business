<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/inventory.php');
    exit;
}

$productId = (int) ($_POST['product_id'] ?? 0);
$lotId = (int) ($_POST['stock_lot_id'] ?? 0);
$quantity = (float) ($_POST['quantity'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

if ($productId <= 0 || $quantity == 0.0) {
    header('Location: /rice-business/frontend/inventory.php?error=invalid');
    exit;
}

$notes = $notes !== '' ? $notes : 'Manual stock adjustment';

try {
    $pdo->beginTransaction();

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
        // Decrease: must pick a stack with enough remaining
        if ($lotId <= 0) {
            throw new RuntimeException('lot');
        }
        $lot = deductStockLot($pdo, $lotId, $absQty);
        if ((int) $lot['product_id'] !== $productId) {
            throw new RuntimeException('lot');
        }
    } else {
        // Increase: top up selected lot, or create a new adjustment stack
        if ($lotId > 0) {
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
                'Inventory adjustment'
            );
        }
    }

    $update = $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?');
    $update->execute([$newStock, $productId]);

    $movement = $pdo->prepare(
        'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $qtyFormatted = $unit === 'pc'
        ? (string) ((int) round($absQty))
        : number_format($absQty, 2);
    $lotNote = $lotId > 0 ? ' (stack #' . $lotId . ')' : '';
    $movement->execute([
        $productId,
        'ADJUSTMENT',
        $absQty,
        'ADJUST-' . date('YmdHis'),
        $notes . ' (' . ($quantity > 0 ? '+' : '-') . $qtyFormatted . ' ' . $unit . ')' . $lotNote,
    ]);

    $pdo->commit();
    header('Location: /rice-business/frontend/inventory.php?success=adjusted');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = match ($e->getMessage()) {
        'stock', 'lot_stock' => 'stock',
        'invalid' => 'invalid',
        'lot', 'lot_missing', 'lot_mismatch' => 'lot',
        default => 'save',
    };
    header('Location: /rice-business/frontend/inventory.php?error=' . $code);
}

exit;
