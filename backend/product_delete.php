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
    header('Location: /rice-business/frontend/products.php?error=invalid');
    exit;
}

try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM stock_lots WHERE product_id = ?')->execute([$id]);
    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $pdo->commit();
    header('Location: /rice-business/frontend/products.php?success=deleted');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: /rice-business/frontend/products.php?error=delete');
}

exit;
