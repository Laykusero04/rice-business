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
$physicalQty = (float) ($_POST['physical_qty'] ?? -1);
$notes = trim($_POST['notes'] ?? '');

if ($productId <= 0 || $physicalQty < 0) {
    header('Location: /rice-business/frontend/inventory.php?error=invalid');
    exit;
}

$notes = $notes !== '' ? $notes : 'Physical count';

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, unit, stock FROM products WHERE id = ? FOR UPDATE');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) {
        throw new RuntimeException('product');
    }

    $unit = $product['unit'] ?? 'kg';
    $oldStock = round((float) $product['stock'], 2);
    $delta = setPhysicalProductStock($pdo, $productId, $physicalQty);

    if (abs($delta) < 0.001) {
        $pdo->commit();
        header('Location: /rice-business/frontend/inventory.php?success=counted');
        exit;
    }

    $absQty = abs($delta);
    $qtyFormatted = $unit === 'pc'
        ? (string) ((int) round($absQty))
        : number_format($absQty, 2);

    $movement = $pdo->prepare(
        'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $movement->execute([
        $productId,
        'ADJUSTMENT',
        $absQty,
        'COUNT-' . date('YmdHis'),
        $notes . ' (set to ' . ($unit === 'pc'
            ? (string) ((int) round($physicalQty))
            : number_format($physicalQty, 2))
            . ' ' . $unit . ', was ' . ($unit === 'pc'
            ? (string) ((int) round($oldStock))
            : number_format($oldStock, 2))
            . '; ' . ($delta > 0 ? '+' : '-') . $qtyFormatted . ')',
    ]);

    $pdo->commit();
    header('Location: /rice-business/frontend/inventory.php?success=counted');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = match ($e->getMessage()) {
        'stock', 'lot_stock' => 'stock',
        'invalid' => 'invalid',
        'product' => 'invalid',
        default => 'save',
    };
    header('Location: /rice-business/frontend/inventory.php?error=' . $code);
}

exit;
