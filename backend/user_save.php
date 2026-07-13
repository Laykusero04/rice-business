<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/users.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$role = ($_POST['role'] ?? 'cashier') === 'admin' ? 'admin' : 'cashier';

if ($name === '' || $username === '') {
    header('Location: /rice-business/frontend/users.php?error=required');
    exit;
}

if ($id === 0 && $password === '') {
    header('Location: /rice-business/frontend/users.php?error=password');
    exit;
}

try {
    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
    $check->execute([$username, $id]);
    if ($check->fetch()) {
        header('Location: /rice-business/frontend/users.php?error=exists');
        exit;
    }

    if ($id > 0) {
        if ($password !== '') {
            $stmt = $pdo->prepare(
                'UPDATE users SET name = ?, username = ?, password = ?, role = ? WHERE id = ?'
            );
            $stmt->execute([
                $name,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $id,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE users SET name = ?, username = ?, role = ? WHERE id = ?'
            );
            $stmt->execute([$name, $username, $role, $id]);
        }

        if ((int) ($_SESSION['user_id'] ?? 0) === $id) {
            $_SESSION['name'] = $name;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $role;
        }

        header('Location: /rice-business/frontend/users.php?success=updated');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
        ]);
        header('Location: /rice-business/frontend/users.php?success=created');
    }
} catch (PDOException $e) {
    header('Location: /rice-business/frontend/users.php?error=save');
}

exit;
