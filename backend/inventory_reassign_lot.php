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
$newProductId = (int) ($_POST['product_id'] ?? 0);

$redirect = trim((string) ($_POST['redirect'] ?? ''));
if ($redirect === '' || !str_starts_with($redirect, '/rice-business/frontend/')) {
    $redirect = '/rice-business/frontend/inventory.php';
}
$queryJoin = str_contains($redirect, '?') ? '&' : '?';

if ($lotId <= 0 || $newProductId <= 0) {
    header('Location: ' . $redirect . $queryJoin . 'error=lot');
    exit;
}

try {
    $pdo->beginTransaction();

    $lotBefore = $pdo->prepare('SELECT product_id, quantity_remaining FROM stock_lots WHERE id = ?');
    $lotBefore->execute([$lotId]);
    $before = $lotBefore->fetch();
    if (!$before) {
        throw new RuntimeException('lot_missing');
    }

    $oldProductId = (int) $before['product_id'];
    $qty = round((float) $before['quantity_remaining'], 2);

    reassignStockLot($pdo, $lotId, $newProductId);

    if ($oldProductId !== $newProductId && $qty > 0) {
        $move = $pdo->prepare(
            'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ts = date('YmdHis');
        $move->execute([
            $oldProductId,
            'ADJUSTMENT',
            $qty,
            'REASSIGN-OUT-' . $lotId . '-' . $ts,
            'Reassign batch #' . $lotId . ' to product #' . $newProductId,
        ]);
        $move->execute([
            $newProductId,
            'ADJUSTMENT',
            $qty,
            'REASSIGN-IN-' . $lotId . '-' . $ts,
            'Received reassigned batch #' . $lotId . ' from product #' . $oldProductId,
        ]);
    }

    $pdo->commit();
    header('Location: ' . $redirect . $queryJoin . 'success=reassigned');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = match ($e->getMessage()) {
        'stock', 'lot_stock' => 'stock',
        'lot_empty', 'lot_missing', 'lot' => 'lot',
        'product' => 'product',
        default => 'save',
    };
    header('Location: ' . $redirect . $queryJoin . 'error=' . $code);
}

exit;
