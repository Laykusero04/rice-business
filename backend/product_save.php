<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_lots.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/products.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$category = trim($_POST['category'] ?? '');
$productType = strtoupper(trim($_POST['product_type'] ?? 'RICE'));
$unit = trim($_POST['unit'] ?? 'kg');

$kgPerSack = (float) ($_POST['kg_per_sack'] ?? 25);
$sellingPrice = (float) ($_POST['selling_price'] ?? 0);
$sellingPriceSack = (float) ($_POST['selling_price_sack'] ?? 0);
$minSacks = isset($_POST['min_sacks']) ? (float) $_POST['min_sacks'] : 0;
$minStockDirect = (float) ($_POST['minimum_stock'] ?? 0);
$status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

$openingSacks = (float) ($_POST['opening_sacks'] ?? 0);
$sourcePurchaseId = (int) ($_POST['source_purchase_id'] ?? 0);
$costAmount = isset($_POST['cost_amount']) ? (float) $_POST['cost_amount'] : 0.0;
$batchLabel = trim((string) ($_POST['batch_label'] ?? ''));

$typeParam = $productType === 'GROCERY' ? 'GROCERY' : 'RICE';
$listUrl = '/rice-business/frontend/products.php?type=' . $typeParam;

if ($name === '') {
    header('Location: ' . $listUrl . '&error=required');
    exit;
}

$allowedTypes = ['RICE', 'GROCERY'];
if (!in_array($productType, $allowedTypes, true)) {
    $productType = 'RICE';
}

$allowedUnits = ['kg', 'pc', 'L', 'ml'];
if (!in_array($unit, $allowedUnits, true)) {
    $unit = 'kg';
}

if ($productType === 'RICE' && $category === '') {
    $category = 'Rice';
}

if ($productType === 'GROCERY' && $category === '') {
    header('Location: ' . $listUrl . '&error=required');
    exit;
}

if ($sellingPrice < 0) {
    header('Location: ' . $listUrl . '&error=invalid');
    exit;
}

try {
    $categoryCheck = $pdo->prepare(
        'SELECT id FROM product_categories WHERE name = ? AND product_type = ? LIMIT 1'
    );
    $categoryCheck->execute([$category, $productType]);
    if (!$categoryCheck->fetch()) {
        // Auto-create default rice category if missing; grocery still must exist.
        if ($productType === 'RICE') {
            try {
                $pdo->prepare(
                    'INSERT INTO product_categories (name, product_type) VALUES (?, ?)'
                )->execute([$category, 'RICE']);
            } catch (PDOException $e) {
                // ignore duplicate / missing table
            }
        } else {
            header('Location: ' . $listUrl . '&error=category_invalid');
            exit;
        }
    }
} catch (PDOException $e) {
    // Table may not exist yet on older installs; allow save with free-text category.
}

$sellingPriceSackDb = null;

if ($productType === 'RICE') {
    $unit = 'kg';

    if ($kgPerSack <= 0 || $sellingPriceSack < 0) {
        header('Location: ' . $listUrl . '&error=invalid');
        exit;
    }

    // Per kg is the main sell price (whole pesos); sack price is optional.
    if ($sellingPrice <= 0 && $sellingPriceSack > 0) {
        $sellingPrice = (float) (int) round($sellingPriceSack / $kgPerSack);
    } else {
        $sellingPrice = (float) (int) round($sellingPrice);
    }

    if ($sellingPrice <= 0) {
        header('Location: ' . $listUrl . '&error=invalid');
        exit;
    }

    // Low-stock alert unused for now — keep 0.
    $minimumStock = 0.0;
    $sellingPriceSackDb = $sellingPriceSack > 0 ? round($sellingPriceSack, 2) : null;

    // Opening stock in sacks — purchase link optional (old stock).
    // No extra validation when purchase is empty.
} else {
    if ($minStockDirect < 0) {
        header('Location: ' . $listUrl . '&error=invalid');
        exit;
    }

    if ($unit === 'pc') {
        $isWholeMin = abs($minStockDirect - round($minStockDirect)) < 0.0001;
        if (!$isWholeMin) {
            header('Location: ' . $listUrl . '&error=invalid');
            exit;
        }
    }

    $kgPerSack = $kgPerSack > 0 ? $kgPerSack : 25;
    $minimumStock = round($minStockDirect, 2);
    $openingSacks = 0;
}

