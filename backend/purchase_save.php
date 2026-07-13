<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/purchase_new.php');
    exit;
}

$supplierId = (int) ($_POST['supplier_id'] ?? 0);
$purchaseDate = trim($_POST['purchase_date'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$productIds = $_POST['product_id'] ?? [];
$sacksList = $_POST['sacks'] ?? [];
$sackPrices = $_POST['sack_price'] ?? [];

if ($supplierId <= 0 || $purchaseDate === '') {
    header('Location: /rice-business/frontend/purchase_new.php?error=required');
    exit;
}

if (!is_array($productIds) || count($productIds) === 0) {
    header('Location: /rice-business/frontend/purchase_new.php?error=items');
    exit;
}

$user = currentUser();
$notes = $notes !== '' ? $notes : null;

try {
    $pdo->beginTransaction();

    $checkSupplier = $pdo->prepare('SELECT id FROM suppliers WHERE id = ?');
    $checkSupplier->execute([$supplierId]);
    if (!$checkSupplier->fetch()) {
        throw new RuntimeException('supplier');
    }

    $checkProduct = $pdo->prepare(
        'SELECT id, kg_per_sack FROM products WHERE id = ? AND status = ? FOR UPDATE'
    );

    $items = [];
    $total = 0.0;

    for ($i = 0; $i < count($productIds); $i++) {
        $productId = (int) ($productIds[$i] ?? 0);
        $sacks = (float) ($sacksList[$i] ?? 0);
        $sackPrice = (float) ($sackPrices[$i] ?? 0);

        if ($productId <= 0 || $sacks <= 0 || $sackPrice < 0) {
            continue;
        }

        $checkProduct->execute([$productId, 'active']);
        $product = $checkProduct->fetch();
        if (!$product) {
            throw new RuntimeException('product');
        }

        $kgPerSack = (float) ($product['kg_per_sack'] ?? 25);
        if ($kgPerSack <= 0) {
            $kgPerSack = 25;
        }

        $quantityKg = round($sacks * $kgPerSack, 2);
        $buyingPricePerKg = round($sackPrice / $kgPerSack, 2);
        $subtotal = round($sacks * $sackPrice, 2);

        $items[] = [
            'product_id' => $productId,
            'quantity' => $quantityKg,
            'buying_price' => $buyingPricePerKg,
            'subtotal' => $subtotal,
            'sacks' => $sacks,
            'sack_price' => $sackPrice,
        ];
        $total += $subtotal;
    }

    if (count($items) === 0) {
        throw new RuntimeException('items');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO purchases (supplier_id, total, purchase_date, notes, user_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $supplierId,
        round($total, 2),
        $purchaseDate,
        $notes,
        $user['id'] ?? null,
    ]);
    $purchaseId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO purchase_items (purchase_id, product_id, quantity, buying_price, subtotal)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stockStmt = $pdo->prepare(
        'UPDATE products
         SET stock = stock + ?, buying_price = ?
         WHERE id = ?'
    );
    $movementStmt = $pdo->prepare(
        'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($items as $item) {
        $itemStmt->execute([
            $purchaseId,
            $item['product_id'],
            $item['quantity'],
            $item['buying_price'],
            $item['subtotal'],
        ]);

        $stockStmt->execute([
            $item['quantity'],
            $item['buying_price'],
            $item['product_id'],
        ]);

        $movementStmt->execute([
            $item['product_id'],
            'IN',
            $item['quantity'],
            'PURCHASE-' . $purchaseId,
            'Stock in from purchase #' . $purchaseId
                . ' (' . number_format($item['sacks'], 2) . ' sack @ ₱'
                . number_format($item['sack_price'], 2) . ')',
        ]);
    }

    $pdo->commit();
    header('Location: /rice-business/frontend/purchase_view.php?id=' . $purchaseId . '&success=created');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $code = $e->getMessage() === 'items' ? 'items' : 'save';
    header('Location: /rice-business/frontend/purchase_new.php?error=' . $code);
}

exit;
