<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /rice-business/frontend/login.php');
        exit;
    }
}

function redirectIfLoggedIn(): void
{
    if (isLoggedIn()) {
        header('Location: /rice-business/frontend/dashboard.php');
        exit;
    }
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['name'] ?? '',
        'username' => $_SESSION['username'] ?? '',
        'role' => $_SESSION['role'] ?? '',
    ];
}

function requireAdmin(): void
{
    requireLogin();

    $user = currentUser();
    if (($user['role'] ?? '') !== 'admin') {
        header('Location: /rice-business/frontend/dashboard.php?error=forbidden');
        exit;
    }
}

function isAdmin(): bool
{
    $user = currentUser();
    return ($user['role'] ?? '') === 'admin';
}
