<?php

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/auth.php';

requireLogin();

$type = trim($_GET['type'] ?? 'summary');
$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $from = date('Y-m-01');
    $to = date('Y-m-d');
}

$filename = 'report_' . $type . '_' . $from . '_to_' . $to . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

switch ($type) {
    case 'sales':
        fputcsv($out, ['Date', 'Sale ID', 'Customer', 'Payment', 'Total']);
        $stmt = $pdo->prepare(
            "SELECT s.sale_date, s.id, COALESCE(c.name, 'Walk-in / Just buying') AS customer_name,
                    s.payment_method, s.total
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.sale_date BETWEEN ? AND ?
             ORDER BY s.sale_date ASC, s.id ASC"
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, [
                $row['sale_date'],
                $row['id'],
                $row['customer_name'],
                $row['payment_method'],
                $row['total'],
            ]);
        }
        break;

    case 'expenses':
        fputcsv($out, ['Date', 'Category', 'Amount', 'Notes']);
        $stmt = $pdo->prepare(
            'SELECT expense_date, category, amount, notes
             FROM expenses
             WHERE expense_date BETWEEN ? AND ?
             ORDER BY expense_date ASC, id ASC'
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, [
                $row['expense_date'],
                $row['category'],
                $row['amount'],
                $row['notes'],
            ]);
        }
        break;

    case 'top_products':
        fputcsv($out, ['Product', 'Qty Sold (kg)', 'Sales Amount']);
        $stmt = $pdo->prepare(
            'SELECT p.name,
                    SUM(si.quantity) AS qty_sold,
                    SUM(si.subtotal) AS sales_amount
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN products p ON p.id = si.product_id
             WHERE s.sale_date BETWEEN ? AND ?
             GROUP BY p.id, p.name
             ORDER BY qty_sold DESC'
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, [$row['name'], $row['qty_sold'], $row['sales_amount']]);
        }
        break;

    case 'inventory':
        fputcsv($out, ['Product', 'Category', 'Stock (kg)', 'Min Stock', 'Buying Price', 'Selling Price', 'Status']);
        $stmt = $pdo->query(
            'SELECT name, category, stock, minimum_stock, buying_price, selling_price, status
             FROM products
             ORDER BY name ASC'
        );
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, [
                $row['name'],
                $row['category'],
                $row['stock'],
                $row['minimum_stock'],
                $row['buying_price'],
                $row['selling_price'],
                $row['status'],
            ]);
        }
        break;

    default:
        fputcsv($out, ['Metric', 'Amount']);
        $salesStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(total), 0) FROM sales WHERE sale_date BETWEEN ? AND ?'
        );
        $salesStmt->execute([$from, $to]);
        $salesTotal = (float) $salesStmt->fetchColumn();

        $expenseStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ?'
        );
        $expenseStmt->execute([$from, $to]);
        $expenseTotal = (float) $expenseStmt->fetchColumn();

        $purchaseStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(total), 0) FROM purchases WHERE purchase_date BETWEEN ? AND ?'
        );
        $purchaseStmt->execute([$from, $to]);
        $purchaseTotal = (float) $purchaseStmt->fetchColumn();

        fputcsv($out, ['Sales Total', $salesTotal]);
        fputcsv($out, ['Purchase Total', $purchaseTotal]);
        fputcsv($out, ['Expense Total', $expenseTotal]);
        fputcsv($out, ['Profit (Sales - Expenses)', $salesTotal - $expenseTotal]);
        break;
}

fclose($out);
exit;
