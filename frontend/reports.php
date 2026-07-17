<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Reports';
$activePage = 'reports';

$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));
$period = trim($_GET['period'] ?? 'daily');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}
if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
    $period = 'daily';
}

$salesTotalStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total), 0) FROM sales WHERE sale_date BETWEEN ? AND ?'
);
$salesTotalStmt->execute([$from, $to]);
$salesTotal = (float) $salesTotalStmt->fetchColumn();

$expenseTotalStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ?'
);
$expenseTotalStmt->execute([$from, $to]);
$expenseTotal = (float) $expenseTotalStmt->fetchColumn();

$ownerInvestmentStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM expenses
     WHERE expense_date BETWEEN ? AND ? AND category = 'Owner Investment'"
);
$ownerInvestmentStmt->execute([$from, $to]);
$ownerInvestmentTotal = (float) $ownerInvestmentStmt->fetchColumn();

$operatingExpensesTotal = $expenseTotal - $ownerInvestmentTotal;

$purchaseTotalStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total), 0) FROM purchases WHERE purchase_date BETWEEN ? AND ?'
);
$purchaseTotalStmt->execute([$from, $to]);
$purchaseTotal = (float) $purchaseTotalStmt->fetchColumn();

$profit = $salesTotal - $expenseTotal;

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
$grossMargin = $salesTotal > 0 ? ($grossProfit / $salesTotal) * 100 : 0.0;
$netIncome = $salesTotal - $cogsTotal - $expenseTotal;

$saleCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM sales WHERE sale_date BETWEEN ? AND ?'
);
$saleCountStmt->execute([$from, $to]);
$saleCount = (int) $saleCountStmt->fetchColumn();

if ($period === 'weekly') {
    $salesTrendSql = "SELECT YEARWEEK(sale_date, 1) AS period_key,
                             MIN(sale_date) AS period_label,
                             COALESCE(SUM(total), 0) AS total
                      FROM sales
                      WHERE sale_date BETWEEN ? AND ?
                      GROUP BY YEARWEEK(sale_date, 1)
                      ORDER BY period_key ASC";
} elseif ($period === 'monthly') {
    $salesTrendSql = "SELECT DATE_FORMAT(sale_date, '%Y-%m') AS period_key,
                             DATE_FORMAT(sale_date, '%Y-%m') AS period_label,
                             COALESCE(SUM(total), 0) AS total
                      FROM sales
                      WHERE sale_date BETWEEN ? AND ?
                      GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
                      ORDER BY period_key ASC";
} else {
    $salesTrendSql = "SELECT sale_date AS period_key,
                             sale_date AS period_label,
                             COALESCE(SUM(total), 0) AS total
                      FROM sales
                      WHERE sale_date BETWEEN ? AND ?
                      GROUP BY sale_date
                      ORDER BY sale_date ASC";
}

$salesTrendStmt = $pdo->prepare($salesTrendSql);
$salesTrendStmt->execute([$from, $to]);
$salesTrend = $salesTrendStmt->fetchAll();

if ($period === 'weekly') {
    $gpTrendSql = "SELECT YEARWEEK(s.sale_date, 1) AS period_key,
                          MIN(s.sale_date) AS period_label,
                          COALESCE(SUM(si.subtotal), 0) AS sales_amount,
                          COALESCE(SUM(si.quantity * p.buying_price), 0) AS cogs
                   FROM sale_items si
                   INNER JOIN sales s ON s.id = si.sale_id
                   INNER JOIN products p ON p.id = si.product_id
                   WHERE s.sale_date BETWEEN ? AND ?
                   GROUP BY YEARWEEK(s.sale_date, 1)
                   ORDER BY period_key ASC";
} elseif ($period === 'monthly') {
    $gpTrendSql = "SELECT DATE_FORMAT(s.sale_date, '%Y-%m') AS period_key,
                          DATE_FORMAT(s.sale_date, '%Y-%m') AS period_label,
                          COALESCE(SUM(si.subtotal), 0) AS sales_amount,
                          COALESCE(SUM(si.quantity * p.buying_price), 0) AS cogs
                   FROM sale_items si
                   INNER JOIN sales s ON s.id = si.sale_id
                   INNER JOIN products p ON p.id = si.product_id
                   WHERE s.sale_date BETWEEN ? AND ?
                   GROUP BY DATE_FORMAT(s.sale_date, '%Y-%m')
                   ORDER BY period_key ASC";
} else {
    $gpTrendSql = "SELECT s.sale_date AS period_key,
                          s.sale_date AS period_label,
                          COALESCE(SUM(si.subtotal), 0) AS sales_amount,
                          COALESCE(SUM(si.quantity * p.buying_price), 0) AS cogs
                   FROM sale_items si
                   INNER JOIN sales s ON s.id = si.sale_id
                   INNER JOIN products p ON p.id = si.product_id
                   WHERE s.sale_date BETWEEN ? AND ?
                   GROUP BY s.sale_date
                   ORDER BY s.sale_date ASC";
}

$gpTrendStmt = $pdo->prepare($gpTrendSql);
$gpTrendStmt->execute([$from, $to]);
$grossProfitTrend = $gpTrendStmt->fetchAll();

$highestGpDay = null;
$dailyGpStmt = $pdo->prepare(
    'SELECT sale_date, sales_amount, cogs
     FROM (
         SELECT s.sale_date,
                COALESCE(SUM(si.subtotal), 0) AS sales_amount,
                COALESCE(SUM(si.quantity * p.buying_price), 0) AS cogs
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         INNER JOIN products p ON p.id = si.product_id
         WHERE s.sale_date BETWEEN ? AND ?
         GROUP BY s.sale_date
     ) daily
     ORDER BY (sales_amount - cogs) DESC
     LIMIT 1'
);
$dailyGpStmt->execute([$from, $to]);
$highestGpDay = $dailyGpStmt->fetch() ?: null;

$today = date('Y-m-d');
$currentMonthStart = date('Y-m-01');
$previousMonthStart = date('Y-m-01', strtotime('first day of last month'));
$previousMonthEnd = date('Y-m-t', strtotime('first day of last month'));

$monthMetricsSql = 'SELECT
        COALESCE((SELECT SUM(total) FROM sales WHERE sale_date BETWEEN ? AND ?), 0) AS sales_amount,
        COALESCE((
            SELECT SUM(si.quantity * p.buying_price)
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            INNER JOIN products p ON p.id = si.product_id
            WHERE s.sale_date BETWEEN ? AND ?
        ), 0) AS cogs';

$curMonthStmt = $pdo->prepare($monthMetricsSql);
$curMonthStmt->execute([$currentMonthStart, $today, $currentMonthStart, $today]);
$curMonth = $curMonthStmt->fetch();
$curMonthSales = (float) $curMonth['sales_amount'];
$curMonthCogs = (float) $curMonth['cogs'];
$curMonthGp = $curMonthSales - $curMonthCogs;
$curMonthMargin = $curMonthSales > 0 ? ($curMonthGp / $curMonthSales) * 100 : 0.0;

$prevMonthStmt = $pdo->prepare($monthMetricsSql);
$prevMonthStmt->execute([$previousMonthStart, $previousMonthEnd, $previousMonthStart, $previousMonthEnd]);
$prevMonth = $prevMonthStmt->fetch();
$prevMonthSales = (float) $prevMonth['sales_amount'];
$prevMonthCogs = (float) $prevMonth['cogs'];
$prevMonthGp = $prevMonthSales - $prevMonthCogs;
$prevMonthMargin = $prevMonthSales > 0 ? ($prevMonthGp / $prevMonthSales) * 100 : 0.0;

$gpMomChange = $curMonthGp - $prevMonthGp;
$gpMomPercent = $prevMonthGp != 0.0
    ? ($gpMomChange / abs($prevMonthGp)) * 100
    : ($curMonthGp != 0.0 ? 100.0 : 0.0);

$topProductsStmt = $pdo->prepare(
    'SELECT p.name,
            p.unit,
            SUM(si.quantity) AS qty_sold,
            SUM(si.subtotal) AS sales_amount
     FROM sale_items si
     INNER JOIN sales s ON s.id = si.sale_id
     INNER JOIN products p ON p.id = si.product_id
     WHERE s.sale_date BETWEEN ? AND ?
       AND p.product_type = \'RICE\'
     GROUP BY p.id, p.name
     ORDER BY qty_sold DESC
     LIMIT 10'
);
$topProductsStmt->execute([$from, $to]);
$topProducts = $topProductsStmt->fetchAll();

$topOtherProductsStmt = $pdo->prepare(
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
     ORDER BY qty_sold DESC
     LIMIT 10'
);
$topOtherProductsStmt->execute([$from, $to]);
$topOtherProducts = $topOtherProductsStmt->fetchAll();

