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
               AND p.product_type = \'RICE\'
             GROUP BY p.id, p.name
             ORDER BY qty_sold DESC'
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, [$row['name'], $row['qty_sold'], $row['sales_amount']]);
        }
        break;

    case 'top_grocery':
        fputcsv($out, ['Product', 'Unit', 'Qty Sold', 'Sales Amount']);
        $stmt = $pdo->prepare(
            'SELECT p.name,
                    p.unit,
                    SUM(si.quantity) AS qty_sold,
                    SUM(si.subtotal) AS sales_amount
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN products p ON p.id = si.product_id
             WHERE s.sale_date BETWEEN ? AND ?
               AND p.product_type = \'GROCERY\'
             GROUP BY p.id, p.name, p.unit
             ORDER BY qty_sold DESC'
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, [$row['name'], $row['unit'], $row['qty_sold'], $row['sales_amount']]);
        }
        break;

    case 'profit_by_variety':
        fputcsv($out, [
            'Product',
            'Category',
            'Qty Sold (kg)',
            'Sales',
            'COGS',
            'Gross Profit',
            'Gross Margin %',
            'Profit per kg',
            'Current Stock (kg)',
        ]);
        $stmt = $pdo->prepare(
            'SELECT p.name,
                    p.category,
                    p.stock,
                    COALESCE(SUM(si.quantity), 0) AS qty_sold,
                    COALESCE(SUM(si.subtotal), 0) AS sales_amount,
                    COALESCE(SUM(si.quantity * p.buying_price), 0) AS cogs
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN products p ON p.id = si.product_id
             WHERE s.sale_date BETWEEN ? AND ?
               AND p.product_type = \'RICE\'
             GROUP BY p.id, p.name, p.category, p.stock
             ORDER BY (COALESCE(SUM(si.subtotal), 0) - COALESCE(SUM(si.quantity * p.buying_price), 0)) DESC'
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $row) {
            $salesAmt = (float) $row['sales_amount'];
            $cogsAmt = (float) $row['cogs'];
            $gp = $salesAmt - $cogsAmt;
            $qty = (float) $row['qty_sold'];
            $margin = $salesAmt > 0 ? round(($gp / $salesAmt) * 100, 2) : 0;
            $profitPerKg = $qty > 0 ? round($gp / $qty, 2) : 0;
            fputcsv($out, [
                $row['name'],
                $row['category'],
                $row['qty_sold'],
                $salesAmt,
                $cogsAmt,
                $gp,
                $margin,
                $profitPerKg,
                $row['stock'],
            ]);
        }
        break;

    case 'inventory':
        fputcsv($out, [
            'Product',
            'Type',
            'Category',
            'Unit',
            'Stock',
            'Min Stock',
            'Buying Price',
            'Selling Price',
            'Cost Value',
            'Selling Value',
            'Potential Gross Profit',
            'Status',
        ]);
        $stmt = $pdo->query(
            'SELECT name, product_type, category, unit, stock, minimum_stock, buying_price, selling_price, status
             FROM products
             ORDER BY (stock * buying_price) DESC, name ASC'
        );
        foreach ($stmt->fetchAll() as $row) {
            $stock = (float) $row['stock'];
            $costValue = $stock * (float) $row['buying_price'];
            $sellValue = $stock * (float) $row['selling_price'];
            fputcsv($out, [
                $row['name'],
                $row['product_type'],
                $row['category'],
                $row['unit'],
                $row['stock'],
                $row['minimum_stock'],
                $row['buying_price'],
                $row['selling_price'],
                round($costValue, 2),
                round($sellValue, 2),
                round($sellValue - $costValue, 2),
                $row['status'],
            ]);
        }
        break;

    case 'inventory_value':
        fputcsv($out, ['Metric', 'Amount']);
        $totals = $pdo->query(
            'SELECT
                COALESCE(SUM(CASE WHEN product_type = \'RICE\' THEN stock ELSE 0 END), 0) AS rice_stock_kg,
                COALESCE(SUM(stock * buying_price), 0) AS cost_value,
                COALESCE(SUM(stock * selling_price), 0) AS sell_value,
                COALESCE(SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END), 0) AS active_count
             FROM products'
        )->fetch();
        $costValue = (float) $totals['cost_value'];
        $sellValue = (float) $totals['sell_value'];
        fputcsv($out, ['Rice Stock (kg)', (float) $totals['rice_stock_kg']]);
        fputcsv($out, ['Active Products', (int) $totals['active_count']]);
        fputcsv($out, ['Inventory Cost Value', round($costValue, 2)]);
        fputcsv($out, ['Inventory Selling Value', round($sellValue, 2)]);
        fputcsv($out, ['Potential Gross Profit', round($sellValue - $costValue, 2)]);
        fputcsv($out, [
            'Potential Margin %',
            $sellValue > 0 ? round((($sellValue - $costValue) / $sellValue) * 100, 2) : 0,
        ]);

        fputcsv($out, []);
        fputcsv($out, [
            'Product',
            'Type',
            'Category',
            'Unit',
            'Stock',
            'Buying Price',
            'Selling Price',
            'Cost Value',
            'Selling Value',
            'Potential Gross Profit',
            'Margin %',
            'Status',
        ]);
        $stmt = $pdo->query(
            'SELECT name, product_type, category, unit, stock, buying_price, selling_price, status
             FROM products
             ORDER BY (stock * buying_price) DESC, name ASC'
        );
        foreach ($stmt->fetchAll() as $row) {
            $stock = (float) $row['stock'];
            $rowCost = $stock * (float) $row['buying_price'];
            $rowSell = $stock * (float) $row['selling_price'];
            $rowGp = $rowSell - $rowCost;
            $rowMargin = $rowSell > 0 ? round(($rowGp / $rowSell) * 100, 2) : 0;
            fputcsv($out, [
                $row['name'],
                $row['product_type'],
                $row['category'],
                $row['unit'],
                $row['stock'],
                $row['buying_price'],
                $row['selling_price'],
                round($rowCost, 2),
                round($rowSell, 2),
                round($rowGp, 2),
                $rowMargin,
                $row['status'],
            ]);
        }

        fputcsv($out, []);
        fputcsv($out, ['Category', 'Products', 'Cost Value', 'Selling Value']);
        $catStmt = $pdo->query(
            "SELECT COALESCE(NULLIF(category, ''), 'Uncategorized') AS category_name,
                    COUNT(*) AS product_count,
                    COALESCE(SUM(stock * buying_price), 0) AS cost_value,
                    COALESCE(SUM(stock * selling_price), 0) AS sell_value
             FROM products
             GROUP BY COALESCE(NULLIF(category, ''), 'Uncategorized')
             ORDER BY cost_value DESC"
        );
        foreach ($catStmt->fetchAll() as $row) {
            fputcsv($out, [
                $row['category_name'],
                $row['product_count'],
                round((float) $row['cost_value'], 2),
                round((float) $row['sell_value'], 2),
            ]);
        }
        break;

    case 'inventory_movement':
        $lookbackDays = 30;
        $movementFrom = date('Y-m-d', strtotime('-' . ($lookbackDays - 1) . ' days'));
        $movementTo = date('Y-m-d');
        fputcsv($out, [
            'Product',
            'Category',
            'Stock (kg)',
            'Min Stock',
            'Qty Sold (30d kg)',
            'Avg Daily (kg)',
            'Days of Cover',
            'Tied Capital',
            'No Sales 30d',
            'Excessive Inventory',
        ]);
        $stmt = $pdo->prepare(
            "SELECT p.name,
                    p.category,
                    p.stock,
                    p.minimum_stock,
                    p.buying_price,
                    COALESCE((
                        SELECT SUM(si.quantity)
                        FROM sale_items si
                        INNER JOIN sales s ON s.id = si.sale_id
                        WHERE si.product_id = p.id
                          AND s.sale_date BETWEEN ? AND ?
                    ), 0) AS qty_sold
             FROM products p
             WHERE p.status = 'active'
               AND p.product_type = 'RICE'
             ORDER BY qty_sold DESC, p.name ASC"
        );
        $stmt->execute([$movementFrom, $movementTo]);
        foreach ($stmt->fetchAll() as $row) {
            $qty = (float) $row['qty_sold'];
            $stock = (float) $row['stock'];
            $minStock = (float) $row['minimum_stock'];
            $avgDaily = $qty / $lookbackDays;
            $daysSupply = $avgDaily > 0 ? round($stock / $avgDaily, 1) : '';
            $tied = round($stock * (float) $row['buying_price'], 2);
            $noSales = $qty <= 0 ? 'Yes' : 'No';
            $excessive = 'No';
            if ($stock > 0) {
                if ($minStock > 0 && $stock >= ($minStock * 3)) {
                    $excessive = 'Yes';
                } elseif ($qty <= 0 && $stock > $minStock) {
                    $excessive = 'Yes';
                } elseif ($avgDaily > 0 && ($stock / $avgDaily) >= 90 && $stock > $minStock) {
                    $excessive = 'Yes';
                }
            }
            fputcsv($out, [
                $row['name'],
                $row['category'],
                $row['stock'],
                $row['minimum_stock'],
                $qty,
                round($avgDaily, 2),
                $daysSupply === '' ? ($qty <= 0 ? 'No sales' : '') : $daysSupply,
                $tied,
                $noSales,
                $excessive,
            ]);
        }
        break;

    case 'stock_forecast':
        $lookbackDays = 30;
        $movementFrom = date('Y-m-d', strtotime('-' . ($lookbackDays - 1) . ' days'));
        $movementTo = date('Y-m-d');
        fputcsv($out, [
            'Product',
            'Category',
            'Stock (kg)',
            'Min Stock',
            'Avg Daily Sales (kg)',
            'Days Until Empty',
            'Estimated Stockout Date',
            'Days Until Min Stock',
            'Date Hits Min Stock',
            'Suggested Reorder (kg)',
            'Risk',
        ]);
        $stmt = $pdo->prepare(
            "SELECT p.name,
                    p.category,
                    p.stock,
                    p.minimum_stock,
                    COALESCE((
                        SELECT SUM(si.quantity)
                        FROM sale_items si
                        INNER JOIN sales s ON s.id = si.sale_id
                        WHERE si.product_id = p.id
                          AND s.sale_date BETWEEN ? AND ?
                    ), 0) AS qty_sold
             FROM products p
             WHERE p.status = 'active'
               AND p.product_type = 'RICE'
             ORDER BY p.name ASC"
        );
        $stmt->execute([$movementFrom, $movementTo]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $stock = (float) $row['stock'];
            $minStock = (float) $row['minimum_stock'];
            $avgDaily = ((float) $row['qty_sold']) / $lookbackDays;
            if ($avgDaily <= 0) {
                fputcsv($out, [
                    $row['name'],
                    $row['category'],
                    $row['stock'],
                    $row['minimum_stock'],
                    0,
                    '',
                    '',
                    '',
                    '',
                    '',
                    'Cannot forecast',
                ]);
                continue;
            }
            $daysToEmpty = $stock / $avgDaily;
            $daysToMin = max(0.0, $stock - $minStock) / $avgDaily;
            if ($stock <= 0) {
                $risk = 'Out';
            } elseif ($daysToEmpty <= 7) {
                $risk = 'Critical';
            } elseif ($daysToEmpty <= 14) {
                $risk = 'Warning';
            } elseif ($daysToEmpty <= 30) {
                $risk = 'Watch';
            } else {
                $risk = 'OK';
            }
            $rows[] = [
                $row['name'],
                $row['category'],
                $row['stock'],
                $row['minimum_stock'],
                round($avgDaily, 2),
                round($daysToEmpty, 1),
                date('Y-m-d', strtotime('+' . (int) floor($daysToEmpty) . ' days')),
                round($daysToMin, 1),
                $stock <= $minStock
                    ? 'Already'
                    : date('Y-m-d', strtotime('+' . (int) floor($daysToMin) . ' days')),
                round(max(0.0, ($avgDaily * 14) - $stock), 2),
                $risk,
                $daysToEmpty,
            ];
        }
        usort($rows, static fn ($a, $b) => $a[11] <=> $b[11]);
        foreach ($rows as $row) {
            array_pop($row);
            fputcsv($out, $row);
        }
        break;

    case 'gross_profit':
        fputcsv($out, ['Date', 'Sales', 'COGS', 'Gross Profit', 'Gross Margin %']);
        $stmt = $pdo->prepare(
            'SELECT s.sale_date,
                    COALESCE(SUM(si.subtotal), 0) AS sales_amount,
                    COALESCE(SUM(si.quantity * p.buying_price), 0) AS cogs
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN products p ON p.id = si.product_id
             WHERE s.sale_date BETWEEN ? AND ?
             GROUP BY s.sale_date
             ORDER BY s.sale_date ASC'
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $row) {
            $salesAmt = (float) $row['sales_amount'];
            $cogsAmt = (float) $row['cogs'];
            $gp = $salesAmt - $cogsAmt;
            $margin = $salesAmt > 0 ? round(($gp / $salesAmt) * 100, 2) : 0;
            fputcsv($out, [
                $row['sale_date'],
                $salesAmt,
                $cogsAmt,
                $gp,
                $margin,
            ]);
        }
        break;

    case 'net_income':
        fputcsv($out, ['Date', 'Sales', 'COGS', 'Gross Profit', 'Expenses', 'Net Income']);
        $salesMap = [];
        $salesStmt = $pdo->prepare(
            'SELECT sale_date, COALESCE(SUM(total), 0) AS total
             FROM sales WHERE sale_date BETWEEN ? AND ? GROUP BY sale_date'
        );
        $salesStmt->execute([$from, $to]);
        foreach ($salesStmt->fetchAll() as $row) {
            $salesMap[$row['sale_date']] = (float) $row['total'];
        }

        $cogsMap = [];
        $cogsStmt = $pdo->prepare(
            'SELECT s.sale_date, COALESCE(SUM(si.quantity * p.buying_price), 0) AS cogs
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN products p ON p.id = si.product_id
             WHERE s.sale_date BETWEEN ? AND ?
             GROUP BY s.sale_date'
        );
        $cogsStmt->execute([$from, $to]);
        foreach ($cogsStmt->fetchAll() as $row) {
            $cogsMap[$row['sale_date']] = (float) $row['cogs'];
        }

        $expenseMap = [];
        $expenseStmt = $pdo->prepare(
            'SELECT expense_date, COALESCE(SUM(amount), 0) AS total
             FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY expense_date'
        );
        $expenseStmt->execute([$from, $to]);
        foreach ($expenseStmt->fetchAll() as $row) {
            $expenseMap[$row['expense_date']] = (float) $row['total'];
        }

        $dates = array_unique(array_merge(array_keys($salesMap), array_keys($cogsMap), array_keys($expenseMap)));
        sort($dates);
        $sumSales = 0.0;
        $sumCogs = 0.0;
        $sumExpenses = 0.0;
        foreach ($dates as $date) {
            $daySales = $salesMap[$date] ?? 0.0;
            $dayCogs = $cogsMap[$date] ?? 0.0;
            $dayExpenses = $expenseMap[$date] ?? 0.0;
            $dayGp = $daySales - $dayCogs;
            $dayNet = $dayGp - $dayExpenses;
            $sumSales += $daySales;
            $sumCogs += $dayCogs;
            $sumExpenses += $dayExpenses;
            fputcsv($out, [
                $date,
                round($daySales, 2),
                round($dayCogs, 2),
                round($dayGp, 2),
                round($dayExpenses, 2),
                round($dayNet, 2),
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, [
            'TOTAL',
            round($sumSales, 2),
            round($sumCogs, 2),
            round($sumSales - $sumCogs, 2),
            round($sumExpenses, 2),
            round($sumSales - $sumCogs - $sumExpenses, 2),
        ]);
        break;

    case 'utang_summary':
        fputcsv($out, ['Metric', 'Value']);
        $totals = $pdo->query(
            "SELECT
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
                COALESCE(SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END), 0) AS partial_count,
                COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_count,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END), 0) AS paid_amount,
                COALESCE(SUM(CASE WHEN payment_status = 'partial' THEN total ELSE 0 END), 0) AS partial_amount,
                COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN total ELSE 0 END), 0) AS unpaid_amount,
                COALESCE(SUM(GREATEST(total - amount_paid, 0)), 0) AS outstanding_total,
                COALESCE(SUM(CASE WHEN (total - amount_paid) > 0 THEN 1 ELSE 0 END), 0) AS open_count
             FROM sales"
        )->fetch();
        fputcsv($out, ['Outstanding Total', (float) $totals['outstanding_total']]);
        fputcsv($out, ['Open Sales', (int) $totals['open_count']]);
        fputcsv($out, ['Paid Sales Count', (int) $totals['paid_count']]);
        fputcsv($out, ['Paid Sales Amount', (float) $totals['paid_amount']]);
        fputcsv($out, ['Partial Sales Count', (int) $totals['partial_count']]);
        fputcsv($out, ['Partial Sales Amount', (float) $totals['partial_amount']]);
        fputcsv($out, ['Unpaid Sales Count', (int) $totals['unpaid_count']]);
        fputcsv($out, ['Unpaid Sales Amount', (float) $totals['unpaid_amount']]);

        $agingRows = $pdo->query(
            "SELECT
                CASE
                    WHEN DATEDIFF(CURDATE(), sale_date) BETWEEN 0 AND 30 THEN '0-30'
                    WHEN DATEDIFF(CURDATE(), sale_date) BETWEEN 31 AND 60 THEN '31-60'
                    WHEN DATEDIFF(CURDATE(), sale_date) BETWEEN 61 AND 90 THEN '61-90'
                    ELSE '90+'
                END AS age_bucket,
                COUNT(*) AS sale_count,
                COALESCE(SUM(total - amount_paid), 0) AS balance
             FROM sales
             WHERE (total - amount_paid) > 0.001
             GROUP BY age_bucket"
        )->fetchAll();
        fputcsv($out, []);
        fputcsv($out, ['Aging Bucket', 'Open Sales', 'Balance']);
        $agingMap = ['0-30' => [0, 0.0], '31-60' => [0, 0.0], '61-90' => [0, 0.0], '90+' => [0, 0.0]];
        foreach ($agingRows as $row) {
            $agingMap[$row['age_bucket']] = [(int) $row['sale_count'], (float) $row['balance']];
        }
        foreach ($agingMap as $bucket => $vals) {
            fputcsv($out, [$bucket . ' days', $vals[0], $vals[1]]);
        }
        break;

    case 'utang_open':
        fputcsv($out, ['Sale ID', 'Date', 'Customer', 'Status', 'Total', 'Amount Paid', 'Balance', 'Days Open']);
        $stmt = $pdo->query(
            "SELECT s.id, s.sale_date, s.total, s.amount_paid, s.payment_status,
                    COALESCE(c.name, 'Walk-in / Just buying') AS customer_name,
                    DATEDIFF(CURDATE(), s.sale_date) AS days_open
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE (s.total - s.amount_paid) > 0.001
             ORDER BY (s.total - s.amount_paid) DESC, s.sale_date ASC"
        );
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, [
                $row['id'],
                $row['sale_date'],
                $row['customer_name'],
                $row['payment_status'],
                $row['total'],
                $row['amount_paid'],
                (float) $row['total'] - (float) $row['amount_paid'],
                $row['days_open'],
            ]);
        }
        break;

    case 'utang_customers':
        fputcsv($out, ['Customer', 'Contact', 'Open Sales', 'Balance', 'Oldest Sale', 'Newest Sale']);
        $stmt = $pdo->query(
            "SELECT COALESCE(c.name, 'Walk-in / Just buying') AS customer_name,
                    COALESCE(c.contact, '') AS contact,
                    COUNT(*) AS open_sales,
                    COALESCE(SUM(s.total - s.amount_paid), 0) AS balance,
                    MIN(s.sale_date) AS oldest_sale,
                    MAX(s.sale_date) AS newest_sale
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE (s.total - s.amount_paid) > 0.001
             GROUP BY c.id, c.name, c.contact
             ORDER BY balance DESC"
        );
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, [
                $row['customer_name'],
                $row['contact'],
                $row['open_sales'],
                $row['balance'],
                $row['oldest_sale'],
                $row['newest_sale'],
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

        $ownerInvestmentStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM expenses
             WHERE expense_date BETWEEN ? AND ? AND category = 'Owner Investment'"
        );
        $ownerInvestmentStmt->execute([$from, $to]);
        $ownerInvestmentTotal = (float) $ownerInvestmentStmt->fetchColumn();

        $purchaseStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(total), 0) FROM purchases WHERE purchase_date BETWEEN ? AND ?'
        );
        $purchaseStmt->execute([$from, $to]);
        $purchaseTotal = (float) $purchaseStmt->fetchColumn();

        $cogsStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(si.quantity * p.buying_price), 0)
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN products p ON p.id = si.product_id
             WHERE s.sale_date BETWEEN ? AND ?'
        );
        $cogsStmt->execute([$from, $to]);
        $cogsTotal = (float) $cogsStmt->fetchColumn();
        $grossProfit = $salesTotal - $cogsTotal;
        $grossMargin = $salesTotal > 0 ? round(($grossProfit / $salesTotal) * 100, 2) : 0;

        fputcsv($out, ['Sales Total', $salesTotal]);
        fputcsv($out, ['Cost of Goods Sold (COGS)', $cogsTotal]);
        fputcsv($out, ['Gross Profit (Sales - COGS)', $grossProfit]);
        fputcsv($out, ['Gross Margin %', $grossMargin]);
        fputcsv($out, ['Purchase Total', $purchaseTotal]);
        fputcsv($out, ['Expense Total', $expenseTotal]);
        fputcsv($out, ['Owner Investment (personal purchases)', $ownerInvestmentTotal]);
        fputcsv($out, ['Net Income (Sales - COGS - Expenses)', $salesTotal - $cogsTotal - $expenseTotal]);
        fputcsv($out, ['Simple Net (Sales - Expenses)', $salesTotal - $expenseTotal]);
        break;
}

fclose($out);
exit;
