<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

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
$sacks = (float) ($_POST['sacks'] ?? 0);
$sackPrice = (float) ($_POST['sack_price'] ?? 0);

$stockDirect = (float) ($_POST['stock'] ?? 0);
$buyingPriceDirect = (float) ($_POST['buying_price'] ?? 0);

$sellingPrice = (float) ($_POST['selling_price'] ?? 0);
$minSacks = (float) ($_POST['min_sacks'] ?? 0);
$minStockDirect = (float) ($_POST['minimum_stock'] ?? 0);
$status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

$typeParam = $productType === 'GROCERY' ? 'GROCERY' : 'RICE';
$listUrl = '/rice-business/frontend/products.php?type=' . $typeParam;

if ($name === '' || $category === '') {
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
        header('Location: ' . $listUrl . '&error=category_invalid');
        exit;
    }
} catch (PDOException $e) {
    // Table may not exist yet on older installs; allow save with free-text category.
}

if ($productType === 'RICE') {
    // Rice is managed by sack, stored in kg in DB
    $unit = 'kg';

    if ($kgPerSack <= 0 || $sacks < 0 || $sackPrice < 0 || $minSacks < 0) {
        header('Location: ' . $listUrl . '&error=invalid');
        exit;
    }

    $stock = round($sacks * $kgPerSack, 2);
    $buyingPrice = round($sackPrice / $kgPerSack, 2);
    $minimumStock = round($minSacks * $kgPerSack, 2);
} else {
    // Grocery items are managed directly by unit
    if ($stockDirect < 0 || $buyingPriceDirect < 0 || $minStockDirect < 0) {
        header('Location: ' . $listUrl . '&error=invalid');
        exit;
    }

    if ($unit === 'pc') {
        $isWholeStock = abs($stockDirect - round($stockDirect)) < 0.0001;
        $isWholeMin = abs($minStockDirect - round($minStockDirect)) < 0.0001;
        if (!$isWholeStock || !$isWholeMin) {
            header('Location: ' . $listUrl . '&error=invalid');
            exit;
        }
    }

    $kgPerSack = $kgPerSack > 0 ? $kgPerSack : 25;
    $stock = round($stockDirect, 2);
    $buyingPrice = round($buyingPriceDirect, 2);
    $minimumStock = round($minStockDirect, 2);
}

try {
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE products
             SET name = ?, product_type = ?, category = ?, unit = ?, buying_price = ?, selling_price = ?,
                 kg_per_sack = ?, stock = ?, minimum_stock = ?, status = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $productType,
            $category,
            $unit,
            $buyingPrice,
            $sellingPrice,
            $kgPerSack,
            $stock,
            $minimumStock,
            $status,
            $id,
        ]);
        header('Location: ' . $listUrl . '&success=updated');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO products
             (name, product_type, category, unit, buying_price, selling_price, kg_per_sack, stock, minimum_stock, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $productType,
            $category,
            $unit,
            $buyingPrice,
            $sellingPrice,
            $kgPerSack,
            $stock,
            $minimumStock,
            $status,
        ]);
        header('Location: ' . $listUrl . '&success=created');
    }
} catch (PDOException $e) {
    header('Location: ' . $listUrl . '&error=save');
}

exit;
