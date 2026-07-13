<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/sale_new.php');
    exit;
}

$customerId = (int) ($_POST['customer_id'] ?? 0);
$saleDate = trim($_POST['sale_date'] ?? '');
$paymentMethod = trim($_POST['payment_method'] ?? 'cash');
$notes = trim($_POST['notes'] ?? '');
$productIds = $_POST['product_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$prices = $_POST['price'] ?? [];

$allowedPayments = ['cash', 'gcash', 'bank', 'credit'];
if (!in_array($paymentMethod, $allowedPayments, true)) {
    $paymentMethod = 'cash';
}

$isLend = $paymentMethod === 'credit';

if ($saleDate === '') {
    header('Location: /rice-business/frontend/sale_new.php?error=required');
    exit;
}

if ($isLend && $customerId <= 0) {
    header('Location: /rice-business/frontend/sale_new.php?error=customer');
    exit;
}

if (!is_array($productIds) || count($productIds) === 0) {
    header('Location: /rice-business/frontend/sale_new.php?error=items');
    exit;
}

$items = [];
$total = 0.0;

for ($i = 0; $i < count($productIds); $i++) {
    $productId = (int) ($productIds[$i] ?? 0);
    $quantity = (float) ($quantities[$i] ?? 0);
    $price = (float) ($prices[$i] ?? 0);

    if ($productId <= 0 || $quantity <= 0 || $price < 0) {
        continue;
    }

    $subtotal = round($quantity * $price, 2);
    $items[] = [
        'product_id' => $productId,
        'quantity' => $quantity,
        'price' => $price,
        'subtotal' => $subtotal,
    ];
    $total += $subtotal;
}

if (count($items) === 0) {
    header('Location: /rice-business/frontend/sale_new.php?error=items');
    exit;
}

$total = round($total, 2);
$amountPaid = $isLend ? 0.0 : $total;
$paymentStatus = $isLend ? 'unpaid' : 'paid';

$user = currentUser();
$notes = $notes !== '' ? $notes : null;
$customerId = $customerId > 0 ? $customerId : null;

try {
    $pdo->beginTransaction();

    if ($customerId !== null) {
        $checkCustomer = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
        $checkCustomer->execute([$customerId]);
        if (!$checkCustomer->fetch()) {
            throw new RuntimeException('customer');
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO sales
         (customer_id, user_id, total, amount_paid, payment_status, payment_method, sale_date, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $customerId,
        $user['id'] ?? null,
        $total,
        $amountPaid,
        $paymentStatus,
        $paymentMethod,
        $saleDate,
        $notes,
    ]);
    $saleId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO sale_items (sale_id, product_id, quantity, price, subtotal)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stockCheck = $pdo->prepare(
        'SELECT id, name, stock FROM products WHERE id = ? AND status = ? FOR UPDATE'
    );
    $stockStmt = $pdo->prepare(
        'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
    );
    $movementStmt = $pdo->prepare(
        'INSERT INTO stock_movements (product_id, type, quantity, reference, notes)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($items as $item) {
        $stockCheck->execute([$item['product_id'], 'active']);
        $product = $stockCheck->fetch();

        if (!$product) {
            throw new RuntimeException('product');
        }

        if ((float) $product['stock'] < $item['quantity']) {
            throw new RuntimeException('stock:' . $product['name']);
        }

        $itemStmt->execute([
            $saleId,
            $item['product_id'],
            $item['quantity'],
            $item['price'],
            $item['subtotal'],
        ]);

        $stockStmt->execute([
            $item['quantity'],
            $item['product_id'],
            $item['quantity'],
        ]);

        if ($stockStmt->rowCount() === 0) {
            throw new RuntimeException('stock:' . $product['name']);
        }

        $movementNote = $isLend
            ? 'Lend (utang) stock out from sale #' . $saleId
            : 'Stock out from sale #' . $saleId;

        $movementStmt->execute([
            $item['product_id'],
            'OUT',
            $item['quantity'],
            'SALE-' . $saleId,
            $movementNote,
        ]);
    }

    $pdo->commit();
    header('Location: /rice-business/frontend/sale_view.php?id=' . $saleId . '&success=created');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = $e->getMessage();
    if (str_starts_with($message, 'stock:')) {
        $productName = substr($message, 6);
        header(
            'Location: /rice-business/frontend/sale_new.php?error=stock&product='
            . urlencode($productName)
        );
        exit;
    }

    header('Location: /rice-business/frontend/sale_new.php?error=save');
}

exit;
