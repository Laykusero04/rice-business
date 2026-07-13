<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/inventory.php');
    exit;
}

$productId = (int) ($_POST['product_id'] ?? 0);
$quantity = (float) ($_POST['quantity'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

if ($productId <= 0 || $quantity == 0.0) {
    header('Location: /rice-business/frontend/inventory.php?error=invalid');
    exit;
}

$notes = $notes !== '' ? $notes : 'Manual stock adjustment';

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, stock FROM products WHERE id = ? FOR UPDATE');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new RuntimeException('product');
    }

    $newStock = (float) $product['stock'] + $quantity;
    if ($newStock < 0) {
        throw new RuntimeException('stock');
    }

    $update = $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?');
    $update->execute([$newStock, $productId]);

    $movement = $pdo->prepare(
        'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $movement->execute([
        $productId,
        'ADJUSTMENT',
        abs($quantity),
        'ADJUST-' . date('YmdHis'),
        $notes . ' (' . ($quantity > 0 ? '+' : '-') . number_format(abs($quantity), 2) . ' kg)',
    ]);

    $pdo->commit();
    header('Location: /rice-business/frontend/inventory.php?success=adjusted');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = $e->getMessage() === 'stock' ? 'stock' : 'save';
    header('Location: /rice-business/frontend/inventory.php?error=' . $code);
}

exit;
