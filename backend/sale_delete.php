<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/sales.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    header('Location: /rice-business/frontend/sales.php?error=invalid');
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id FROM sales WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $sale = $stmt->fetch();

    if (!$sale) {
        throw new RuntimeException('missing');
    }

    $itemsStmt = $pdo->prepare(
        'SELECT product_id, quantity FROM sale_items WHERE sale_id = ?'
    );
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll();

    $restoreStock = $pdo->prepare(
        'UPDATE products SET stock = stock + ? WHERE id = ?'
    );
    $deleteMovements = $pdo->prepare(
        'DELETE FROM stock_movements WHERE reference = ?'
    );

    foreach ($items as $item) {
        $restoreStock->execute([
            (float) $item['quantity'],
            (int) $item['product_id'],
        ]);
    }

    $deleteMovements->execute(['SALE-' . $id]);

    $deleteSale = $pdo->prepare('DELETE FROM sales WHERE id = ?');
    $deleteSale->execute([$id]);

    $pdo->commit();
    header('Location: /rice-business/frontend/sales.php?success=deleted');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: /rice-business/frontend/sales.php?error=delete');
}

exit;
