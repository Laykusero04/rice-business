<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/products.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    header('Location: /rice-business/frontend/products.php?error=category_invalid');
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, name, product_type FROM product_categories WHERE id = ?'
    );
    $stmt->execute([$id]);
    $category = $stmt->fetch();

    if (!$category) {
        header('Location: /rice-business/frontend/products.php?error=category_missing');
        exit;
    }

    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM products WHERE category = ? AND product_type = ?'
    );
    $check->execute([$category['name'], $category['product_type']]);
    $inUse = (int) $check->fetchColumn();

    if ($inUse > 0) {
        header('Location: /rice-business/frontend/products.php?error=category_in_use');
        exit;
    }

    $delete = $pdo->prepare('DELETE FROM product_categories WHERE id = ?');
    $delete->execute([$id]);
    header('Location: /rice-business/frontend/products.php?success=category_deleted');
} catch (PDOException $e) {
    header('Location: /rice-business/frontend/products.php?error=category_delete');
}

exit;
