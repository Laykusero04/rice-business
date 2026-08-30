<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/purchases.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    header('Location: /rice-business/frontend/purchases.php?error=invalid');
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $purchase = $stmt->fetch();

    if (!$purchase) {
        throw new RuntimeException('missing');
    }

    $itemsStmt = $pdo->prepare(
        'SELECT pi.id, pi.product_id, pi.quantity, pr.name
         FROM purchase_items pi
         INNER JOIN products pr ON pr.id = pi.product_id
         WHERE pi.purchase_id = ?'
    );
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll();

    // Only allow delete if purchase batches were not sold yet
    reversePurchaseLots($pdo, $items);

    $reverseStock = $pdo->prepare(
        'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
    );
    foreach ($items as $item) {
        $reverseStock->execute([
            (float) $item['quantity'],
            (int) $item['product_id'],
            (float) $item['quantity'],
        ]);
        if ($reverseStock->rowCount() === 0) {
            throw new RuntimeException('stock:' . $item['name']);
        }
    }

    $pdo->prepare('DELETE FROM stock_movements WHERE reference = ?')
        ->execute(['PURCHASE-' . $id]);

    $pdo->prepare('DELETE FROM expenses WHERE purchase_id = ?')->execute([$id]);

    $pdo->prepare('DELETE FROM purchases WHERE id = ?')->execute([$id]);

    $pdo->commit();
    header('Location: /rice-business/frontend/purchases.php?success=deleted');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = $e->getMessage();
    if ($message === 'lot_used') {
        header('Location: /rice-business/frontend/purchase_view.php?id=' . $id . '&error=lot_used');
        exit;
    }
    if (str_starts_with($message, 'stock:')) {
        header(
            'Location: /rice-business/frontend/purchase_view.php?id=' . $id
            . '&error=stock&product=' . urlencode(substr($message, 6))
        );
        exit;
    }
    if ($message === 'missing') {
        header('Location: /rice-business/frontend/purchases.php?error=notfound');
        exit;
    }
    header('Location: /rice-business/frontend/purchase_view.php?id=' . $id . '&error=delete');
}

exit;
