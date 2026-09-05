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

$exists = $pdo->prepare('SELECT id, status FROM products WHERE id = ?');
$exists->execute([$id]);
$product = $exists->fetch();

if (!$product) {
    header('Location: /rice-business/frontend/products.php?error=invalid');
    exit;
}

function productHasHistory(PDO $pdo, int $id): bool
{
    $checks = [
        'SELECT 1 FROM sale_items WHERE product_id = ? LIMIT 1',
        'SELECT 1 FROM purchase_items WHERE product_id = ? LIMIT 1',
        'SELECT 1 FROM stock_movements WHERE product_id = ? LIMIT 1',
        'SELECT 1 FROM stock_lots WHERE product_id = ? LIMIT 1',
    ];

    foreach ($checks as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        } catch (PDOException $e) {
            // Table may not exist yet on older installs — treat as no history for that check.
        }
    }

    return false;
}

try {
    if (productHasHistory($pdo, $id)) {
        // Keep ledger history intact; hide from selling catalog.
        if (($product['status'] ?? '') === 'inactive') {
            header('Location: /rice-business/frontend/products.php?error=in_use');
            exit;
        }

        $pdo->prepare("UPDATE products SET status = 'inactive' WHERE id = ?")->execute([$id]);
        header('Location: /rice-business/frontend/products.php?success=deactivated');
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM stock_lots WHERE product_id = ?')->execute([$id]);
    } catch (PDOException $e) {
        // stock_lots may not exist
    }
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
