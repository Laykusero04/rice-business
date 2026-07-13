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

$purchaseTotalStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total), 0) FROM purchases WHERE purchase_date BETWEEN ? AND ?'
);
$purchaseTotalStmt->execute([$from, $to]);
$purchaseTotal = (float) $purchaseTotalStmt->fetchColumn();

$profit = $salesTotal - $expenseTotal;

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

$topProductsStmt = $pdo->prepare(
    'SELECT p.name,
            SUM(si.quantity) AS qty_sold,
            SUM(si.subtotal) AS sales_amount
     FROM sale_items si
     INNER JOIN sales s ON s.id = si.sale_id
     INNER JOIN products p ON p.id = si.product_id
     WHERE s.sale_date BETWEEN ? AND ?
     GROUP BY p.id, p.name
     ORDER BY qty_sold DESC
     LIMIT 10'
);
$topProductsStmt->execute([$from, $to]);
$topProducts = $topProductsStmt->fetchAll();

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
    'SELECT name, category, stock, minimum_stock, buying_price, selling_price, status
     FROM products
     ORDER BY name ASC'
)->fetchAll();

$lowStockCount = 0;
foreach ($inventory as $item) {
    if ((float) $item['stock'] <= (float) $item['minimum_stock']) {
        $lowStockCount++;
    }
}

$chartLabels = [];
$chartValues = [];
foreach ($salesTrend as $row) {
    $chartLabels[] = $row['period_label'];
    $chartValues[] = (float) $row['total'];
}

$exportBase = '/rice-business/backend/report_export.php?from=' . urlencode($from) . '&to=' . urlencode($to);

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 no-print">
  <div>
    <h1 class="h3 mb-1">Reports</h1>
    <p class="text-muted mb-0">Sales, expenses, profit, and inventory overview.</p>
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
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=sales">Sales</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=expenses">Expenses</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>&type=top_products">Top Products</a></li>
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
      <div class="text-muted small">Purchases</div>
      <div class="fs-4 fw-bold">₱<?= number_format($purchaseTotal, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Expenses</div>
      <div class="fs-4 fw-bold text-danger">₱<?= number_format($expenseTotal, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Profit (Sales − Expenses)</div>
      <div class="fs-4 fw-bold <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>">
        ₱<?= number_format($profit, 2) ?>
      </div>
      <div class="small text-muted"><?= $lowStockCount ?> low-stock product(s)</div>
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
  <div class="col-lg-6">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-3">Top Selling Rice</h2>
      <?php if (count($topProducts) === 0): ?>
        <p class="text-muted mb-0">No product sales in this period.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-end">Qty (kg)</th>
                <th class="text-end">Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($topProducts as $row): ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars($row['name']) ?></td>
                  <td class="text-end"><?= number_format((float) $row['qty_sold'], 2) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['sales_amount'], 2) ?></td>
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
      <h2 class="h6 mb-3">Inventory Report</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Product</th>
              <th class="text-end">Stock</th>
              <th class="text-end">Min</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($inventory) === 0): ?>
              <tr>
                <td colspan="4" class="text-muted text-center">No products.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($inventory as $item): ?>
                <?php $isLow = (float) $item['stock'] <= (float) $item['minimum_stock']; ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars($item['name']) ?></td>
                  <td class="text-end <?= $isLow ? 'text-danger fw-semibold' : '' ?>">
                    <?= number_format((float) $item['stock'], 2) ?>
                  </td>
                  <td class="text-end"><?= number_format((float) $item['minimum_stock'], 2) ?></td>
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
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const canvas = document.getElementById('salesChart');
  if (!canvas) return;

  const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
  const values = <?= json_encode($chartValues) ?>;

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Sales (₱)',
        data: values,
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
        y: {
          beginAtZero: true,
          ticks: {
            callback: function (value) {
              return '₱' + Number(value).toLocaleString();
            }
          }
        }
      }
    }
  });
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
