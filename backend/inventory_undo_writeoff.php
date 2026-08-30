<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/inventory.php');
    exit;
}

$movementId = (int) ($_POST['movement_id'] ?? 0);

if ($movementId <= 0) {
    header('Location: /rice-business/frontend/inventory.php?error=invalid');
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT * FROM stock_movements WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$movementId]);
    $move = $stmt->fetch();

    if (!$move || ($move['type'] ?? '') !== 'ADJUSTMENT') {
        throw new RuntimeException('missing');
    }

    $reference = (string) ($move['reference'] ?? '');
    $notes = (string) ($move['notes'] ?? '');
    $qty = round((float) $move['quantity'], 2);
    $productId = (int) $move['product_id'];

    if ($qty <= 0) {
        throw new RuntimeException('invalid');
    }

    $isWriteoffLike = str_starts_with($reference, 'WRITEOFF') || str_starts_with($reference, 'CLOSE');
    if (!$isWriteoffLike) {
        throw new RuntimeException('not_undoable');
    }

    // Prefer WRITEOFF|CLOSE-{lotId}-{timestamp} ; fall back to "batch #N" in notes
    $lotId = 0;
    if (preg_match('/^(?:WRITEOFF|CLOSE)-(\d+)-\d{8,}/', $reference, $m)) {
        $lotId = (int) $m[1];
    } elseif (preg_match('/batch\s*#\s*(\d+)/i', $notes, $m)) {
        $lotId = (int) $m[1];
    }

    if ($lotId <= 0) {
        throw new RuntimeException('not_undoable');
    }

    $lotStmt = $pdo->prepare('SELECT * FROM stock_lots WHERE id = ? FOR UPDATE');
    $lotStmt->execute([$lotId]);
    $lot = $lotStmt->fetch();

    if (!$lot || (int) $lot['product_id'] !== $productId) {
        throw new RuntimeException('lot_missing');
    }

    restoreStockLot($pdo, $lotId, $qty);

    $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')
        ->execute([$qty, $productId]);

    $pdo->prepare(
        'DELETE FROM stock_lot_writeoffs WHERE movement_reference = ?'
    )->execute([$reference]);

    $pdo->prepare('DELETE FROM stock_movements WHERE id = ?')->execute([$movementId]);

    $pdo->commit();
    header('Location: /rice-business/frontend/inventory.php?success=undo_writeoff');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = match ($e->getMessage()) {
        'not_undoable' => 'not_undoable',
        'lot_missing', 'lot' => 'lot',
        'missing' => 'invalid',
        default => 'save',
    };
    header('Location: /rice-business/frontend/inventory.php?error=' . $code);
}

exit;
