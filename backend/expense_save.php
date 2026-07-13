<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rice-business/frontend/expenses.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$category = trim($_POST['category'] ?? '');
$amount = (float) ($_POST['amount'] ?? 0);
$expenseDate = trim($_POST['expense_date'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$allowedCategories = ['Delivery', 'Electricity', 'Salary', 'Maintenance', 'Fuel', 'Other'];

if ($category === '' || $expenseDate === '' || $amount <= 0) {
    header('Location: /rice-business/frontend/expenses.php?error=required');
    exit;
}

if (!in_array($category, $allowedCategories, true)) {
    header('Location: /rice-business/frontend/expenses.php?error=invalid');
    exit;
}

$notes = $notes !== '' ? $notes : null;
$user = currentUser();

try {
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE expenses
             SET category = ?, amount = ?, expense_date = ?, notes = ?
             WHERE id = ?'
        );
        $stmt->execute([$category, $amount, $expenseDate, $notes, $id]);
        header('Location: /rice-business/frontend/expenses.php?success=updated');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO expenses (category, amount, expense_date, notes, user_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $category,
            $amount,
            $expenseDate,
            $notes,
            $user['id'] ?? null,
        ]);
        header('Location: /rice-business/frontend/expenses.php?success=created');
    }
} catch (PDOException $e) {
    header('Location: /rice-business/frontend/expenses.php?error=save');
}

exit;
