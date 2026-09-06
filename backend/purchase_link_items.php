<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/purchases.php');
    exit;
}

$purchaseId = (int) ($_POST['purchase_id'] ?? 0);
if ($purchaseId <= 0) {
    header('Location: /rice-business/frontend/purchases.php?error=invalid');
    exit;
}

$redirect = '/rice-business/frontend/purchase_view.php?id=' . $purchaseId;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT id, batch_label, purchase_date FROM purchases WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$purchaseId]);
    $purchase = $stmt->fetch();
    if (!$purchase) {
        throw new RuntimeException('missing');
    }

    $batchLabel = trim((string) ($purchase['batch_label'] ?? ''));
    $purchaseDate = (string) ($purchase['purchase_date'] ?? date('Y-m-d'));

    $created = linkMatchingPurchaseItemsToStock(
        $pdo,
        $purchaseId,
        $batchLabel,
        $purchaseDate
    );

    $pdo->commit();

    if ($created > 0) {
        header('Location: ' . $redirect . '&success=items_stocked&count=' . $created);
    } else {
        header('Location: ' . $redirect . '&error=no_match');
    }
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = $e->getMessage();
    if ($message === 'missing') {
        header('Location: /rice-business/frontend/purchases.php?error=notfound');
        exit;
    }
    if ($message === 'lot_used') {
        header('Location: ' . $redirect . '&error=lot_used');
        exit;
    }
    header('Location: ' . $redirect . '&error=save');
    exit;
}
