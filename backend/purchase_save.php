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
    ? '/rice-business/frontend/purchase_new.php?id=' . $purchaseId
    : '/rice-business/frontend/purchase_new.php';

$supplierId = (int) ($_POST['supplier_id'] ?? 0);
$purchaseDate = trim($_POST['purchase_date'] ?? '');
$paymentSource = trim($_POST['payment_source'] ?? 'business');
$notes = trim($_POST['notes'] ?? '');
$itemNames = $_POST['product_name'] ?? [];
$qtyList = $_POST['quantity'] ?? [];
$unitPrices = $_POST['unit_price'] ?? [];
$kgPerSackList = $_POST['kg_per_sack'] ?? [];
$purchaseBatch = trim((string) ($_POST['purchase_batch'] ?? ''));
$forProductId = (int) ($_POST['for_product_id'] ?? 0);

if ($supplierId <= 0 || $purchaseDate === '') {
    header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=required');
    exit;
}

$allowedPaymentSources = ['business', 'personal'];
if (!in_array($paymentSource, $allowedPaymentSources, true)) {
    $paymentSource = 'business';
}

if (!is_array($itemNames) || count($itemNames) === 0) {
    header('Location: ' . $redirectNew . ($isEdit ? '&' : '?') . 'error=items');
    exit;
}

$user = currentUser();
$notes = $notes !== '' ? $notes : null;

/**
 * Undo stock that older purchases may have pushed into products (legacy only).
 */
function reverseLegacyPurchaseStock(PDO $pdo, int $purchaseId): void
{
    $oldItemsStmt = $pdo->prepare(
        'SELECT pi.id, pi.product_id, pi.quantity,
                COALESCE(pi.item_name, pr.name, \'item\') AS name
         FROM purchase_items pi
         LEFT JOIN products pr ON pr.id = pi.product_id
         WHERE pi.purchase_id = ?'
    );
    $oldItemsStmt->execute([$purchaseId]);
    $oldItems = $oldItemsStmt->fetchAll();

    reversePurchaseLots($pdo, $oldItems);

    $reverseStock = $pdo->prepare(
        'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
    );
    foreach ($oldItems as $oldItem) {
        $productId = (int) ($oldItem['product_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }
        $qty = (float) $oldItem['quantity'];
        if ($qty <= 0) {
            continue;
        }
        $reverseStock->execute([$qty, $productId, $qty]);
        if ($reverseStock->rowCount() === 0) {
            throw new RuntimeException('stock:' . $oldItem['name']);
        }
    }

    $pdo->prepare('DELETE FROM stock_movements WHERE reference = ?')
        ->execute(['PURCHASE-' . $purchaseId]);
}

try {
    ensurePurchaseForProductColumn($pdo);
    $pdo->beginTransaction();

    $checkSupplier = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ?');
    $checkSupplier->execute([$supplierId]);
    $supplier = $checkSupplier->fetch();
    if (!$supplier) {
        throw new RuntimeException('supplier');
    }

    if ($isEdit) {
        $existingStmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ? FOR UPDATE');
        $existingStmt->execute([$purchaseId]);
        if (!$existingStmt->fetch()) {
            throw new RuntimeException('missing');
        }

        reverseLegacyPurchaseStock($pdo, $purchaseId);
        $pdo->prepare('DELETE FROM purchase_items WHERE purchase_id = ?')
            ->execute([$purchaseId]);
    }

    $items = [];
    $total = 0.0;

    for ($i = 0; $i < count($itemNames); $i++) {
        $itemName = trim((string) ($itemNames[$i] ?? ''));
        $qty = (float) ($qtyList[$i] ?? 0);
        $unitPrice = (float) ($unitPrices[$i] ?? 0);
        $kgPerSack = (float) ($kgPerSackList[$i] ?? 25);

        if ($itemName === '' || $qty <= 0 || $unitPrice < 0) {
            continue;
        }

        if ($kgPerSack <= 0) {
            $kgPerSack = 25;
        }
        $kgPerSack = round($kgPerSack, 2);

        $quantityKg = round($qty * $kgPerSack, 2);
        $buyingPricePerKg = round($unitPrice / $kgPerSack, 2);
        $subtotal = round($qty * $unitPrice, 2);

        $items[] = [
            'item_name' => $itemName,
            'kg_per_sack' => $kgPerSack,
            'quantity' => $quantityKg,
            'buying_price' => $buyingPricePerKg,
            'subtotal' => $subtotal,
        ];
        $total += $subtotal;
    }

    if (count($items) === 0) {
        throw new RuntimeException('items');
    }

    // One batch label for the whole buy — cost record; link sell stock later via this purchase.
    if ($purchaseBatch !== '') {
        $batchLabel = $purchaseBatch;
        if (preg_match('/^Batch-(\d+)$/', $batchLabel, $m)) {
            $usedNo = (int) $m[1];
            try {
                $pdo->prepare(
                    'UPDATE batch_counters SET next_batch_no = GREATEST(next_batch_no, ?) WHERE id = 1'
                )->execute([$usedNo + 1]);
            } catch (PDOException $e) {
                // counter table may not exist yet
            }
        }
    } else {
        $batchLabel = allocateNextBatchLabel($pdo);
    }

    if ($forProductId > 0) {
        $prodCheck = $pdo->prepare(
            "SELECT id FROM products WHERE id = ? AND status = 'active' LIMIT 1"
        );
        $prodCheck->execute([$forProductId]);
        if (!$prodCheck->fetch()) {
            $forProductId = 0;
        }
    } else {
        $forProductId = null;
    }

    $total = round($total, 2);

    if ($isEdit) {
        $stmt = $pdo->prepare(
            'UPDATE purchases
             SET supplier_id = ?, total = ?, purchase_date = ?, payment_source = ?, notes = ?,
                 batch_label = ?, for_product_id = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $supplierId,
            $total,
            $purchaseDate,
            $paymentSource,
            $notes,
            $batchLabel,
            $forProductId,
            $purchaseId,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO purchases
             (supplier_id, total, purchase_date, payment_source, notes, batch_label, for_product_id, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $total,
            $purchaseDate,
            $paymentSource,
            $notes,
            $batchLabel,
            $forProductId,
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
        'INSERT INTO purchase_items
         (purchase_id, product_id, item_name, quantity, buying_price, subtotal, kg_per_sack)
         VALUES (?, NULL, ?, ?, ?, ?, ?)'
    );

    foreach ($items as $item) {
        $itemStmt->execute([
            $purchaseId,
            $item['item_name'],
            $item['quantity'],
            $item['buying_price'],
            $item['subtotal'],
            $item['kg_per_sack'],
        ]);
    }

    $pdo->commit();
    $success = $isEdit ? 'updated' : 'created';
    header('Location: /rice-business/frontend/purchase_view.php?id=' . $purchaseId . '&success=' . $success);
    exit;
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
    exit;
}
