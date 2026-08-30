<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/gcash_fees.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/gcash_new.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$isEdit = $id > 0;

$txnType = normalizeGcashTxnType(trim($_POST['txn_type'] ?? 'cash_in'));
$cashinAmount = round((float) ($_POST['cashin_amount'] ?? 0), 2);
$feeCharged = round((float) ($_POST['fee_charged'] ?? 0), 2);
$cashinDate = trim($_POST['cashin_date'] ?? '');
$customerName = trim($_POST['customer_name'] ?? '');
$gcashNumber = trim($_POST['gcash_number'] ?? '');
$referenceNo = trim($_POST['reference_no'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$redirect = $isEdit
    ? '/rice-business/frontend/gcash_edit.php?id=' . $id
    : '/rice-business/frontend/gcash_new.php';

if ($cashinDate === '' || $cashinAmount <= 0 || $feeCharged < 0) {
    header('Location: ' . $redirect . ($isEdit ? '&' : '?') . 'error=invalid');
    exit;
}

$suggested = lookupGcashSuggestedFee($pdo, $cashinAmount);
$suggestedFee = $suggested !== null ? $suggested : $feeCharged;
$summary = gcashMoneySummary($txnType, $cashinAmount, $feeCharged);
$totalCollected = $summary['total_collected'];

$user = currentUser();
$customerName = $customerName !== '' ? $customerName : null;
$gcashNumber = $gcashNumber !== '' ? $gcashNumber : null;
$referenceNo = $referenceNo !== '' ? $referenceNo : null;
$notes = $notes !== '' ? $notes : null;

try {
    if ($isEdit) {
        $check = $pdo->prepare('SELECT id FROM gcash_cashins WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetch()) {
            header('Location: /rice-business/frontend/gcash.php?error=notfound');
            exit;
        }

        $stmt = $pdo->prepare(
            'UPDATE gcash_cashins
             SET txn_type = ?, cashin_amount = ?, suggested_fee = ?, fee_charged = ?, total_collected = ?,
                 customer_name = ?, gcash_number = ?, reference_no = ?, notes = ?, cashin_date = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $txnType,
            $cashinAmount,
            $suggestedFee,
            $feeCharged,
            $totalCollected,
            $customerName,
            $gcashNumber,
            $referenceNo,
            $notes,
            $cashinDate,
            $id,
        ]);
        header('Location: /rice-business/frontend/gcash_view.php?id=' . $id . '&success=updated');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO gcash_cashins
             (txn_type, cashin_amount, suggested_fee, fee_charged, total_collected,
              customer_name, gcash_number, reference_no, notes, cashin_date, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $txnType,
            $cashinAmount,
            $suggestedFee,
            $feeCharged,
            $totalCollected,
            $customerName,
            $gcashNumber,
            $referenceNo,
            $notes,
            $cashinDate,
            $user['id'] ?? null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        header('Location: /rice-business/frontend/gcash_view.php?id=' . $newId . '&success=created');
    }
} catch (Throwable $e) {
    header('Location: ' . $redirect . ($isEdit ? '&' : '?') . 'error=save');
}

exit;
