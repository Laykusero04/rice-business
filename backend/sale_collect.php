<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/sales.php');
    exit;
}

$saleId = (int) ($_POST['sale_id'] ?? 0);
$amount = (float) ($_POST['amount'] ?? 0);
$collectMethod = trim($_POST['collect_method'] ?? 'cash');
$notes = trim($_POST['notes'] ?? '');

$allowedMethods = ['cash', 'gcash', 'bank'];
if (!in_array($collectMethod, $allowedMethods, true)) {
    $collectMethod = 'cash';
}

if ($saleId <= 0 || $amount <= 0) {
    header('Location: /rice-business/frontend/sale_view.php?id=' . max(1, $saleId) . '&error=amount');
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();

    if (!$sale) {
        throw new RuntimeException('missing');
    }

    $total = (float) $sale['total'];
    $amountPaid = (float) $sale['amount_paid'];
    $balance = $total - $amountPaid;

    if ($balance <= 0) {
        throw new RuntimeException('paid');
    }

    if ($amount > $balance + 0.001) {
        throw new RuntimeException('amount');
    }

    $newPaid = round($amountPaid + $amount, 2);
    if ($newPaid >= $total) {
        $newPaid = $total;
        $status = 'paid';
    } else {
        $status = 'partial';
    }

    $methodLabels = [
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'bank' => 'Bank Transfer',
    ];

    $noteLine = 'Collected ₱' . number_format($amount, 2)
        . ' via ' . ($methodLabels[$collectMethod] ?? $collectMethod)
        . ' on ' . date('Y-m-d');

    if ($notes !== '') {
        $noteLine .= ' — ' . $notes;
    }

    $existingNotes = trim((string) ($sale['notes'] ?? ''));
    $newNotes = $existingNotes === '' ? $noteLine : $existingNotes . "\n" . $noteLine;

    $update = $pdo->prepare(
        'UPDATE sales
         SET amount_paid = ?, payment_status = ?, notes = ?
         WHERE id = ?'
    );
    $update->execute([$newPaid, $status, $newNotes, $saleId]);

    $pdo->commit();
    header('Location: /rice-business/frontend/sale_view.php?id=' . $saleId . '&success=collected');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = $e->getMessage() === 'amount' ? 'amount' : 'save';
    header('Location: /rice-business/frontend/sale_view.php?id=' . $saleId . '&error=' . $code);
}

exit;
