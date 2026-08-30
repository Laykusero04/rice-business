<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/gcash.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: /rice-business/frontend/gcash.php?error=invalid');
    exit;
}

try {
    $stmt = $pdo->prepare('DELETE FROM gcash_cashins WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        header('Location: /rice-business/frontend/gcash.php?error=notfound');
        exit;
    }
    header('Location: /rice-business/frontend/gcash.php?success=deleted');
} catch (Throwable $e) {
    header('Location: /rice-business/frontend/gcash.php?error=delete');
}

exit;
