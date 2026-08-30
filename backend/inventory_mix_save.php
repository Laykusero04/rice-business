<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/mix.php');
    exit;
}

$outputProductId = (int) ($_POST['output_product_id'] ?? 0);
$batchLabel = trim((string) ($_POST['batch_label'] ?? ''));
$lossPercent = (float) ($_POST['loss_percent'] ?? 0);
$sourceLotIds = $_POST['source_lot_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];

if ($outputProductId <= 0) {
    header('Location: /rice-business/frontend/mix.php?error=product');
    exit;
}

if (!is_array($sourceLotIds) || !is_array($quantities)) {
    header('Location: /rice-business/frontend/mix.php?error=inputs');
    exit;
}

$inputs = [];
for ($i = 0; $i < count($sourceLotIds); $i++) {
    $inputs[] = [
        'lot_id' => (int) ($sourceLotIds[$i] ?? 0),
        'quantity' => (float) ($quantities[$i] ?? 0),
    ];
}

try {
    $pdo->beginTransaction();

    $result = mixStockLots(
        $pdo,
        $outputProductId,
        $inputs,
        $batchLabel !== '' ? $batchLabel : 'Mix',
        $lossPercent
    );

    $qty = (float) $result['quantity'];
    $mixLotId = (int) $result['mix_lot_id'];
    $ts = date('YmdHis');

    $move = $pdo->prepare(
        'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($result['components'] as $comp) {
        $move->execute([
            (int) $comp['source_product_id'],
            'ADJUSTMENT',
            (float) $comp['quantity_used'],
            'MIX-OUT-' . $mixLotId . '-' . $ts,
            'Mixed into batch #' . $mixLotId . ' (-' . number_format((float) $comp['quantity_used'], 2) . ')',
        ]);
    }

    $move->execute([
        $outputProductId,
        'IN',
        $qty,
        'MIX-IN-' . $mixLotId . '-' . $ts,
        'Blend created batch #' . $mixLotId
            . ' (' . number_format($qty, 2) . ' @ cost ₱' . number_format((float) $result['total_cost'], 2) . ')',
    ]);

    $pdo->commit();
    header('Location: /rice-business/frontend/mix.php?success=mixed&lot_id=' . $mixLotId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = match ($e->getMessage()) {
        'product' => 'product',
        'inputs', 'lot_qty' => 'inputs',
        'loss' => 'loss',
        'lot_stock', 'stock' => 'stock',
        'lot_missing', 'lot_closed' => 'lot',
        default => 'save',
    };
    header('Location: /rice-business/frontend/mix.php?error=' . $code);
}

exit;
