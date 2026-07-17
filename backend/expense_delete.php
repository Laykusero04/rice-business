<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/expenses.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    header('Location: /rice-business/frontend/expenses.php?error=invalid');
    exit;
}

try {
    $linkedStmt = $pdo->prepare('SELECT purchase_id FROM expenses WHERE id = ?');
    $linkedStmt->execute([$id]);
    $linked = $linkedStmt->fetch();
    if ($linked && !empty($linked['purchase_id'])) {
        header('Location: /rice-business/frontend/expenses.php?error=linked');
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM expenses WHERE id = ?');
    $stmt->execute([$id]);
    header('Location: /rice-business/frontend/expenses.php?success=deleted');
} catch (PDOException $e) {
    header('Location: /rice-business/frontend/expenses.php?error=delete');
}

exit;
