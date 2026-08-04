<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/sale_new.php');
    exit;
}

$saleId = (int) ($_POST['id'] ?? 0);
$isEdit = $saleId > 0;

$redirectNew = $isEdit
    ? '/rice-business/frontend/sale_edit.php?id=' . $saleId
    : '/rice-business/frontend/sale_new.php';

$customerId = (int) ($_POST['customer_id'] ?? 0);
$walkinName = trim($_POST['walkin_name'] ?? '');
$saleDate = trim($_POST['sale_date'] ?? '');
$paymentMethod = trim($_POST['payment_method'] ?? 'cash');
$notes = trim($_POST['notes'] ?? '');
$productIds = $_POST['product_id'] ?? [];
$lotIds = $_POST['stock_lot_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$prices = $_POST['price'] ?? [];
$lineSubtotals = $_POST['line_subtotal'] ?? [];

$allowedPayments = ['cash', 'gcash', 'bank', 'credit'];
if (!in_array($paymentMethod, $allowedPayments, true)) {
    $paymentMethod = 'cash';
}

$isLend = $paymentMethod === 'credit';

if ($saleDate === '') {
    header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=required');
    exit;
}

if ($isLend && $customerId <= 0 && $walkinName === '') {
    header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=customer');
    exit;
}

if (!is_array($productIds) || count($productIds) === 0) {
    header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=items');
    exit;
}

$items = [];
$total = 0.0;

for ($i = 0; $i < count($productIds); $i++) {
    $productId = (int) ($productIds[$i] ?? 0);
    $lotId = (int) ($lotIds[$i] ?? 0);
    $quantity = (float) ($quantities[$i] ?? 0);
    $price = (float) ($prices[$i] ?? 0);
    $lineSubtotalRaw = trim((string) ($lineSubtotals[$i] ?? ''));

    if ($productId <= 0 || $lotId <= 0 || $quantity <= 0 || $price < 0) {
        continue;
    }

    if ($lineSubtotalRaw !== '' && is_numeric($lineSubtotalRaw)) {
        $subtotal = round((float) $lineSubtotalRaw, 2);
    } else {
        $subtotal = round($quantity * $price, 2);
    }
    $items[] = [
        'product_id' => $productId,
        'stock_lot_id' => $lotId,
        'quantity' => $quantity,
        'price' => $price,
        'subtotal' => $subtotal,
    ];
    $total += $subtotal;
}

if (count($items) === 0) {
    header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=items');
    exit;
}

$total = round($total, 2);

$user = currentUser();
$notes = $notes !== '' ? $notes : null;
$customerId = $customerId > 0 ? $customerId : null;

try {
    $pdo->beginTransaction();

    $existingSale = null;
    $oldAmountPaid = 0.0;

    if ($isEdit) {
        $existingStmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
        $existingStmt->execute([$saleId]);
        $existingSale = $existingStmt->fetch();

        if (!$existingSale) {
            throw new RuntimeException('missing');
        }

        $oldAmountPaid = (float) ($existingSale['amount_paid'] ?? 0);

        $oldItemsStmt = $pdo->prepare(
            'SELECT product_id, stock_lot_id, quantity FROM sale_items WHERE sale_id = ?'
        );
        $oldItemsStmt->execute([$saleId]);
        $oldItems = $oldItemsStmt->fetchAll();

        $restoreStock = $pdo->prepare(
            'UPDATE products SET stock = stock + ? WHERE id = ?'
        );
        foreach ($oldItems as $oldItem) {
            $restoreStock->execute([
                (float) $oldItem['quantity'],
                (int) $oldItem['product_id'],
            ]);

            $oldLotId = (int) ($oldItem['stock_lot_id'] ?? 0);
            if ($oldLotId > 0) {
                restoreStockLot($pdo, $oldLotId, (float) $oldItem['quantity']);
            }
        }

        $pdo->prepare('DELETE FROM stock_movements WHERE reference = ?')
            ->execute(['SALE-' . $saleId]);
        $pdo->prepare('DELETE FROM sale_items WHERE sale_id = ?')
            ->execute([$saleId]);
    }

    // Walk-in utang: create (or reuse) a customer from the borrower name
    if ($isLend && $customerId === null && $walkinName !== '') {
        $findCustomer = $pdo->prepare(
            'SELECT id FROM customers WHERE LOWER(name) = LOWER(?) LIMIT 1'
        );
        $findCustomer->execute([$walkinName]);
        $existing = $findCustomer->fetch();

        if ($existing) {
            $customerId = (int) $existing['id'];
        } else {
            $createCustomer = $pdo->prepare(
                'INSERT INTO customers (name, notes) VALUES (?, ?)'
            );
            $createCustomer->execute([$walkinName, 'Added from walk-in utang sale']);
            $customerId = (int) $pdo->lastInsertId();
        }
    }

    if ($customerId !== null) {
        $checkCustomer = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
        $checkCustomer->execute([$customerId]);
        if (!$checkCustomer->fetch()) {
            throw new RuntimeException('customer');
        }
    }

    if ($isLend && $customerId === null) {
        throw new RuntimeException('customer');
    }

    if ($isLend) {
        $amountPaid = min($oldAmountPaid, $total);
        if ($amountPaid <= 0) {
            $paymentStatus = 'unpaid';
        } elseif ($amountPaid + 0.001 >= $total) {
            $amountPaid = $total;
            $paymentStatus = 'paid';
        } else {
            $paymentStatus = 'partial';
        }
    } else {
        $amountPaid = $total;
        $paymentStatus = 'paid';
    }

    if ($isEdit) {
        // Keep collection notes from utang payments when editing header/items
        $existingNotes = trim((string) ($existingSale['notes'] ?? ''));
        $collectionLines = [];
        foreach (preg_split("/\r\n|\n|\r/", $existingNotes) as $line) {
            $line = trim($line);
            if ($line !== '' && str_starts_with($line, 'Collected ₱')) {
                $collectionLines[] = $line;
            }
        }

        $mergedNotes = $notes ?? '';
        if (count($collectionLines) > 0) {
            $mergedNotes = trim($mergedNotes . "\n" . implode("\n", $collectionLines));
        }
        $mergedNotes = $mergedNotes !== '' ? $mergedNotes : null;

        $updateStmt = $pdo->prepare(
            'UPDATE sales
             SET customer_id = ?, total = ?, amount_paid = ?, payment_status = ?,
                 payment_method = ?, sale_date = ?, notes = ?
             WHERE id = ?'
        );
        $updateStmt->execute([
            $customerId,
            $total,
            $amountPaid,
            $paymentStatus,
            $paymentMethod,
            $saleDate,
            $mergedNotes,
            $saleId,
        ]);
    } else {
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
    }

    $itemStmt = $pdo->prepare(
        'INSERT INTO sale_items (sale_id, product_id, stock_lot_id, quantity, price, cost_price, subtotal)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stockCheck = $pdo->prepare(
        'SELECT id, name, unit, stock FROM products WHERE id = ? AND status = ? FOR UPDATE'
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

        $unit = $product['unit'] ?? 'kg';
        if ($unit === 'pc') {
            $isWhole = abs(((float) $item['quantity']) - round((float) $item['quantity'])) < 0.0001;
            if (!$isWhole) {
                throw new RuntimeException('items');
            }
        }

        if ((float) $product['stock'] < $item['quantity']) {
            throw new RuntimeException('stock:' . $product['name']);
        }

        $lot = deductStockLot($pdo, (int) $item['stock_lot_id'], (float) $item['quantity']);

        if ((int) $lot['product_id'] !== (int) $item['product_id']) {
            throw new RuntimeException('lot_mismatch');
        }

        $costPrice = round((float) $lot['buying_price'], 2);

        $itemStmt->execute([
            $saleId,
            $item['product_id'],
            $item['stock_lot_id'],
            $item['quantity'],
            $item['price'],
            $costPrice,
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
        $movementNote .= ' (stack #' . $item['stock_lot_id'] . ')';

        $movementStmt->execute([
            $item['product_id'],
            'OUT',
            $item['quantity'],
            'SALE-' . $saleId,
            $movementNote,
        ]);
    }

    $pdo->commit();
    $success = $isEdit ? 'updated' : 'created';
    header('Location: /rice-business/frontend/sale_view.php?id=' . $saleId . '&success=' . $success);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = $e->getMessage();
    if (str_starts_with($message, 'stock:') || $message === 'lot_stock') {
        $productName = $message === 'lot_stock'
            ? ($_GET['product'] ?? 'selected stack')
            : substr($message, 6);
        if ($message === 'lot_stock') {
            $productName = 'selected stack';
        }
        header(
            'Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=stock&product='
            . urlencode($productName)
        );
        exit;
    }

    if ($message === 'customer') {
        header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=customer');
        exit;
    }

    if ($message === 'missing') {
        header('Location: /rice-business/frontend/sales.php?error=notfound');
        exit;
    }

    if ($message === 'lot_mismatch' || $message === 'lot_missing') {
        header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=lot');
        exit;
    }

    header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=save');
}

exit;
