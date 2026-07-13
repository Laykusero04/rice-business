<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/suppliers.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$contact = trim($_POST['contact'] ?? '');
$address = trim($_POST['address'] ?? '');

if ($name === '') {
    header('Location: /rice-business/frontend/suppliers.php?error=required');
    exit;
}

$contact = $contact !== '' ? $contact : null;
$address = $address !== '' ? $address : null;

try {
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE suppliers
             SET name = ?, contact = ?, address = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, $contact, $address, $id]);
        header('Location: /rice-business/frontend/suppliers.php?success=updated');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (name, contact, address)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, $contact, $address]);
        header('Location: /rice-business/frontend/suppliers.php?success=created');
    }
} catch (PDOException $e) {
    header('Location: /rice-business/frontend/suppliers.php?error=save');
}

exit;
