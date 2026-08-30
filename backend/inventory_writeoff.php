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

if ($lotId <= 0) {
    header('Location: /rice-business/frontend/inventory.php?error=lot');
    exit;
}

try {
    $pdo->beginTransaction();

    $result = writeOffStockLot($pdo, $lotId);

    $unit = $result['unit'];
    $qty = $result['quantity'];
    $qtyFormatted = $unit === 'pc'
        ? (string) ((int) round($qty))
        : number_format($qty, 2);

    $movement = $pdo->prepare(
        'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $movement->execute([
        $result['product_id'],
        'ADJUSTMENT',
        $qty,
        'WRITEOFF-' . $lotId . '-' . date('YmdHis'),
        'Write-off leftover (empty sack / waste) (-' . $qtyFormatted . ' ' . $unit . ', batch #' . $lotId . ')',
    ]);

    $pdo->commit();
    header('Location: /rice-business/frontend/inventory.php?success=writeoff');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = match ($e->getMessage()) {
        'stock', 'lot_stock' => 'stock',
        'lot_empty' => 'lot',
        'lot_missing', 'lot' => 'lot',
        default => 'save',
    };
    header('Location: /rice-business/frontend/inventory.php?error=' . $code);
}

exit;
