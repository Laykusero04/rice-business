<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/purchase_new.php');
    exit;
}

$purchaseId = (int) ($_POST['id'] ?? 0);
$isEdit = $purchaseId > 0;

$redirectNew = $isEdit
    ? '/rice-business/frontend/purchase_edit.php?id=' . $purchaseId
    : '/rice-business/frontend/purchase_new.php';

$supplierId = (int) ($_POST['supplier_id'] ?? 0);
$purchaseDate = trim($_POST['purchase_date'] ?? '');
$paymentSource = trim($_POST['payment_source'] ?? 'business');
$notes = trim($_POST['notes'] ?? '');
$productIds = $_POST['product_id'] ?? [];
$qtyList = $_POST['quantity'] ?? [];
$unitPrices = $_POST['unit_price'] ?? [];
$batchLabels = $_POST['batch_label'] ?? [];

if ($supplierId <= 0 || $purchaseDate === '') {
    header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=required');
    exit;
}

$allowedPaymentSources = ['business', 'personal'];
if (!in_array($paymentSource, $allowedPaymentSources, true)) {
    $paymentSource = 'business';
}

if (!is_array($productIds) || count($productIds) === 0) {
    header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=items');
    exit;
}

$user = currentUser();
$notes = $notes !== '' ? $notes : null;

