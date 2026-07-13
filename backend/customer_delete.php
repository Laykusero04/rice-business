<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/customers.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    header('Location: /rice-business/frontend/customers.php?error=invalid');
    exit;
}

try {
    $stmt = $pdo->prepare('DELETE FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    header('Location: /rice-business/frontend/customers.php?success=deleted');
} catch (PDOException $e) {
    header('Location: /rice-business/frontend/customers.php?error=delete');
}

exit;