if ($productType === 'RICE' && $openingSacks > 0) {
    ensureSourcePurchaseColumn($pdo);
}

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE products
             SET name = ?, product_type = ?, category = ?, unit = ?, selling_price = ?,
                 selling_price_sack = ?, kg_per_sack = ?, minimum_stock = ?, status = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $productType,
            $category,
            $unit,
            $sellingPrice,
            $sellingPriceSackDb,
            $kgPerSack,
            $minimumStock,
            $status,
            $id,
        ]);
        $pdo->commit();
        header('Location: ' . $listUrl . '&success=updated');
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO products
         (name, product_type, category, unit, buying_price, selling_price, selling_price_sack,
          kg_per_sack, stock, minimum_stock, status)
         VALUES (?, ?, ?, ?, 0, ?, ?, ?, 0, ?, ?)'
    );
    $stmt->execute([
        $name,
        $productType,
        $category,
        $unit,
        $sellingPrice,
        $sellingPriceSackDb,
        $kgPerSack,
        $minimumStock,
        $status,
    ]);
    $newProductId = (int) $pdo->lastInsertId();

    if ($productType === 'RICE' && $openingSacks > 0 && $newProductId > 0) {
        $quantityKg = round($openingSacks * $kgPerSack, 2);
        $totalCost = 0.0;
        $buyingPerKg = 0.0;
        $label = 'Old stock';
        $purchaseDate = date('Y-m-d');
        $linkedPurchaseId = null;

        if ($sourcePurchaseId > 0) {
            $purchaseStmt = $pdo->prepare(
                'SELECT id, total, purchase_date, batch_label,
                        COALESCE((
                          SELECT SUM(sl.total_cost)
                          FROM stock_lots sl
                          WHERE sl.source_purchase_id = purchases.id
                            AND sl.total_cost IS NOT NULL
                        ), 0) AS allocated
                 FROM purchases
                 WHERE id = ?
                 FOR UPDATE'
            );
            $purchaseStmt->execute([$sourcePurchaseId]);
            $purchase = $purchaseStmt->fetch();
            if (!$purchase) {
                throw new RuntimeException('purchase');
            }

            $purchaseTotal = round((float) $purchase['total'], 2);
            $allocated = round((float) $purchase['allocated'], 2);
            $remainingCost = round(max(0, $purchaseTotal - $allocated), 2);

            if ($costAmount > 0) {
                $totalCost = round($costAmount, 2);
            } elseif ($remainingCost > 0) {
                $totalCost = $remainingCost;
            } else {
                $totalCost = $purchaseTotal;
            }

            $buyingPerKg = $quantityKg > 0 ? round($totalCost / $quantityKg, 4) : 0.0;
            $purchaseBatchLabel = trim((string) ($purchase['batch_label'] ?? ''));
            if ($batchLabel !== '') {
                $label = $batchLabel;
            } elseif ($purchaseBatchLabel !== '') {
                $label = $purchaseBatchLabel;
            } else {
                $label = allocateNextBatchLabel($pdo);
            }
            $purchaseDate = $purchase['purchase_date'] ?? $purchaseDate;
            $linkedPurchaseId = $sourcePurchaseId;
        } elseif ($batchLabel !== '') {
            $label = $batchLabel;
        }

        createSellBatch(
            $pdo,
            $newProductId,
            $buyingPerKg,
            $label,
            $purchaseDate,
            $quantityKg,
            $totalCost,
            $linkedPurchaseId
        );

        if (preg_match('/^Batch-(\d+)$/', $label, $m)) {
            $usedNo = (int) $m[1];
            $pdo->prepare(
                'UPDATE batch_counters SET next_batch_no = GREATEST(next_batch_no, ?) WHERE id = 1'
            )->execute([$usedNo + 1]);
        }
    }

    $pdo->commit();
    header('Location: ' . $listUrl . '&success=created');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: ' . $listUrl . '&error=save');
}

exit;
