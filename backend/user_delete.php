<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/users.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$currentId = (int) ($_SESSION['user_id'] ?? 0);

if ($id <= 0) {
    header('Location: /rice-business/frontend/users.php?error=invalid');
    exit;
}

if ($id === $currentId) {
    header('Location: /rice-business/frontend/users.php?error=self');
    exit;
}

try {
    $adminCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'admin'"
    )->fetchColumn();

    $target = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $target->execute([$id]);
    $user = $target->fetch();

    if (!$user) {
        header('Location: /rice-business/frontend/users.php?error=invalid');
        exit;
    }

    if ($user['role'] === 'admin' && $adminCount <= 1) {
        header('Location: /rice-business/frontend/users.php?error=lastadmin');
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    header('Location: /rice-business/frontend/users.php?success=deleted');
} catch (PDOException $e) {
    header('Location: /rice-business/frontend/users.php?error=delete');
}

exit;