try {
    $pdo->beginTransaction();

    $checkSupplier = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ?');
    $checkSupplier->execute([$supplierId]);
    $supplier = $checkSupplier->fetch();
    if (!$supplier) {
        throw new RuntimeException('supplier');
    }

    $originalProductIds = [];
    if ($isEdit) {
        $existingStmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ? FOR UPDATE');
        $existingStmt->execute([$purchaseId]);
        if (!$existingStmt->fetch()) {
            throw new RuntimeException('missing');
        }

        $oldItemsStmt = $pdo->prepare(
            'SELECT pi.id, pi.product_id, pi.quantity, pr.name
             FROM purchase_items pi
             INNER JOIN products pr ON pr.id = pi.product_id
             WHERE pi.purchase_id = ?'
        );
        $oldItemsStmt->execute([$purchaseId]);
        $oldItems = $oldItemsStmt->fetchAll();
        $originalProductIds = array_map(static fn ($item) => (int) $item['product_id'], $oldItems);

        // Only allow edit if stacks from this purchase were not sold yet
        reversePurchaseLots($pdo, $oldItems);

        $reverseStock = $pdo->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        foreach ($oldItems as $oldItem) {
            $reverseStock->execute([
                (float) $oldItem['quantity'],
                (int) $oldItem['product_id'],
                (float) $oldItem['quantity'],
            ]);
            if ($reverseStock->rowCount() === 0) {
                throw new RuntimeException('stock:' . $oldItem['name']);
            }
        }

        $pdo->prepare('DELETE FROM stock_movements WHERE reference = ?')
            ->execute(['PURCHASE-' . $purchaseId]);
        $pdo->prepare('DELETE FROM purchase_items WHERE purchase_id = ?')
            ->execute([$purchaseId]);
    }

    $checkProduct = $pdo->prepare(
        'SELECT id, name, product_type, unit, kg_per_sack, status FROM products WHERE id = ? FOR UPDATE'
    );

    $items = [];
    $total = 0.0;

    for ($i = 0; $i < count($productIds); $i++) {
        $productId = (int) ($productIds[$i] ?? 0);
        $qty = (float) ($qtyList[$i] ?? 0);
        $unitPrice = (float) ($unitPrices[$i] ?? 0);
        $batchLabel = trim((string) ($batchLabels[$i] ?? ''));

        if ($productId <= 0 || $qty <= 0 || $unitPrice < 0) {
            continue;
        }

        $checkProduct->execute([$productId]);
        $product = $checkProduct->fetch();
        if (
            !$product
            || (
                ($product['status'] ?? 'active') !== 'active'
                && !($isEdit && in_array($productId, $originalProductIds, true))
            )
        ) {
            throw new RuntimeException('product');
        }

        $productType = $product['product_type'] ?? 'RICE';
        $unit = $product['unit'] ?? 'kg';
        $kgPerSack = (float) ($product['kg_per_sack'] ?? 25);
        if ($kgPerSack <= 0) {
            $kgPerSack = 25;
        }

        if ($productType === 'RICE') {
            $quantityStock = round($qty * $kgPerSack, 2);
            $buyingPriceStored = round($unitPrice / $kgPerSack, 2);
            $subtotal = round($qty * $unitPrice, 2);
        } else {
            if ($unit === 'pc') {
                $isWhole = abs($qty - round($qty)) < 0.0001;
                if (!$isWhole) {
                    throw new RuntimeException('items');
                }
            }
            $quantityStock = round($qty, 2);
            $buyingPriceStored = round($unitPrice, 2);
            $subtotal = round($qty * $unitPrice, 2);
        }

        $items[] = [
            'product_id' => $productId,
            'product_type' => $productType,
            'unit' => $unit,
            'qty_input' => $qty,
            'unit_price_input' => $unitPrice,
            'kg_per_sack' => $kgPerSack,
            'quantity' => $quantityStock,
            'buying_price' => $buyingPriceStored,
            'subtotal' => $subtotal,
            'batch_label' => $batchLabel !== '' ? $batchLabel : null,
        ];
        $total += $subtotal;
    }

    if (count($items) === 0) {
        throw new RuntimeException('items');
    }

    $total = round($total, 2);

    if ($isEdit) {
        $stmt = $pdo->prepare(
            'UPDATE purchases
             SET supplier_id = ?, total = ?, purchase_date = ?, payment_source = ?, notes = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $supplierId,
            $total,
            $purchaseDate,
            $paymentSource,
            $notes,
            $purchaseId,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO purchases (supplier_id, total, purchase_date, payment_source, notes, user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $total,
            $purchaseDate,
            $paymentSource,
            $notes,
            $user['id'] ?? null,
        ]);
        $purchaseId = (int) $pdo->lastInsertId();
    }

    $expenseNote = 'Personal cash for purchase #' . $purchaseId . ' (' . $supplier['name'] . ')';
    $linkedExpenseStmt = $pdo->prepare('SELECT id FROM expenses WHERE purchase_id = ? LIMIT 1');
    $linkedExpenseStmt->execute([$purchaseId]);
    $linkedExpense = $linkedExpenseStmt->fetch();

    if ($paymentSource === 'personal') {
        if ($linkedExpense) {
            $updateExpense = $pdo->prepare(
                'UPDATE expenses SET category = ?, amount = ?, expense_date = ?, notes = ? WHERE id = ?'
            );
            $updateExpense->execute([
                'Owner Investment',
                $total,
                $purchaseDate,
                $expenseNote,
                $linkedExpense['id'],
            ]);
        } else {
            $expenseStmt = $pdo->prepare(
                'INSERT INTO expenses (category, amount, expense_date, notes, user_id, purchase_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $expenseStmt->execute([
                'Owner Investment',
                $total,
                $purchaseDate,
                $expenseNote,
                $user['id'] ?? null,
                $purchaseId,
            ]);
        }
    } elseif ($linkedExpense) {
        $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$linkedExpense['id']]);
    }

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
        $movementNote = 'Stock in from purchase #' . $purchaseId;
        if (($item['product_type'] ?? 'RICE') === 'RICE') {
            $movementNote .= ' (' . number_format((float) $item['qty_input'], 2) . ' sack @ ₱'
                . number_format((float) $item['unit_price_input'], 2) . ')';
        } else {
            $unit = $item['unit'] ?? 'pc';
            $movementNote .= ' (' . number_format((float) $item['qty_input'], $unit === 'pc' ? 0 : 2) . ' ' . $unit
                . ' @ ₱' . number_format((float) $item['unit_price_input'], 2) . ' / ' . $unit . ')';
        }

        $itemStmt->execute([
            $purchaseId,
            $item['product_id'],
            $item['quantity'],
            $item['buying_price'],
            $item['subtotal'],
        ]);
        $purchaseItemId = (int) $pdo->lastInsertId();

        createStockLot(
            $pdo,
            (int) $item['product_id'],
            (float) $item['quantity'],
            (float) $item['buying_price'],
            $purchaseDate,
            $purchaseItemId,
            $item['batch_label'] ?? ('Purchase #' . $purchaseId)
        );

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
            $movementNote,
        ]);
    }

    $pdo->commit();
    $success = $isEdit ? 'updated' : 'created';
    header('Location: /rice-business/frontend/purchase_view.php?id=' . $purchaseId . '&success=' . $success);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = $e->getMessage();
    if (str_starts_with($message, 'stock:')) {
        $productName = substr($message, 6);
        header(
            'Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=stock&product='
            . urlencode($productName)
        );
        exit;
    }

    if ($message === 'missing') {
        header('Location: /rice-business/frontend/purchases.php?error=notfound');
        exit;
    }

    if ($message === 'lot_used') {
        header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=lot_used');
        exit;
    }

    $code = $message === 'items' ? 'items' : 'save';
    header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=' . $code);
}

exit;
