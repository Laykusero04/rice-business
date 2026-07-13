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
$kgPerSack = (float) ($_POST['kg_per_sack'] ?? 25);
$sacks = (float) ($_POST['sacks'] ?? 0);
$sackPrice = (float) ($_POST['sack_price'] ?? 0);
$sellingPrice = (float) ($_POST['selling_price'] ?? 0);
$minSacks = (float) ($_POST['min_sacks'] ?? 0);
$status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

if ($name === '' || $category === '') {
    header('Location: /rice-business/frontend/products.php?error=required');
    exit;
}

if ($kgPerSack <= 0 || $sacks < 0 || $sackPrice < 0 || $sellingPrice < 0 || $minSacks < 0) {
    header('Location: /rice-business/frontend/products.php?error=invalid');
    exit;
}

// Convert sack inputs into kg values stored in the database
$stock = round($sacks * $kgPerSack, 2);
$buyingPrice = round($sackPrice / $kgPerSack, 2);
$minimumStock = round($minSacks * $kgPerSack, 2);

try {
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE products
             SET name = ?, category = ?, buying_price = ?, selling_price = ?,
                 kg_per_sack = ?, stock = ?, minimum_stock = ?, status = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $category,
            $buyingPrice,
            $sellingPrice,
            $kgPerSack,
            $stock,
            $minimumStock,
            $status,
            $id,
        ]);
        header('Location: /rice-business/frontend/products.php?success=updated');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO products
             (name, category, buying_price, selling_price, kg_per_sack, stock, minimum_stock, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $category,
            $buyingPrice,
            $sellingPrice,
            $kgPerSack,
            $stock,
            $minimumStock,
            $status,
        ]);
        header('Location: /rice-business/frontend/products.php?success=created');
    }
} catch (PDOException $e) {
    header('Location: /rice-business/frontend/products.php?error=save');
}

exit;