$profitByVarietyStmt = $pdo->prepare(
    'SELECT p.id,
            p.name,
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
$profitByVarietyStmt->execute([$from, $to]);
$profitByVariety = $profitByVarietyStmt->fetchAll();

$topProfitProduct = null;
$topMarginProduct = null;
$varietyChartLabels = [];
$varietyChartProfit = [];
$varietyChartSales = [];

foreach ($profitByVariety as $index => $row) {
    $salesAmt = (float) $row['sales_amount'];
    $cogsAmt = (float) $row['cogs'];
    $gp = $salesAmt - $cogsAmt;
    $margin = $salesAmt > 0 ? ($gp / $salesAmt) * 100 : 0.0;
    $qty = (float) $row['qty_sold'];
    $profitPerKg = $qty > 0 ? $gp / $qty : 0.0;

    $profitByVariety[$index]['gross_profit'] = $gp;
    $profitByVariety[$index]['margin'] = $margin;
    $profitByVariety[$index]['profit_per_kg'] = $profitPerKg;

    if ($index < 10) {
        $varietyChartLabels[] = $row['name'];
        $varietyChartProfit[] = round($gp, 2);
        $varietyChartSales[] = round($salesAmt, 2);
    }

    if ($topProfitProduct === null || $gp > $topProfitProduct['gross_profit']) {
        $topProfitProduct = $profitByVariety[$index];
    }
    if ($salesAmt > 0 && ($topMarginProduct === null || $margin > $topMarginProduct['margin'])) {
        $topMarginProduct = $profitByVariety[$index];
    }
}

$profitByGroceryStmt = $pdo->prepare(
    'SELECT p.id,
            p.name,
            p.category,
            p.unit,
            p.stock,
            COALESCE(SUM(si.quantity), 0) AS qty_sold,
            COALESCE(SUM(si.subtotal), 0) AS sales_amount,
            COALESCE(SUM(si.quantity * p.buying_price), 0) AS cogs
     FROM sale_items si
     INNER JOIN sales s ON s.id = si.sale_id
     INNER JOIN products p ON p.id = si.product_id
     WHERE s.sale_date BETWEEN ? AND ?
       AND p.product_type = \'GROCERY\'
     GROUP BY p.id, p.name, p.category, p.unit, p.stock
     ORDER BY (COALESCE(SUM(si.subtotal), 0) - COALESCE(SUM(si.quantity * p.buying_price), 0)) DESC'
);
$profitByGroceryStmt->execute([$from, $to]);
$profitByGrocery = $profitByGroceryStmt->fetchAll();

$topProfitGrocery = null;
foreach ($profitByGrocery as $index => $row) {
    $salesAmt = (float) $row['sales_amount'];
    $cogsAmt = (float) $row['cogs'];
    $gp = $salesAmt - $cogsAmt;
    $margin = $salesAmt > 0 ? ($gp / $salesAmt) * 100 : 0.0;
    $qty = (float) $row['qty_sold'];
    $unit = $row['unit'] ?? 'pc';
    $profitPerUnit = $qty > 0 ? $gp / $qty : 0.0;

    $profitByGrocery[$index]['gross_profit'] = $gp;
    $profitByGrocery[$index]['margin'] = $margin;
    $profitByGrocery[$index]['profit_per_unit'] = $profitPerUnit;

    if ($topProfitGrocery === null || $gp > $topProfitGrocery['gross_profit']) {
        $topProfitGrocery = $profitByGrocery[$index];
    }
}

$groceryStock = $pdo->query(
    "SELECT name, category, unit, stock, minimum_stock, buying_price, selling_price
     FROM products
     WHERE product_type = 'GROCERY' AND status = 'active'
     ORDER BY name ASC"
)->fetchAll();

$expensesByCategoryStmt = $pdo->prepare(
    'SELECT category, COALESCE(SUM(amount), 0) AS total
     FROM expenses
     WHERE expense_date BETWEEN ? AND ?
     GROUP BY category
     ORDER BY total DESC'
);
$expensesByCategoryStmt->execute([$from, $to]);
$expensesByCategory = $expensesByCategoryStmt->fetchAll();

$inventory = $pdo->query(
    'SELECT id, name, product_type, category, unit, stock, minimum_stock, buying_price, selling_price, status
     FROM products
     ORDER BY (stock * buying_price) DESC, name ASC'
)->fetchAll();

$lowStockCount = 0;
$inventoryCostValue = 0.0;
$inventorySellValue = 0.0;
$inventoryRiceKg = 0.0;
$inventoryActiveCount = 0;
$inventoryGroceryCount = 0;
$inventoryValueByCategory = [];
$valueChartLabels = [];
$valueChartCost = [];
$valueChartSell = [];

foreach ($inventory as $index => $item) {
    $stock = (float) $item['stock'];
    $buy = (float) $item['buying_price'];
    $sell = (float) $item['selling_price'];
    $costValue = $stock * $buy;
    $sellValue = $stock * $sell;
    $potentialProfit = $sellValue - $costValue;
    $margin = $sellValue > 0 ? ($potentialProfit / $sellValue) * 100 : 0.0;

    $inventory[$index]['cost_value'] = $costValue;
    $inventory[$index]['sell_value'] = $sellValue;
    $inventory[$index]['potential_profit'] = $potentialProfit;
    $inventory[$index]['value_margin'] = $margin;

    $inventoryCostValue += $costValue;
    $inventorySellValue += $sellValue;
    if (($item['product_type'] ?? 'RICE') === 'RICE' && ($item['unit'] ?? 'kg') === 'kg') {
        $inventoryRiceKg += $stock;
    }

    if ($item['status'] === 'active') {
        $inventoryActiveCount++;
        if (($item['product_type'] ?? 'RICE') === 'GROCERY') {
            $inventoryGroceryCount++;
        }
    }

    if ((float) $item['stock'] <= (float) $item['minimum_stock']) {
        $lowStockCount++;
    }

    $category = $item['category'] !== '' ? $item['category'] : 'Uncategorized';
    if (!isset($inventoryValueByCategory[$category])) {
        $inventoryValueByCategory[$category] = [
            'cost_value' => 0.0,
            'sell_value' => 0.0,
            'products' => 0,
        ];
    }
    $inventoryValueByCategory[$category]['cost_value'] += $costValue;
    $inventoryValueByCategory[$category]['sell_value'] += $sellValue;
    $inventoryValueByCategory[$category]['products']++;
}

uasort($inventoryValueByCategory, static function ($a, $b) {
    return $b['cost_value'] <=> $a['cost_value'];
});

$inventoryPotentialProfit = $inventorySellValue - $inventoryCostValue;
$inventoryValueMargin = $inventorySellValue > 0
    ? ($inventoryPotentialProfit / $inventorySellValue) * 100
    : 0.0;

foreach (array_slice($inventory, 0, 10) as $item) {
    if ((float) $item['stock'] <= 0) {
        continue;
    }
    $valueChartLabels[] = $item['name'];
    $valueChartCost[] = round((float) $item['cost_value'], 2);
    $valueChartSell[] = round((float) $item['sell_value'], 2);
}

$lookbackDays = 30;
$movementFrom = date('Y-m-d', strtotime('-' . ($lookbackDays - 1) . ' days'));
$movementTo = date('Y-m-d');

$movementStmt = $pdo->prepare(
    "SELECT p.id,
            p.name,
            p.category,
            p.stock,
            p.minimum_stock,
            p.buying_price,
            p.status,
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
$movementStmt->execute([$movementFrom, $movementTo]);
$productMovement = $movementStmt->fetchAll();

$fastestSelling = [];
$slowestSelling = [];
$noSales30 = [];
$excessiveInventory = [];
$fastChartLabels = [];
$fastChartQty = [];
$slowChartLabels = [];
$slowChartQty = [];

foreach ($productMovement as $index => $row) {
    $qty = (float) $row['qty_sold'];
    $stock = (float) $row['stock'];
    $minStock = (float) $row['minimum_stock'];
    $avgDaily = $qty / $lookbackDays;
    $daysSupply = $avgDaily > 0 ? $stock / $avgDaily : ($stock > 0 ? null : 0.0);
    $tiedCapital = $stock * (float) $row['buying_price'];

    $productMovement[$index]['avg_daily'] = $avgDaily;
    $productMovement[$index]['days_supply'] = $daysSupply;
    $productMovement[$index]['tied_capital'] = $tiedCapital;

    if ($qty <= 0) {
        $noSales30[] = $productMovement[$index];
    }

    $isExcessive = false;
    if ($stock > 0) {
        if ($minStock > 0 && $stock >= ($minStock * 3)) {
            $isExcessive = true;
        } elseif ($qty <= 0 && $stock > $minStock) {
            $isExcessive = true;
        } elseif ($daysSupply !== null && $daysSupply >= 90 && $stock > $minStock) {
            $isExcessive = true;
        }
    }
    if ($isExcessive) {
        $excessiveInventory[] = $productMovement[$index];
    }
}

$fastestSelling = array_slice(array_values(array_filter(
    $productMovement,
    static fn ($row) => (float) $row['qty_sold'] > 0
)), 0, 10);

$soldOnly = array_values(array_filter(
    $productMovement,
    static fn ($row) => (float) $row['qty_sold'] > 0
));
usort($soldOnly, static function ($a, $b) {
    $cmp = (float) $a['qty_sold'] <=> (float) $b['qty_sold'];
    if ($cmp !== 0) {
        return $cmp;
    }
    return (float) $b['stock'] <=> (float) $a['stock'];
});
$slowestSelling = array_slice($soldOnly, 0, 10);

// Prefer highlighting dead stock among slow movers when few products sold.
if (count($slowestSelling) < 5) {
    $deadStock = $noSales30;
    usort($deadStock, static fn ($a, $b) => (float) $b['stock'] <=> (float) $a['stock']);
    foreach ($deadStock as $row) {
        if (count($slowestSelling) >= 10) {
            break;
        }
        $already = false;
        foreach ($slowestSelling as $existing) {
            if ((int) $existing['id'] === (int) $row['id']) {
                $already = true;
                break;
            }
        }
        if (!$already) {
            $slowestSelling[] = $row;
        }
    }
}

usort($excessiveInventory, static function ($a, $b) {
    $aDays = $a['days_supply'] === null ? PHP_FLOAT_MAX : (float) $a['days_supply'];
    $bDays = $b['days_supply'] === null ? PHP_FLOAT_MAX : (float) $b['days_supply'];
    $cmp = $bDays <=> $aDays;
    if ($cmp !== 0) {
        return $cmp;
    }
    return (float) $b['stock'] <=> (float) $a['stock'];
});

foreach ($fastestSelling as $row) {
    $fastChartLabels[] = $row['name'];
    $fastChartQty[] = round((float) $row['qty_sold'], 2);
}
foreach (array_slice($slowestSelling, 0, 10) as $row) {
    $slowChartLabels[] = $row['name'];
    $slowChartQty[] = round((float) $row['qty_sold'], 2);
}

$forecastHorizonDays = 30;
$stockForecast = [];
$forecastUrgent = [];
$forecastWatch = [];
$forecastCannot = [];
$forecastChartLabels = [];
$forecastChartDays = [];
$forecastOutNow = 0;
$forecastCritical = 0; // <= 7 days
$forecastWarning = 0;  // 8-14 days
$forecastWatchCount = 0; // 15-30 days

foreach ($productMovement as $row) {
    $stock = (float) $row['stock'];
    $minStock = (float) $row['minimum_stock'];
    $avgDaily = (float) $row['avg_daily'];

    if ($avgDaily <= 0) {
        $forecastCannot[] = $row;
        continue;
    }

    $daysToEmpty = $stock / $avgDaily;
    $stockAboveMin = max(0.0, $stock - $minStock);
    $daysToMin = $stockAboveMin / $avgDaily;
    $stockoutDate = date('Y-m-d', strtotime('+' . (int) floor($daysToEmpty) . ' days'));
    $minHitDate = date('Y-m-d', strtotime('+' . (int) floor($daysToMin) . ' days'));

    if ($stock <= 0) {
        $urgency = 'out';
        $forecastOutNow++;
    } elseif ($daysToEmpty <= 7) {
        $urgency = 'critical';
        $forecastCritical++;
    } elseif ($daysToEmpty <= 14) {
        $urgency = 'warning';
        $forecastWarning++;
    } elseif ($daysToEmpty <= $forecastHorizonDays) {
        $urgency = 'watch';
        $forecastWatchCount++;
    } else {
        $urgency = 'ok';
    }

    $entry = $row;
    $entry['days_to_empty'] = $daysToEmpty;
    $entry['days_to_min'] = $daysToMin;
    $entry['stockout_date'] = $stockoutDate;
    $entry['min_hit_date'] = $minHitDate;
    $entry['urgency'] = $urgency;
    $entry['suggested_reorder_kg'] = max(0.0, ($avgDaily * 14) - $stock); // cover ~2 more weeks

    $stockForecast[] = $entry;

    if ($urgency === 'out' || $urgency === 'critical' || $urgency === 'warning') {
        $forecastUrgent[] = $entry;
    } elseif ($urgency === 'watch') {
        $forecastWatch[] = $entry;
    }
}

usort($stockForecast, static function ($a, $b) {
    return (float) $a['days_to_empty'] <=> (float) $b['days_to_empty'];
});
usort($forecastUrgent, static function ($a, $b) {
    return (float) $a['days_to_empty'] <=> (float) $b['days_to_empty'];
});
usort($forecastWatch, static function ($a, $b) {
    return (float) $a['days_to_empty'] <=> (float) $b['days_to_empty'];
});

foreach (array_slice($stockForecast, 0, 10) as $row) {
    $forecastChartLabels[] = $row['name'];
    $forecastChartDays[] = round((float) $row['days_to_empty'], 1);
}

$forecastAtRiskCount = $forecastOutNow + $forecastCritical + $forecastWarning + $forecastWatchCount;

$chartLabels = [];
$chartValues = [];
foreach ($salesTrend as $row) {
    $chartLabels[] = $row['period_label'];
    $chartValues[] = (float) $row['total'];
}

$gpChartLabels = [];
$gpChartSales = [];
$gpChartCogs = [];
$gpChartProfit = [];
foreach ($grossProfitTrend as $row) {
    $salesAmt = (float) $row['sales_amount'];
    $cogsAmt = (float) $row['cogs'];
    $gpChartLabels[] = $row['period_label'];
    $gpChartSales[] = $salesAmt;
    $gpChartCogs[] = $cogsAmt;
    $gpChartProfit[] = $salesAmt - $cogsAmt;
}

$dailySalesMap = [];
$dailySalesStmt = $pdo->prepare(
    'SELECT sale_date, COALESCE(SUM(total), 0) AS total
     FROM sales
     WHERE sale_date BETWEEN ? AND ?
     GROUP BY sale_date'
);
$dailySalesStmt->execute([$from, $to]);
foreach ($dailySalesStmt->fetchAll() as $row) {
    $dailySalesMap[$row['sale_date']] = (float) $row['total'];
}

$dailyCogsMap = [];
$dailyCogsStmt = $pdo->prepare(
    'SELECT s.sale_date, COALESCE(SUM(si.quantity * p.buying_price), 0) AS cogs
     FROM sale_items si
     INNER JOIN sales s ON s.id = si.sale_id
     INNER JOIN products p ON p.id = si.product_id
     WHERE s.sale_date BETWEEN ? AND ?
     GROUP BY s.sale_date'
);
$dailyCogsStmt->execute([$from, $to]);
foreach ($dailyCogsStmt->fetchAll() as $row) {
    $dailyCogsMap[$row['sale_date']] = (float) $row['cogs'];
}

$dailyExpenseMap = [];
$dailyExpenseStmt = $pdo->prepare(
    'SELECT expense_date, COALESCE(SUM(amount), 0) AS total
     FROM expenses
     WHERE expense_date BETWEEN ? AND ?
     GROUP BY expense_date'
);
$dailyExpenseStmt->execute([$from, $to]);
foreach ($dailyExpenseStmt->fetchAll() as $row) {
    $dailyExpenseMap[$row['expense_date']] = (float) $row['total'];
}

$allIncomeDates = array_unique(array_merge(
    array_keys($dailySalesMap),
    array_keys($dailyCogsMap),
    array_keys($dailyExpenseMap)
));
sort($allIncomeDates);

$dailyNetIncome = [];
foreach ($allIncomeDates as $date) {
    $daySales = $dailySalesMap[$date] ?? 0.0;
    $dayCogs = $dailyCogsMap[$date] ?? 0.0;
    $dayExpenses = $dailyExpenseMap[$date] ?? 0.0;
    $dayGross = $daySales - $dayCogs;
    $dayNet = $dayGross - $dayExpenses;
    $dailyNetIncome[] = [
        'date' => $date,
        'sales' => $daySales,
        'cogs' => $dayCogs,
        'expenses' => $dayExpenses,
        'gross_profit' => $dayGross,
        'net_income' => $dayNet,
    ];
}

$bestNetDay = null;
$worstNetDay = null;
foreach ($dailyNetIncome as $row) {
    if ($bestNetDay === null || $row['net_income'] > $bestNetDay['net_income']) {
        $bestNetDay = $row;
    }
    if ($worstNetDay === null || $row['net_income'] < $worstNetDay['net_income']) {
        $worstNetDay = $row;
    }
}

$rangeDayCount = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
$avgDailyNet = $netIncome / $rangeDayCount;
$positiveNetDays = 0;
$negativeNetDays = 0;
foreach ($dailyNetIncome as $row) {
    if ($row['net_income'] >= 0) {
        $positiveNetDays++;
    } else {
        $negativeNetDays++;
    }
}

$weeklyNetIncome = [];
$monthlyNetIncome = [];
foreach ($dailyNetIncome as $row) {
    $weekKey = date('o-\WW', strtotime($row['date']));
    $monthKey = date('Y-m', strtotime($row['date']));

    if (!isset($weeklyNetIncome[$weekKey])) {
        $weeklyNetIncome[$weekKey] = [
            'period_key' => $weekKey,
            'period_label' => $weekKey,
            'sales' => 0.0,
            'cogs' => 0.0,
            'expenses' => 0.0,
            'net_income' => 0.0,
        ];
    }
    $weeklyNetIncome[$weekKey]['sales'] += $row['sales'];
    $weeklyNetIncome[$weekKey]['cogs'] += $row['cogs'];
    $weeklyNetIncome[$weekKey]['expenses'] += $row['expenses'];
    $weeklyNetIncome[$weekKey]['net_income'] += $row['net_income'];

    if (!isset($monthlyNetIncome[$monthKey])) {
        $monthlyNetIncome[$monthKey] = [
            'period_key' => $monthKey,
            'period_label' => $monthKey,
            'sales' => 0.0,
            'cogs' => 0.0,
            'expenses' => 0.0,
            'net_income' => 0.0,
        ];
    }
    $monthlyNetIncome[$monthKey]['sales'] += $row['sales'];
    $monthlyNetIncome[$monthKey]['cogs'] += $row['cogs'];
    $monthlyNetIncome[$monthKey]['expenses'] += $row['expenses'];
    $monthlyNetIncome[$monthKey]['net_income'] += $row['net_income'];
}
$weeklyNetIncome = array_values($weeklyNetIncome);
$monthlyNetIncome = array_values($monthlyNetIncome);

$dailyNetChartLabels = [];
$dailyNetChartValues = [];
foreach ($dailyNetIncome as $row) {
    $dailyNetChartLabels[] = $row['date'];
    $dailyNetChartValues[] = round((float) $row['net_income'], 2);
}

$weeklyNetChartLabels = [];
$weeklyNetChartValues = [];
foreach ($weeklyNetIncome as $row) {
    $weeklyNetChartLabels[] = $row['period_label'];
    $weeklyNetChartValues[] = round((float) $row['net_income'], 2);
}

$monthlyNetChartLabels = [];
$monthlyNetChartValues = [];
foreach ($monthlyNetIncome as $row) {
    $monthlyNetChartLabels[] = $row['period_label'];
    $monthlyNetChartValues[] = round((float) $row['net_income'], 2);
}

$exportBase = '/rice-business/backend/report_export.php?from=' . urlencode($from) . '&to=' . urlencode($to);

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 no-print">
  <div>
    <h1 class="h3 mb-1">Reports</h1>
    <p class="text-muted mb-0">Sales, COGS, gross profit, expenses, and inventory overview.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
      <i class="bi bi-printer"></i> Print / PDF
    </button>
    <div class="dropdown">
      <button class="btn btn-rice dropdown-toggle" type="button" data-bs-toggle="dropdown">
        <i class="bi bi-download"></i> Export Excel (CSV)
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=summary">Summary</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=gross_profit">Gross Profit</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=net_income">Net Income</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=profit_by_variety">Profit by Variety</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=sales">Sales</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=expenses">Expenses</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=top_products">Top Selling Rice</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=top_grocery">Top Selling Other Items</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=inventory_movement">Inventory Movement</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=stock_forecast">Stock Forecast</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=inventory_value">Inventory Value</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=inventory">Inventory</a></li>
      </ul>
    </div>
  </div>
</div>

<form class="row g-2 mb-4 no-print" method="GET" action="reports.php">
  <div class="col-md-3">
    <label class="form-label small mb-1">From</label>
    <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label small mb-1">To</label>
    <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label small mb-1">Sales period</label>
    <select name="period" class="form-select">
      <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
      <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
      <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
    </select>
  </div>
  <div class="col-md-3 d-flex align-items-end">
    <button type="submit" class="btn btn-outline-secondary w-100">Apply</button>
  </div>
</form>

<p class="small text-muted mb-3">
  Showing <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?>
  (<?= htmlspecialchars(ucfirst($period)) ?> sales view)
</p>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Sales</div>
      <div class="fs-4 fw-bold text-success">₱<?= number_format($salesTotal, 2) ?></div>
      <div class="small text-muted"><?= $saleCount ?> transaction(s)</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Cost of Goods Sold</div>
      <div class="fs-4 fw-bold">₱<?= number_format($cogsTotal, 2) ?></div>
      <div class="small text-muted">Qty sold × buying price</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Gross Profit</div>
      <div class="fs-4 fw-bold <?= $grossProfit >= 0 ? 'text-success' : 'text-danger' ?>">
        ₱<?= number_format($grossProfit, 2) ?>
      </div>
      <div class="small text-muted">Sales − COGS</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Gross Margin</div>
      <div class="fs-4 fw-bold <?= $grossMargin >= 0 ? 'text-success' : 'text-danger' ?>">
        <?= number_format($grossMargin, 1) ?>%
      </div>
      <div class="small text-muted"><?= $lowStockCount ?> low-stock product(s)</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Purchases (period)</div>
      <div class="fs-5 fw-bold">₱<?= number_format($purchaseTotal, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Expenses</div>
      <div class="fs-5 fw-bold text-danger">₱<?= number_format($expenseTotal, 2) ?></div>
      <?php if ($ownerInvestmentTotal > 0): ?>
        <div class="small text-muted">
          Includes ₱<?= number_format($ownerInvestmentTotal, 2) ?> owner investment
          (personal purchases)
        </div>
      <?php endif; ?>
      <?php if ($operatingExpensesTotal > 0 && $ownerInvestmentTotal > 0): ?>
        <div class="small text-muted">Operating: ₱<?= number_format($operatingExpensesTotal, 2) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Net Income</div>
      <div class="fs-5 fw-bold <?= $netIncome >= 0 ? 'text-success' : 'text-danger' ?>">
        ₱<?= number_format($netIncome, 2) ?>
      </div>
      <div class="small text-muted">Sales − COGS − Expenses</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Avg daily net</div>
      <div class="fs-5 fw-bold <?= $avgDailyNet >= 0 ? 'text-success' : 'text-danger' ?>">
        ₱<?= number_format($avgDailyNet, 2) ?>
      </div>
      <div class="small text-muted">
        <?= $positiveNetDays ?> profitable / <?= $negativeNetDays ?> loss day(s)
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="bg-white rounded shadow-sm p-3">
      <h2 class="h6 mb-1">Daily Net Income</h2>
      <p class="small text-muted mb-3">
        Money earned after product costs and operating expenses (Sales − COGS − Expenses)
      </p>
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Best profit day</div>
            <?php if ($bestNetDay): ?>
              <div class="fw-semibold"><?= htmlspecialchars($bestNetDay['date']) ?></div>
              <div class="fs-4 fw-bold text-success">₱<?= number_format((float) $bestNetDay['net_income'], 2) ?></div>
              <div class="small text-muted">
                Sales ₱<?= number_format((float) $bestNetDay['sales'], 2) ?>
                · COGS ₱<?= number_format((float) $bestNetDay['cogs'], 2) ?>
                · Expenses ₱<?= number_format((float) $bestNetDay['expenses'], 2) ?>
              </div>
            <?php else: ?>
              <div class="text-muted">No activity in this range.</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-md-6">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Worst profit day</div>
            <?php if ($worstNetDay): ?>
              <div class="fw-semibold"><?= htmlspecialchars($worstNetDay['date']) ?></div>
              <div class="fs-4 fw-bold <?= $worstNetDay['net_income'] >= 0 ? 'text-success' : 'text-danger' ?>">
                ₱<?= number_format((float) $worstNetDay['net_income'], 2) ?>
              </div>
              <div class="small text-muted">
                Sales ₱<?= number_format((float) $worstNetDay['sales'], 2) ?>
                · COGS ₱<?= number_format((float) $worstNetDay['cogs'], 2) ?>
                · Expenses ₱<?= number_format((float) $worstNetDay['expenses'], 2) ?>
              </div>
            <?php else: ?>
              <div class="text-muted">No activity in this range.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-4">
          <h3 class="h6">Daily trend</h3>
          <?php if (count($dailyNetIncome) === 0): ?>
            <p class="text-muted mb-0">No sales or expenses in this period.</p>
          <?php else: ?>
            <canvas id="dailyNetChart" height="160"></canvas>
          <?php endif; ?>
        </div>
        <div class="col-lg-4">
          <h3 class="h6">Weekly trend</h3>
          <?php if (count($weeklyNetIncome) === 0): ?>
            <p class="text-muted mb-0">No weekly data.</p>
          <?php else: ?>
            <canvas id="weeklyNetChart" height="160"></canvas>
          <?php endif; ?>
        </div>
        <div class="col-lg-4">
          <h3 class="h6">Monthly trend</h3>
          <?php if (count($monthlyNetIncome) === 0): ?>
            <p class="text-muted mb-0">No monthly data.</p>
          <?php else: ?>
            <canvas id="monthlyNetChart" height="160"></canvas>
          <?php endif; ?>
        </div>
      </div>

      <?php if (count($dailyNetIncome) > 0): ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th class="text-end">Sales</th>
                <th class="text-end">COGS</th>
                <th class="text-end">Gross Profit</th>
                <th class="text-end">Expenses</th>
                <th class="text-end">Net Income</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (array_reverse($dailyNetIncome) as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['date']) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['sales'], 2) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['cogs'], 2) ?></td>
                  <td class="text-end <?= $row['gross_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                    ₱<?= number_format((float) $row['gross_profit'], 2) ?>
                  </td>
                  <td class="text-end text-danger">₱<?= number_format((float) $row['expenses'], 2) ?></td>
                  <td class="text-end fw-semibold <?= $row['net_income'] >= 0 ? 'text-success' : 'text-danger' ?>">
                    ₱<?= number_format((float) $row['net_income'], 2) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="fw-semibold">
                <td>Total</td>
                <td class="text-end">₱<?= number_format($salesTotal, 2) ?></td>
                <td class="text-end">₱<?= number_format($cogsTotal, 2) ?></td>
                <td class="text-end <?= $grossProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                  ₱<?= number_format($grossProfit, 2) ?>
                </td>
                <td class="text-end text-danger">₱<?= number_format($expenseTotal, 2) ?></td>
                <td class="text-end <?= $netIncome >= 0 ? 'text-success' : 'text-danger' ?>">
                  ₱<?= number_format($netIncome, 2) ?>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-1">Gross Profit Trend</h2>
      <p class="small text-muted mb-3"><?= htmlspecialchars(ucfirst($period)) ?> sales, COGS, and gross profit</p>
      <?php if (count($grossProfitTrend) === 0): ?>
        <p class="text-muted mb-0">No sales in this period.</p>
      <?php else: ?>
        <canvas id="grossProfitChart" height="140"></canvas>
        <div class="table-responsive mt-3">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Period</th>
                <th class="text-end">Sales</th>
                <th class="text-end">COGS</th>
                <th class="text-end">Gross Profit</th>
                <th class="text-end">Margin</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grossProfitTrend as $row): ?>
                <?php
                  $rowSales = (float) $row['sales_amount'];
                  $rowCogs = (float) $row['cogs'];
                  $rowGp = $rowSales - $rowCogs;
                  $rowMargin = $rowSales > 0 ? ($rowGp / $rowSales) * 100 : 0.0;
                ?>
                <tr>
                  <td><?= htmlspecialchars($row['period_label']) ?></td>
                  <td class="text-end">₱<?= number_format($rowSales, 2) ?></td>
                  <td class="text-end">₱<?= number_format($rowCogs, 2) ?></td>
                  <td class="text-end <?= $rowGp >= 0 ? 'text-success' : 'text-danger' ?>">
                    ₱<?= number_format($rowGp, 2) ?>
                  </td>
                  <td class="text-end"><?= number_format($rowMargin, 1) ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-3">Month vs Previous Month</h2>
      <div class="table-responsive">
        <table class="table table-sm mb-3">
          <thead>
            <tr>
              <th></th>
              <th class="text-end"><?= htmlspecialchars(date('M Y', strtotime($previousMonthStart))) ?></th>
              <th class="text-end"><?= htmlspecialchars(date('M Y')) ?> (MTD)</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Sales</td>
              <td class="text-end">₱<?= number_format($prevMonthSales, 2) ?></td>
              <td class="text-end">₱<?= number_format($curMonthSales, 2) ?></td>
            </tr>
            <tr>
              <td>COGS</td>
              <td class="text-end">₱<?= number_format($prevMonthCogs, 2) ?></td>
              <td class="text-end">₱<?= number_format($curMonthCogs, 2) ?></td>
            </tr>
            <tr>
              <td>Gross Profit</td>
              <td class="text-end <?= $prevMonthGp >= 0 ? 'text-success' : 'text-danger' ?>">
                ₱<?= number_format($prevMonthGp, 2) ?>
              </td>
              <td class="text-end <?= $curMonthGp >= 0 ? 'text-success' : 'text-danger' ?>">
                ₱<?= number_format($curMonthGp, 2) ?>
              </td>
            </tr>
            <tr>
              <td>Margin</td>
              <td class="text-end"><?= number_format($prevMonthMargin, 1) ?>%</td>
              <td class="text-end"><?= number_format($curMonthMargin, 1) ?>%</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="border-top pt-3">
        <div class="text-muted small">Gross profit change</div>
        <div class="fs-5 fw-bold <?= $gpMomChange >= 0 ? 'text-success' : 'text-danger' ?>">
          <?= $gpMomChange >= 0 ? '+' : '' ?>₱<?= number_format($gpMomChange, 2) ?>
          <span class="fs-6 fw-normal">(<?= $gpMomChange >= 0 ? '+' : '' ?><?= number_format($gpMomPercent, 1) ?>%)</span>
        </div>
      </div>
      <?php if ($highestGpDay): ?>
        <?php
          $bestSales = (float) $highestGpDay['sales_amount'];
          $bestCogs = (float) $highestGpDay['cogs'];
          $bestGp = $bestSales - $bestCogs;
        ?>
        <div class="border-top pt-3 mt-3">
          <div class="text-muted small">Highest gross profit day (in range)</div>
          <div class="fw-semibold"><?= htmlspecialchars($highestGpDay['sale_date']) ?></div>
          <div class="small">
            ₱<?= number_format($bestGp, 2) ?>
            <span class="text-muted">
              (Sales ₱<?= number_format($bestSales, 2) ?> − COGS ₱<?= number_format($bestCogs, 2) ?>)
            </span>
          </div>
        </div>
      <?php else: ?>
        <div class="border-top pt-3 mt-3">
          <div class="text-muted small">Highest gross profit day</div>
          <div class="text-muted">No sales in this period.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-3"><?= htmlspecialchars(ucfirst($period)) ?> Sales</h2>
      <?php if (count($salesTrend) === 0): ?>
        <p class="text-muted mb-0">No sales in this period.</p>
      <?php else: ?>
        <canvas id="salesChart" height="140"></canvas>
        <div class="table-responsive mt-3">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Period</th>
                <th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($salesTrend as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['period_label']) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['total'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-3">Expenses by Category</h2>
      <?php if (count($expensesByCategory) === 0): ?>
        <p class="text-muted mb-0">No expenses in this period.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Category</th>
                <th class="text-end">Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($expensesByCategory as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['category']) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['total'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-1">Profit per Rice Variety</h2>
      <p class="small text-muted mb-3">Ranked by gross profit (Sales − COGS), not volume</p>
      <?php if (count($profitByVariety) === 0): ?>
        <p class="text-muted mb-0">No product sales in this period.</p>
      <?php else: ?>
        <canvas id="varietyProfitChart" height="120"></canvas>
        <div class="table-responsive mt-3">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>#</th>
                <th>Product</th>
                <th class="text-end">Qty (kg)</th>
                <th class="text-end">Sales</th>
                <th class="text-end">COGS</th>
                <th class="text-end">Gross Profit</th>
                <th class="text-end">Margin</th>
                <th class="text-end">₱/kg</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($profitByVariety as $i => $row): ?>
                <tr>
                  <td class="text-muted"><?= $i + 1 ?></td>
                  <td>
                    <div class="fw-semibold"><?= htmlspecialchars($row['name']) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($row['category']) ?></div>
                  </td>
                  <td class="text-end"><?= number_format((float) $row['qty_sold'], 2) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['sales_amount'], 2) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['cogs'], 2) ?></td>
                  <td class="text-end fw-semibold <?= $row['gross_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                    ₱<?= number_format((float) $row['gross_profit'], 2) ?>
                  </td>
                  <td class="text-end"><?= number_format((float) $row['margin'], 1) ?>%</td>
                  <td class="text-end">₱<?= number_format((float) $row['profit_per_kg'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="bg-white rounded shadow-sm p-3 mb-3">
      <div class="text-muted small">Most profitable variety</div>
      <?php if ($topProfitProduct): ?>
        <div class="fw-semibold fs-5"><?= htmlspecialchars($topProfitProduct['name']) ?></div>
        <div class="fs-4 fw-bold text-success">₱<?= number_format((float) $topProfitProduct['gross_profit'], 2) ?></div>
        <div class="small text-muted">
          <?= number_format((float) $topProfitProduct['qty_sold'], 2) ?> kg sold ·
          <?= number_format((float) $topProfitProduct['margin'], 1) ?>% margin
        </div>
      <?php else: ?>
        <div class="text-muted">No sales in this period.</div>
      <?php endif; ?>
    </div>
    <div class="bg-white rounded shadow-sm p-3 mb-3">
      <div class="text-muted small">Highest margin variety</div>
      <?php if ($topMarginProduct): ?>
        <div class="fw-semibold fs-5"><?= htmlspecialchars($topMarginProduct['name']) ?></div>
        <div class="fs-4 fw-bold text-success"><?= number_format((float) $topMarginProduct['margin'], 1) ?>%</div>
        <div class="small text-muted">
          ₱<?= number_format((float) $topMarginProduct['profit_per_kg'], 2) ?> profit/kg ·
          stock <?= number_format((float) $topMarginProduct['stock'], 2) ?> kg
        </div>
      <?php else: ?>
        <div class="text-muted">No sales in this period.</div>
      <?php endif; ?>
    </div>
    <div class="bg-white rounded shadow-sm p-3">
      <h2 class="h6 mb-3">Top Selling Rice (by volume)</h2>
      <?php if (count($topProducts) === 0): ?>
        <p class="text-muted mb-0">No product sales in this period.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Sales</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($topProducts as $row): ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars($row['name']) ?></td>
                  <td class="text-end"><?= number_format((float) $row['qty_sold'], 2) ?> kg</td>
                  <td class="text-end">₱<?= number_format((float) $row['sales_amount'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="small text-muted mb-0 mt-2">Volume leaders may differ from profit leaders.</p>
      <?php endif; ?>
    </div>
    <div class="bg-white rounded shadow-sm p-3 mt-3">
      <h2 class="h6 mb-3">Top Selling Other Items (by volume)</h2>
      <?php if (count($topOtherProducts) === 0): ?>
        <p class="text-muted mb-0">No other-item sales in this period.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Sales</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($topOtherProducts as $row): ?>
                <?php $unit = $row['unit'] ?? 'pc'; ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars($row['name']) ?></td>
                  <td class="text-end">
                    <?= number_format((float) $row['qty_sold'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
                  </td>
                  <td class="text-end">₱<?= number_format((float) $row['sales_amount'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-1">Profit per Other Item</h2>
      <p class="small text-muted mb-3">Grocery and non-rice products in the selected period</p>
      <?php if (count($profitByGrocery) === 0): ?>
        <p class="text-muted mb-0">No other-item sales in this period.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>#</th>
                <th>Product</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Sales</th>
                <th class="text-end">COGS</th>
                <th class="text-end">Gross Profit</th>
                <th class="text-end">Margin</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($profitByGrocery as $i => $row): ?>
                <?php $unit = $row['unit'] ?? 'pc'; ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td>
                    <div class="fw-semibold"><?= htmlspecialchars($row['name']) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($row['category']) ?></div>
                  </td>
                  <td class="text-end">
                    <?= number_format((float) $row['qty_sold'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
                  </td>
                  <td class="text-end">₱<?= number_format((float) $row['sales_amount'], 2) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['cogs'], 2) ?></td>
                  <td class="text-end text-success fw-semibold">₱<?= number_format((float) $row['gross_profit'], 2) ?></td>
                  <td class="text-end"><?= number_format((float) $row['margin'], 1) ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="bg-white rounded shadow-sm p-3 mb-3">
      <div class="text-muted small">Most profitable other item</div>
      <?php if ($topProfitGrocery): ?>
        <?php $gpUnit = $topProfitGrocery['unit'] ?? 'pc'; ?>
        <div class="fw-semibold fs-5"><?= htmlspecialchars($topProfitGrocery['name']) ?></div>
        <div class="fs-4 fw-bold text-success">₱<?= number_format((float) $topProfitGrocery['gross_profit'], 2) ?></div>
        <div class="small text-muted">
          <?= number_format((float) $topProfitGrocery['qty_sold'], $gpUnit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($gpUnit) ?> sold ·
          <?= number_format((float) $topProfitGrocery['margin'], 1) ?>% margin
        </div>
      <?php else: ?>
        <div class="text-muted">No other-item sales in this period.</div>
      <?php endif; ?>
    </div>
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-3">Other Items — Current Stock</h2>
      <?php if (count($groceryStock) === 0): ?>
        <p class="text-muted mb-0">No active other items in inventory.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-end">Stock</th>
                <th class="text-end">Sell</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($groceryStock as $item): ?>
                <?php
                  $unit = $item['unit'] ?? 'pc';
                  $isLow = (float) $item['stock'] <= (float) $item['minimum_stock'];
                ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= htmlspecialchars($item['name']) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($item['category']) ?></div>
                  </td>
                  <td class="text-end <?= $isLow ? 'text-danger fw-semibold' : '' ?>">
                    <?= number_format((float) $item['stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
                  </td>
                  <td class="text-end">₱<?= number_format((float) $item['selling_price'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="bg-white rounded shadow-sm p-3">
      <h2 class="h6 mb-1">Fastest vs Slowest Selling Rice</h2>
      <p class="small text-muted mb-3">
        Inventory movement for the last <?= (int) $lookbackDays ?> days
        (<?= htmlspecialchars($movementFrom) ?> to <?= htmlspecialchars($movementTo) ?>)
      </p>
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Fast movers (with sales)</div>
            <div class="fs-4 fw-bold text-success"><?= count($fastestSelling) ?></div>
            <div class="small text-muted">Top products by kg sold</div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">No sales (30 days)</div>
            <div class="fs-4 fw-bold text-warning"><?= count($noSales30) ?></div>
            <div class="small text-muted">Active products idle</div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Excessive inventory</div>
            <div class="fs-4 fw-bold text-danger"><?= count($excessiveInventory) ?></div>
            <div class="small text-muted">Overstock / slow cover risk</div>
          </div>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-lg-6">
          <h3 class="h6">Top 10 fastest-selling</h3>
          <?php if (count($fastestSelling) === 0): ?>
            <p class="text-muted mb-0">No product sales in the last 30 days.</p>
          <?php else: ?>
            <canvas id="fastSellingChart" height="160"></canvas>
            <div class="table-responsive mt-3">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th class="text-end">Sold (kg)</th>
                    <th class="text-end">Avg/day</th>
                    <th class="text-end">Stock</th>
                    <th class="text-end">Days cover</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($fastestSelling as $i => $row): ?>
                    <?php
                      $daysSupply = $row['days_supply'];
                      $coverLabel = $daysSupply === null ? '—' : number_format((float) $daysSupply, 0);
                    ?>
                    <tr>
                      <td class="text-muted"><?= $i + 1 ?></td>
                      <td>
                        <div class="fw-semibold"><?= htmlspecialchars($row['name']) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($row['category']) ?></div>
                      </td>
                      <td class="text-end text-success fw-semibold">
                        <?= number_format((float) $row['qty_sold'], 2) ?>
                      </td>
                      <td class="text-end"><?= number_format((float) $row['avg_daily'], 2) ?></td>
                      <td class="text-end"><?= number_format((float) $row['stock'], 2) ?></td>
                      <td class="text-end"><?= $coverLabel ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-lg-6">
          <h3 class="h6">Slowest-moving</h3>
          <?php if (count($slowestSelling) === 0): ?>
            <p class="text-muted mb-0">No active products to rank.</p>
          <?php else: ?>
            <canvas id="slowSellingChart" height="160"></canvas>
            <div class="table-responsive mt-3">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th class="text-end">Sold (kg)</th>
                    <th class="text-end">Stock</th>
                    <th class="text-end">Days cover</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($slowestSelling as $i => $row): ?>
                    <?php
                      $daysSupply = $row['days_supply'];
                      $isIdle = (float) $row['qty_sold'] <= 0;
                      $coverLabel = $daysSupply === null
                          ? ($isIdle ? 'No sales' : '—')
                          : number_format((float) $daysSupply, 0);
                    ?>
                    <tr>
                      <td class="text-muted"><?= $i + 1 ?></td>
                      <td>
                        <div class="fw-semibold"><?= htmlspecialchars($row['name']) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($row['category']) ?></div>
                      </td>
                      <td class="text-end <?= $isIdle ? 'text-warning' : '' ?>">
                        <?= number_format((float) $row['qty_sold'], 2) ?>
                      </td>
                      <td class="text-end"><?= number_format((float) $row['stock'], 2) ?></td>
                      <td class="text-end <?= $isIdle ? 'text-warning' : '' ?>"><?= $coverLabel ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-3">No Sales in Last 30 Days</h2>
      <?php if (count($noSales30) === 0): ?>
        <p class="text-muted mb-0">Every active product sold at least once in the last 30 days.</p>
      <?php else: ?>
        <?php usort($noSales30, static fn ($a, $b) => (float) $b['stock'] <=> (float) $a['stock']); ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-end">Stock (kg)</th>
                <th class="text-end">Min</th>
                <th class="text-end">Tied capital</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($noSales30 as $row): ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= htmlspecialchars($row['name']) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($row['category']) ?></div>
                  </td>
                  <td class="text-end"><?= number_format((float) $row['stock'], 2) ?></td>
                  <td class="text-end"><?= number_format((float) $row['minimum_stock'], 2) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['tied_capital'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-3">Excessive Inventory</h2>
      <p class="small text-muted mb-3">
        Stock ≥ 3× minimum, idle stock above minimum, or ≥ 90 days of cover
      </p>
      <?php if (count($excessiveInventory) === 0): ?>
        <p class="text-muted mb-0">No overstock alerts right now.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-end">Stock</th>
                <th class="text-end">Sold (30d)</th>
                <th class="text-end">Days cover</th>
                <th class="text-end">Tied capital</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($excessiveInventory as $row): ?>
                <?php
                  $daysSupply = $row['days_supply'];
                  $coverLabel = $daysSupply === null ? 'No sales' : number_format((float) $daysSupply, 0);
                ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= htmlspecialchars($row['name']) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($row['category']) ?></div>
                  </td>
                  <td class="text-end text-danger fw-semibold">
                    <?= number_format((float) $row['stock'], 2) ?>
                  </td>
                  <td class="text-end"><?= number_format((float) $row['qty_sold'], 2) ?></td>
                  <td class="text-end"><?= $coverLabel ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['tied_capital'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="bg-white rounded shadow-sm p-3">
      <h2 class="h6 mb-1">Low Stock Forecast</h2>
      <p class="small text-muted mb-3">
        Estimated stockout based on average daily sales over the last <?= (int) $lookbackDays ?> days
        — not only the minimum stock threshold
      </p>
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">At risk (≤<?= (int) $forecastHorizonDays ?> days)</div>
            <div class="fs-4 fw-bold <?= $forecastAtRiskCount > 0 ? 'text-danger' : 'text-success' ?>">
              <?= (int) $forecastAtRiskCount ?>
            </div>
            <div class="small text-muted">Products forecasted to run low</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Critical (≤7 days / out)</div>
            <div class="fs-4 fw-bold text-danger"><?= (int) ($forecastOutNow + $forecastCritical) ?></div>
            <div class="small text-muted">Order now</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Warning (8–14 days)</div>
            <div class="fs-4 fw-bold text-warning"><?= (int) $forecastWarning ?></div>
            <div class="small text-muted">Plan purchase soon</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Cannot forecast</div>
            <div class="fs-4 fw-bold"><?= count($forecastCannot) ?></div>
            <div class="small text-muted">No sales in <?= (int) $lookbackDays ?> days</div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-7">
          <h3 class="h6">Soonest to run out</h3>
          <?php if (count($stockForecast) === 0): ?>
            <p class="text-muted mb-0">No products with recent sales to forecast.</p>
          <?php else: ?>
            <canvas id="stockForecastChart" height="150"></canvas>
            <div class="table-responsive mt-3">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th class="text-end">Stock</th>
                    <th class="text-end">Avg/day</th>
                    <th class="text-end">Days left</th>
                    <th>Stockout</th>
                    <th>Hits min</th>
                    <th class="text-end">Suggest order</th>
                    <th>Risk</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($stockForecast as $row): ?>
                    <?php
                      $urgency = $row['urgency'];
                      $badge = match ($urgency) {
                          'out' => '<span class="badge text-bg-danger">Out</span>',
                          'critical' => '<span class="badge text-bg-danger">Critical</span>',
                          'warning' => '<span class="badge text-bg-warning">Warning</span>',
                          'watch' => '<span class="badge text-bg-info">Watch</span>',
                          default => '<span class="badge text-bg-success">OK</span>',
                      };
                      $daysClass = in_array($urgency, ['out', 'critical'], true)
                          ? 'text-danger fw-semibold'
                          : ($urgency === 'warning' ? 'text-warning fw-semibold' : '');
                      $belowMin = (float) $row['stock'] <= (float) $row['minimum_stock'];
                    ?>
                    <tr>
                      <td>
                        <div class="fw-semibold"><?= htmlspecialchars($row['name']) ?></div>
                        <div class="small text-muted">
                          <?= htmlspecialchars($row['category']) ?>
                          · min <?= number_format((float) $row['minimum_stock'], 2) ?> kg
                          <?php if ($belowMin): ?>
                            <span class="text-warning">(at/below min)</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td class="text-end"><?= number_format((float) $row['stock'], 2) ?></td>
                      <td class="text-end"><?= number_format((float) $row['avg_daily'], 2) ?></td>
                      <td class="text-end <?= $daysClass ?>">
                        <?= number_format((float) $row['days_to_empty'], 1) ?>
                      </td>
                      <td class="<?= $daysClass ?>"><?= htmlspecialchars($row['stockout_date']) ?></td>
                      <td>
                        <?php if ((float) $row['stock'] <= (float) $row['minimum_stock']): ?>
                          <span class="text-muted">Already</span>
                        <?php else: ?>
                          <?= htmlspecialchars($row['min_hit_date']) ?>
                          <div class="small text-muted"><?= number_format((float) $row['days_to_min'], 1) ?> days</div>
                        <?php endif; ?>
                      </td>
                      <td class="text-end">
                        <?php if ((float) $row['suggested_reorder_kg'] > 0.01): ?>
                          <?= number_format((float) $row['suggested_reorder_kg'], 2) ?> kg
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                      <td><?= $badge ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-lg-5">
          <h3 class="h6 mb-3">Priority reorder list</h3>
          <?php if (count($forecastUrgent) === 0): ?>
            <p class="text-muted mb-3">Nothing critical or warning in the next 14 days.</p>
          <?php else: ?>
            <div class="table-responsive mb-3">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th class="text-end">Days</th>
                    <th>Stockout</th>
                    <th class="text-end">Order</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($forecastUrgent as $row): ?>
                    <tr>
                      <td class="fw-semibold"><?= htmlspecialchars($row['name']) ?></td>
                      <td class="text-end text-danger fw-semibold">
                        <?= number_format((float) $row['days_to_empty'], 1) ?>
                      </td>
                      <td><?= htmlspecialchars($row['stockout_date']) ?></td>
                      <td class="text-end">
                        <?= number_format((float) $row['suggested_reorder_kg'], 2) ?> kg
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if (count($forecastWatch) > 0): ?>
            <h3 class="h6 mb-2">Watch (15–<?= (int) $forecastHorizonDays ?> days)</h3>
            <ul class="list-unstyled small mb-3">
              <?php foreach ($forecastWatch as $row): ?>
                <li class="mb-1">
                  <span class="fw-semibold"><?= htmlspecialchars($row['name']) ?></span>
                  — <?= number_format((float) $row['days_to_empty'], 1) ?> days
                  (<?= htmlspecialchars($row['stockout_date']) ?>)
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if (count($forecastCannot) > 0): ?>
            <h3 class="h6 mb-2">No velocity data</h3>
            <p class="small text-muted mb-2">
              These active products had no sales in <?= (int) $lookbackDays ?> days, so a stockout date cannot be estimated. Rely on minimum stock instead.
            </p>
            <ul class="list-unstyled small mb-0">
              <?php foreach ($forecastCannot as $row): ?>
                <li class="mb-1">
                  <?= htmlspecialchars($row['name']) ?>
                  <span class="text-muted">
                    — stock <?= number_format((float) $row['stock'], 2) ?> kg
                    / min <?= number_format((float) $row['minimum_stock'], 2) ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="bg-white rounded shadow-sm p-3">
      <h2 class="h6 mb-1">Inventory Value</h2>
      <p class="small text-muted mb-3">
        Capital tied in stock (buying cost) vs estimated selling value
      </p>
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Cost value (capital)</div>
            <div class="fs-4 fw-bold">₱<?= number_format($inventoryCostValue, 2) ?></div>
            <div class="small text-muted">
              <?= number_format($inventoryRiceKg, 2) ?> kg rice · <?= $inventoryGroceryCount ?> other item(s)
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Selling value</div>
            <div class="fs-4 fw-bold text-success">₱<?= number_format($inventorySellValue, 2) ?></div>
            <div class="small text-muted"><?= $inventoryActiveCount ?> active product(s)</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Potential gross profit</div>
            <div class="fs-4 fw-bold <?= $inventoryPotentialProfit >= 0 ? 'text-success' : 'text-danger' ?>">
              ₱<?= number_format($inventoryPotentialProfit, 2) ?>
            </div>
            <div class="small text-muted">If all stock sells at list price</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Potential margin</div>
            <div class="fs-4 fw-bold"><?= number_format($inventoryValueMargin, 1) ?>%</div>
            <div class="small text-muted"><?= $lowStockCount ?> low-stock product(s)</div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-7">
          <?php if (count($valueChartLabels) === 0): ?>
            <p class="text-muted mb-3">No stock on hand to chart.</p>
          <?php else: ?>
            <h3 class="h6">Top products by inventory value</h3>
            <canvas id="inventoryValueChart" height="140"></canvas>
          <?php endif; ?>
          <div class="table-responsive mt-3">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Type</th>
                  <th class="text-end">Stock</th>
                  <th class="text-end">Buy</th>
                  <th class="text-end">Sell</th>
                  <th class="text-end">Cost value</th>
                  <th class="text-end">Sell value</th>
                  <th class="text-end">Potential GP</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($inventory) === 0): ?>
                  <tr>
                    <td colspan="9" class="text-muted text-center">No products.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($inventory as $item): ?>
                    <?php
                      $unit = $item['unit'] ?? 'kg';
                      $isLow = (float) $item['stock'] <= (float) $item['minimum_stock'];
                      $isGrocery = ($item['product_type'] ?? 'RICE') === 'GROCERY';
                    ?>
                    <tr>
                      <td>
                        <div class="fw-semibold"><?= htmlspecialchars($item['name']) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($item['category']) ?></div>
                      </td>
                      <td>
                        <?php if ($isGrocery): ?>
                          <span class="badge text-bg-info">Other</span>
                        <?php else: ?>
                          <span class="badge text-bg-primary">Rice</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end <?= $isLow ? 'text-danger fw-semibold' : '' ?>">
                        <?= number_format((float) $item['stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
                      </td>
                      <td class="text-end">₱<?= number_format((float) $item['buying_price'], 2) ?></td>
                      <td class="text-end">₱<?= number_format((float) $item['selling_price'], 2) ?></td>
                      <td class="text-end">₱<?= number_format((float) $item['cost_value'], 2) ?></td>
                      <td class="text-end">₱<?= number_format((float) $item['sell_value'], 2) ?></td>
                      <td class="text-end <?= $item['potential_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                        ₱<?= number_format((float) $item['potential_profit'], 2) ?>
                      </td>
                      <td>
                        <?php if ($isLow): ?>
                          <span class="badge text-bg-warning">Low</span>
                        <?php elseif ($item['status'] === 'active'): ?>
                          <span class="badge text-bg-success">OK</span>
                        <?php else: ?>
                          <span class="badge text-bg-secondary">Inactive</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
              <?php if (count($inventory) > 0): ?>
                <tfoot>
                  <tr class="fw-semibold">
                    <td colspan="2">Total</td>
                    <td class="text-end">—</td>
                    <td></td>
                    <td></td>
                    <td class="text-end">₱<?= number_format($inventoryCostValue, 2) ?></td>
                    <td class="text-end">₱<?= number_format($inventorySellValue, 2) ?></td>
                    <td class="text-end <?= $inventoryPotentialProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                      ₱<?= number_format($inventoryPotentialProfit, 2) ?>
                    </td>
                    <td></td>
                  </tr>
                </tfoot>
              <?php endif; ?>
            </table>
          </div>
        </div>
        <div class="col-lg-5">
          <h3 class="h6 mb-3">Value by Category</h3>
          <?php if (count($inventoryValueByCategory) === 0): ?>
            <p class="text-muted mb-0">No products.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Category</th>
                    <th class="text-end">Products</th>
                    <th class="text-end">Cost value</th>
                    <th class="text-end">Sell value</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($inventoryValueByCategory as $category => $data): ?>
                    <tr>
                      <td class="fw-semibold"><?= htmlspecialchars($category) ?></td>
                      <td class="text-end"><?= (int) $data['products'] ?></td>
                      <td class="text-end">₱<?= number_format((float) $data['cost_value'], 2) ?></td>
                      <td class="text-end">₱<?= number_format((float) $data['sell_value'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const pesoTick = {
    beginAtZero: true,
    ticks: {
      callback: function (value) {
        return '₱' + Number(value).toLocaleString();
      }
    }
  };

  const salesCanvas = document.getElementById('salesChart');
  if (salesCanvas) {
    new Chart(salesCanvas, {
      type: 'bar',
      data: {
        labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
          label: 'Sales (₱)',
          data: <?= json_encode($chartValues) ?>,
          backgroundColor: 'rgba(45, 106, 79, 0.75)',
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false }
        },
        scales: {
          y: pesoTick
        }
      }
    });
  }

  const gpCanvas = document.getElementById('grossProfitChart');
  if (gpCanvas) {
    new Chart(gpCanvas, {
      type: 'bar',
      data: {
        labels: <?= json_encode($gpChartLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [
          {
            label: 'Sales',
            data: <?= json_encode($gpChartSales) ?>,
            backgroundColor: 'rgba(45, 106, 79, 0.75)',
            borderRadius: 4
          },
          {
            label: 'COGS',
            data: <?= json_encode($gpChartCogs) ?>,
            backgroundColor: 'rgba(201, 162, 39, 0.75)',
            borderRadius: 4
          },
          {
            label: 'Gross Profit',
            data: <?= json_encode($gpChartProfit) ?>,
            type: 'line',
            borderColor: 'rgba(27, 67, 50, 0.95)',
            backgroundColor: 'rgba(27, 67, 50, 0.15)',
            borderWidth: 2,
            tension: 0.25,
            pointRadius: 3,
            fill: false
          }
        ]
      },
      options: {
        responsive: true,
        interaction: {
          mode: 'index',
          intersect: false
        },
        plugins: {
          legend: { position: 'bottom' }
        },
        scales: {
          y: pesoTick
        }
      }
    });
  }

  const varietyCanvas = document.getElementById('varietyProfitChart');
  if (varietyCanvas) {
    new Chart(varietyCanvas, {
      type: 'bar',
      data: {
        labels: <?= json_encode($varietyChartLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [
          {
            label: 'Gross Profit',
            data: <?= json_encode($varietyChartProfit) ?>,
            backgroundColor: 'rgba(45, 106, 79, 0.8)',
            borderRadius: 4
          },
          {
            label: 'Sales',
            data: <?= json_encode($varietyChartSales) ?>,
            backgroundColor: 'rgba(45, 106, 79, 0.3)',
            borderRadius: 4
          }
        ]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        plugins: {
          legend: { position: 'bottom' }
        },
        scales: {
          x: pesoTick
        }
      }
    });
  }

  const kgTick = {
    beginAtZero: true,
    ticks: {
      callback: function (value) {
        return Number(value).toLocaleString() + ' kg';
      }
    }
  };

  const fastCanvas = document.getElementById('fastSellingChart');
  if (fastCanvas) {
    new Chart(fastCanvas, {
      type: 'bar',
      data: {
        labels: <?= json_encode($fastChartLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
          label: 'Qty sold (kg)',
          data: <?= json_encode($fastChartQty) ?>,
          backgroundColor: 'rgba(45, 106, 79, 0.8)',
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: kgTick }
      }
    });
  }

  const slowCanvas = document.getElementById('slowSellingChart');
  if (slowCanvas) {
    new Chart(slowCanvas, {
      type: 'bar',
      data: {
        labels: <?= json_encode($slowChartLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
          label: 'Qty sold (kg)',
          data: <?= json_encode($slowChartQty) ?>,
          backgroundColor: 'rgba(185, 68, 68, 0.75)',
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: kgTick }
      }
    });
  }

  const inventoryValueCanvas = document.getElementById('inventoryValueChart');
  if (inventoryValueCanvas) {
    new Chart(inventoryValueCanvas, {
      type: 'bar',
      data: {
        labels: <?= json_encode($valueChartLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [
          {
            label: 'Cost value',
            data: <?= json_encode($valueChartCost) ?>,
            backgroundColor: 'rgba(201, 162, 39, 0.8)',
            borderRadius: 4
          },
          {
            label: 'Selling value',
            data: <?= json_encode($valueChartSell) ?>,
            backgroundColor: 'rgba(45, 106, 79, 0.75)',
            borderRadius: 4
          }
        ]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        scales: { x: pesoTick }
      }
    });
  }

  const forecastCanvas = document.getElementById('stockForecastChart');
  if (forecastCanvas) {
    new Chart(forecastCanvas, {
      type: 'bar',
      data: {
        labels: <?= json_encode($forecastChartLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
          label: 'Days until stockout',
          data: <?= json_encode($forecastChartDays) ?>,
          backgroundColor: <?= json_encode(array_map(static function ($days) {
              if ($days <= 7) {
                  return 'rgba(185, 68, 68, 0.85)';
              }
              if ($days <= 14) {
                  return 'rgba(217, 119, 6, 0.8)';
              }
              if ($days <= 30) {
                  return 'rgba(59, 130, 246, 0.75)';
              }
              return 'rgba(45, 106, 79, 0.7)';
          }, $forecastChartDays)) ?>,
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          x: {
            beginAtZero: true,
            title: { display: true, text: 'Days of stock left' }
          }
        }
      }
    });
  }

  function renderNetIncomeChart(canvasId, labels, values, labelText) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    new Chart(canvas, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: labelText,
          data: values,
          backgroundColor: values.map(function (v) {
            return v >= 0 ? 'rgba(45, 106, 79, 0.75)' : 'rgba(185, 68, 68, 0.75)';
          }),
          borderRadius: 4
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: pesoTick }
      }
    });
  }

  renderNetIncomeChart(
    'dailyNetChart',
    <?= json_encode($dailyNetChartLabels, JSON_UNESCAPED_UNICODE) ?>,
    <?= json_encode($dailyNetChartValues) ?>,
    'Daily net income'
  );
  renderNetIncomeChart(
    'weeklyNetChart',
    <?= json_encode($weeklyNetChartLabels, JSON_UNESCAPED_UNICODE) ?>,
    <?= json_encode($weeklyNetChartValues) ?>,
    'Weekly net income'
  );
  renderNetIncomeChart(
    'monthlyNetChart',
    <?= json_encode($monthlyNetChartLabels, JSON_UNESCAPED_UNICODE) ?>,
    <?= json_encode($monthlyNetChartValues) ?>,
    'Monthly net income'
  );
});
</script>

<style>
@media print {
  .app-drawer,
  .app-topbar,
  .drawer-backdrop,
  .no-print {
    display: none !important;
  }

  .app-main {
    margin-left: 0 !important;
  }

  .app-content {
    padding: 0 !important;
  }

  .shadow-sm {
    box-shadow: none !important;
    border: 1px solid #ddd !important;
  }
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
