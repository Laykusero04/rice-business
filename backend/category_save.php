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
$productType = strtoupper(trim($_POST['product_type'] ?? 'RICE'));

if ($name === '') {
    header('Location: /rice-business/frontend/products.php?error=category_required');
    exit;
}

if (!in_array($productType, ['RICE', 'GROCERY'], true)) {
    $productType = 'RICE';
}

if (strlen($name) > 50) {
    header('Location: /rice-business/frontend/products.php?error=category_invalid');
    exit;
}

try {
    if ($id > 0) {
        $existing = $pdo->prepare(
            'SELECT id, name, product_type FROM product_categories WHERE id = ?'
        );
        $existing->execute([$id]);
        $row = $existing->fetch();

        if (!$row) {
            header('Location: /rice-business/frontend/products.php?error=category_missing');
            exit;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'UPDATE product_categories SET name = ?, product_type = ? WHERE id = ?'
        );
        $stmt->execute([$name, $productType, $id]);

        $pdo->prepare(
            'UPDATE products SET category = ? WHERE category = ? AND product_type = ?'
        )->execute([$name, $row['name'], $row['product_type']]);

        $pdo->commit();
        header('Location: /rice-business/frontend/products.php?success=category_updated');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO product_categories (name, product_type) VALUES (?, ?)'
        );
        $stmt->execute([$name, $productType]);
        header('Location: /rice-business/frontend/products.php?success=category_created');
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $code = str_contains($e->getMessage(), 'Duplicate') ? 'category_duplicate' : 'category_save';
    header('Location: /rice-business/frontend/products.php?error=' . $code);
}

exit;
