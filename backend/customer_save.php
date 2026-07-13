<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/customers.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$contact = trim($_POST['contact'] ?? '');
$address = trim($_POST['address'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if ($name === '') {
    header('Location: /rice-business/frontend/customers.php?error=required');
    exit;
}

$contact = $contact !== '' ? $contact : null;
$address = $address !== '' ? $address : null;
$notes = $notes !== '' ? $notes : null;

try {
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE customers
             SET name = ?, contact = ?, address = ?, notes = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, $contact, $address, $notes, $id]);
        header('Location: /rice-business/frontend/customers.php?success=updated');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO customers (name, contact, address, notes)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $contact, $address, $notes]);
        header('Location: /rice-business/frontend/customers.php?success=created');
    }
} catch (PDOException $e) {
    header('Location: /rice-business/frontend/customers.php?error=save');
}

exit;
