

<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Reports';
$activePage = 'reports';

$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$todayForPresets = date('Y-m-d');
$earliestAll = $pdo->query(
    "SELECT MIN(d) FROM (
        SELECT MIN(sale_date) AS d FROM sales
        UNION ALL
        SELECT MIN(expense_date) FROM expenses
        UNION ALL
        SELECT MIN(purchase_date) FROM purchases
     ) AS dates"
)->fetchColumn();
$allFrom = $earliestAll ?: $todayForPresets;

$datePresets = [
    'week' => [
        'label' => 'Week',
        'from' => date('Y-m-d', strtotime('-6 days')),
        'to' => $todayForPresets,
    ],
    'month' => [
        'label' => 'Month',
        'from' => date('Y-m-01'),
        'to' => $todayForPresets,
    ],
    'year' => [
        'label' => 'Year',
        'from' => date('Y-01-01'),
        'to' => $todayForPresets,
    ],
    'all' => [
        'label' => 'All',
        'from' => $allFrom,
        'to' => $todayForPresets,
    ],
];

$activeDatePreset = null;
foreach ($datePresets as $key => $preset) {
    if ($from === $preset['from'] && $to === $preset['to']) {
        $activeDatePreset = $key;
        break;
    }
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

    $profitByGrocery[$index]['gross_profit'] = $gp;
    $profitByGrocery[$index]['margin'] = $margin;

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

$exportBase = '/rice-business/backend/report_export.php?from=' . urlencode($from) . '&to=' . urlencode($to);

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 no-print">
  <div>
    <h1 class="h3 mb-1">Reports</h1>
    <p class="text-muted mb-0">Sales, COGS, gross profit, expenses, and inventory overview.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="analytics.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-outline-secondary">
      <i class="bi bi-graph-up"></i> Analytics
    </a>
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
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=inventory_value">Inventory Value</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=inventory">Inventory</a></li>
      </ul>
    </div>
  </div>
</div>

<form class="row g-2 mb-2 no-print" method="GET" action="reports.php">
  <div class="col-md-4">
    <label class="form-label small mb-1">From</label>
    <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
  </div>
  <div class="col-md-4">
    <label class="form-label small mb-1">To</label>
    <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
  </div>
  <div class="col-md-4 d-flex align-items-end">
    <button type="submit" class="btn btn-outline-secondary w-100">Apply</button>
  </div>
</form>
<div class="d-flex flex-wrap align-items-center gap-2 mb-4 no-print">
  <span class="small text-muted">Quick:</span>
  <div class="btn-group btn-group-sm" role="group" aria-label="Date range presets">
    <?php foreach ($datePresets as $key => $preset): ?>
      <a href="reports.php?from=<?= urlencode($preset['from']) ?>&to=<?= urlencode($preset['to']) ?>"
         class="btn btn-outline-secondary<?= $activeDatePreset === $key ? ' active' : '' ?>">
        <?= htmlspecialchars($preset['label']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<p class="small text-muted mb-3">
  Showing <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?>
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
      <?php else: ?>
        <p class="text-muted mb-0">No sales or expenses in this period.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
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
  <div class="col-lg-7">
    <div class="bg-white rounded shadow-sm p-3 h-100">
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
        <div class="table-responsive">
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
    <div class="bg-white rounded shadow-sm p-3 h-100">
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
    <div class="bg-white rounded shadow-sm p-3">
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
          <div class="table-responsive">
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
