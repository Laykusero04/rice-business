<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/inventory.php');
    exit;
}

$lotId = (int) ($_POST['stock_lot_id'] ?? 0);
$notes = trim((string) ($_POST['notes'] ?? ''));
$millName = trim((string) ($_POST['mill_name'] ?? ''));

$redirect = trim((string) ($_POST['redirect'] ?? ''));
if ($redirect === '' || !str_starts_with($redirect, '/rice-business/frontend/')) {
    $redirect = '/rice-business/frontend/inventory.php';
}
$queryJoin = str_contains($redirect, '?') ? '&' : '?';

if ($lotId <= 0) {
    header('Location: ' . $redirect . $queryJoin . 'error=lot');
    exit;
}

try {
    renameStockLot($pdo, $lotId, $notes, $millName !== '' ? $millName : null);
    header('Location: ' . $redirect . $queryJoin . 'success=renamed');
} catch (Throwable $e) {
    $code = $e->getMessage() === 'lot_missing' ? 'lot' : 'save';
    header('Location: ' . $redirect . $queryJoin . 'error=' . $code);
}

exit;
