<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Dashboard';
$activePage = 'dashboard';

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$todaySalesStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total), 0) FROM sales WHERE sale_date = ?'
);
$todaySalesStmt->execute([$today]);
$todaySales = (float) $todaySalesStmt->fetchColumn();

$monthSalesStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total), 0) FROM sales WHERE sale_date BETWEEN ? AND ?'
);
$monthSalesStmt->execute([$monthStart, $today]);
$monthSales = (float) $monthSalesStmt->fetchColumn();

$riceStockKg = (float) $pdo->query(
    "SELECT COALESCE(SUM(stock), 0)
     FROM products
     WHERE status = 'active' AND product_type = 'RICE'"
)->fetchColumn();

$lowStockRice = $pdo->query(
    "SELECT id, name, stock, minimum_stock, unit
     FROM products
     WHERE status = 'active'
       AND product_type = 'RICE'
       AND stock <= minimum_stock
     ORDER BY stock ASC
     LIMIT 8"
)->fetchAll();

$lowStockOther = $pdo->query(
    "SELECT id, name, stock, minimum_stock, unit
     FROM products
     WHERE status = 'active'
       AND product_type = 'GROCERY'
       AND stock <= minimum_stock
     ORDER BY stock ASC
     LIMIT 5"
)->fetchAll();

$otherProductCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM products WHERE status = 'active' AND product_type = 'GROCERY'"
)->fetchColumn();

$otherProductsStock = $pdo->query(
    "SELECT id, name, category, unit, stock, minimum_stock, selling_price
     FROM products
     WHERE status = 'active' AND product_type = 'GROCERY'
     ORDER BY name ASC
     LIMIT 10"
)->fetchAll();

$topOtherMonthStmt = $pdo->prepare(
    "SELECT p.name, p.unit,
            SUM(si.quantity) AS qty_sold,
            SUM(si.subtotal) AS sales_amount
     FROM sale_items si
     INNER JOIN sales s ON s.id = si.sale_id
     INNER JOIN products p ON p.id = si.product_id
     WHERE s.sale_date BETWEEN ? AND ?
       AND p.product_type = 'GROCERY'
     GROUP BY p.id, p.name, p.unit
     ORDER BY qty_sold DESC
     LIMIT 5"
);
$topOtherMonthStmt->execute([$monthStart, $today]);
$topOtherMonth = $topOtherMonthStmt->fetchAll();

$recentSales = $pdo->query(
    "SELECT s.id, s.sale_date, s.total, s.payment_method,
            COALESCE(c.name, 'Walk-in / Just buying') AS customer_name
     FROM sales s
     LEFT JOIN customers c ON c.id = s.customer_id
     ORDER BY s.id DESC
     LIMIT 8"
)->fetchAll();

$recentPurchases = $pdo->query(
    'SELECT p.id, p.purchase_date, p.total, s.name AS supplier_name
     FROM purchases p
     INNER JOIN suppliers s ON s.id = p.supplier_id
     ORDER BY p.id DESC
     LIMIT 5'
)->fetchAll();

$todayExpensesStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = ?'
);
$todayExpensesStmt->execute([$today]);
$todayExpenses = (float) $todayExpensesStmt->fetchColumn();

$range = trim($_GET['range'] ?? 'week');
if (!in_array($range, ['week', 'month', 'year', 'all'], true)) {
    $range = 'week';
}

$chartTo = $today;
if ($range === 'week') {
    $chartFrom = date('Y-m-d', strtotime('-6 days'));
    $chartGrain = 'daily';
    $rangeLabel = 'Last 7 Days';
} elseif ($range === 'month') {
    $chartFrom = $monthStart;
    $chartGrain = 'daily';
    $rangeLabel = date('F Y');
} elseif ($range === 'year') {
    $chartFrom = date('Y-01-01');
    $chartGrain = 'monthly';
    $rangeLabel = date('Y');
} else {
    $earliest = $pdo->query(
        "SELECT MIN(d) FROM (
            SELECT MIN(sale_date) AS d FROM sales
            UNION ALL
            SELECT MIN(expense_date) FROM expenses
            UNION ALL
            SELECT MIN(purchase_date) FROM purchases
            UNION ALL
            SELECT MIN(cashin_date) FROM gcash_cashins
         ) AS dates"
    )->fetchColumn();
    $chartFrom = $earliest ?: $today;
    $chartGrain = 'monthly';
    $rangeLabel = 'All Time';
}

$salesByBucket = [];
$expensesByBucket = [];
$purchasesByBucket = [];
$gcashFeesByBucket = [];

if ($chartGrain === 'daily') {
    $salesStmt = $pdo->prepare(
        'SELECT sale_date AS bucket, COALESCE(SUM(total), 0) AS total
         FROM sales
         WHERE sale_date BETWEEN ? AND ?
         GROUP BY sale_date'
    );
    $expensesStmt = $pdo->prepare(
        'SELECT expense_date AS bucket, COALESCE(SUM(amount), 0) AS total
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?
         GROUP BY expense_date'
    );
    $purchasesStmt = $pdo->prepare(
        'SELECT purchase_date AS bucket, COALESCE(SUM(total), 0) AS total
         FROM purchases
         WHERE purchase_date BETWEEN ? AND ?
         GROUP BY purchase_date'
    );
    $gcashFeesStmt = $pdo->prepare(
        'SELECT cashin_date AS bucket, COALESCE(SUM(fee_charged), 0) AS total
         FROM gcash_cashins
         WHERE cashin_date BETWEEN ? AND ?
         GROUP BY cashin_date'
    );
} else {
    $salesStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(sale_date, '%Y-%m') AS bucket, COALESCE(SUM(total), 0) AS total
         FROM sales
         WHERE sale_date BETWEEN ? AND ?
         GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
         ORDER BY bucket ASC"
    );
    $expensesStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS bucket, COALESCE(SUM(amount), 0) AS total
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?
         GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
         ORDER BY bucket ASC"
    );
    $purchasesStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(purchase_date, '%Y-%m') AS bucket, COALESCE(SUM(total), 0) AS total
         FROM purchases
         WHERE purchase_date BETWEEN ? AND ?
         GROUP BY DATE_FORMAT(purchase_date, '%Y-%m')
         ORDER BY bucket ASC"
    );
    $gcashFeesStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(cashin_date, '%Y-%m') AS bucket, COALESCE(SUM(fee_charged), 0) AS total
         FROM gcash_cashins
         WHERE cashin_date BETWEEN ? AND ?
         GROUP BY DATE_FORMAT(cashin_date, '%Y-%m')
         ORDER BY bucket ASC"
    );
}

$salesStmt->execute([$chartFrom, $chartTo]);
foreach ($salesStmt->fetchAll() as $row) {
    $salesByBucket[$row['bucket']] = (float) $row['total'];
}
$expensesStmt->execute([$chartFrom, $chartTo]);
foreach ($expensesStmt->fetchAll() as $row) {
    $expensesByBucket[$row['bucket']] = (float) $row['total'];
}
$purchasesStmt->execute([$chartFrom, $chartTo]);
foreach ($purchasesStmt->fetchAll() as $row) {
    $purchasesByBucket[$row['bucket']] = (float) $row['total'];
}
try {
    $gcashFeesStmt->execute([$chartFrom, $chartTo]);
    foreach ($gcashFeesStmt->fetchAll() as $row) {
        $gcashFeesByBucket[$row['bucket']] = (float) $row['total'];
    }
} catch (PDOException $e) {
    // ignore if gcash_cashins table is missing
}

$chartLabels = [];
$chartSales = [];
$chartExpenses = [];
$chartPurchases = [];
$chartGcashFees = [];

if ($chartGrain === 'daily') {
    $cursor = strtotime($chartFrom);
    $endTs = strtotime($chartTo);
    while ($cursor <= $endTs) {
        $key = date('Y-m-d', $cursor);
        $chartLabels[] = date('M j', $cursor);
        $chartSales[] = $salesByBucket[$key] ?? 0.0;
        $chartExpenses[] = $expensesByBucket[$key] ?? 0.0;
        $chartPurchases[] = $purchasesByBucket[$key] ?? 0.0;
        $chartGcashFees[] = $gcashFeesByBucket[$key] ?? 0.0;
        $cursor = strtotime('+1 day', $cursor);
    }
} else {
    $cursor = strtotime(date('Y-m-01', strtotime($chartFrom)));
    $endTs = strtotime(date('Y-m-01', strtotime($chartTo)));
    while ($cursor <= $endTs) {
        $key = date('Y-m', $cursor);
        $chartLabels[] = date('M Y', $cursor);
        $chartSales[] = $salesByBucket[$key] ?? 0.0;
        $chartExpenses[] = $expensesByBucket[$key] ?? 0.0;
        $chartPurchases[] = $purchasesByBucket[$key] ?? 0.0;
        $chartGcashFees[] = $gcashFeesByBucket[$key] ?? 0.0;
        $cursor = strtotime('+1 month', $cursor);
    }
}

$rangePresets = [
    'week' => 'Week',
    'month' => 'Month',
    'year' => 'Year',
    'all' => 'All',
];

$paymentLabels = [
    'cash' => 'Cash',
    'gcash' => 'GCash',
    'bank' => 'Bank',
    'credit' => 'Lend (Utang)',
];

require __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'forbidden'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    You do not have permission to access that page.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Dashboard</h1>
    <p class="text-muted mb-0">Welcome back, <?= htmlspecialchars($user['name']) ?>.</p>
  </div>
  <a href="sale_new.php" class="btn btn-rice">
    <i class="bi bi-plus-lg"></i> New Sale
  </a>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-xl">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Today's Sales</div>
      <div class="fs-4 fw-bold text-success">₱<?= number_format($todaySales, 2) ?></div>
      <div class="small text-muted"><?= htmlspecialchars($today) ?></div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Monthly Sales</div>
      <div class="fs-4 fw-bold">₱<?= number_format($monthSales, 2) ?></div>
      <div class="small text-muted"><?= htmlspecialchars(date('F Y')) ?></div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Rice Stock</div>
      <div class="fs-4 fw-bold"><?= number_format($riceStockKg, 2) ?> kg</div>
      <div class="small text-muted">Active rice products</div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Other Items</div>
      <div class="fs-4 fw-bold"><?= $otherProductCount ?></div>
      <div class="small text-muted">Active products (egg, oil, etc.)</div>
      <a href="products.php?type=GROCERY" class="small">View other items</a>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Today's Expenses</div>
      <div class="fs-4 fw-bold text-danger">₱<?= number_format($todayExpenses, 2) ?></div>
      <div class="small text-muted">
        <?= count($lowStockRice) + count($lowStockOther) ?> low-stock item(s)
      </div>
    </div>
  </div>
</div>

<div class="bg-white rounded shadow-sm p-3 mb-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h2 class="h6 mb-0">Sales vs Money Out</h2>
      <p class="small text-muted mb-0"><?= htmlspecialchars($rangeLabel) ?> · Sales, GCash fees, Expenses &amp; Purchases</p>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <div class="btn-group btn-group-sm" role="group" aria-label="Chart range">
        <?php foreach ($rangePresets as $key => $label): ?>
          <a href="dashboard.php?range=<?= urlencode($key) ?>"
             class="btn btn-outline-secondary<?= $range === $key ? ' active' : '' ?>">
            <?= htmlspecialchars($label) ?>
          </a>
        <?php endforeach; ?>
      </div>
      <a href="reports.php?from=<?= urlencode($chartFrom) ?>&to=<?= urlencode($chartTo) ?>" class="small">View reports</a>
      <a href="analytics.php?from=<?= urlencode($chartFrom) ?>&to=<?= urlencode($chartTo) ?>" class="small">Analytics</a>
    </div>
  </div>
  <canvas id="cashflowChart" height="100"></canvas>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Other Items — Stock</h2>
        <a href="inventory.php" class="small">View inventory</a>
      </div>
      <?php if (count($otherProductsStock) === 0): ?>
        <p class="text-muted mb-0">No active other items yet. <a href="products.php?type=GROCERY">Add one</a>.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-end">Stock</th>
                <th class="text-end">Price</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($otherProductsStock as $item): ?>
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
  <div class="col-lg-6">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Top Other Items — This Month</h2>
        <a href="reports.php?from=<?= urlencode($monthStart) ?>&to=<?= urlencode($today) ?>" class="small">View reports</a>
      </div>
      <?php if (count($topOtherMonth) === 0): ?>
        <p class="text-muted mb-0">No other-item sales this month yet.</p>
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
              <?php foreach ($topOtherMonth as $row): ?>
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
  <div class="col-lg-4">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Low Stock Alert (Rice)</h2>
        <a href="inventory.php" class="small">View inventory</a>
      </div>
      <?php if (count($lowStockRice) === 0): ?>
        <p class="text-muted mb-0">All rice products are above minimum stock.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-end">Stock</th>
                <th class="text-end">Min</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lowStockRice as $item): ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars($item['name']) ?></td>
                  <td class="text-end text-danger fw-semibold"><?= number_format((float) $item['stock'], 2) ?> kg</td>
                  <td class="text-end"><?= number_format((float) $item['minimum_stock'], 2) ?> kg</td>
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
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Low Stock Alert (Other Items)</h2>
        <a href="inventory.php" class="small">View inventory</a>
      </div>
      <?php if (count($lowStockOther) === 0): ?>
        <p class="text-muted mb-0">All other items are above minimum stock.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-end">Stock</th>
                <th class="text-end">Min</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lowStockOther as $item): ?>
                <?php $unit = $item['unit'] ?? 'pc'; ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars($item['name']) ?></td>
                  <td class="text-end text-danger fw-semibold">
                    <?= number_format((float) $item['stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
                  </td>
                  <td class="text-end">
                    <?= number_format((float) $item['minimum_stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
                  </td>
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
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Recent Sales</h2>
        <a href="sales.php" class="small">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Date</th>
              <th>Customer</th>
              <th>Payment</th>
              <th class="text-end">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($recentSales) === 0): ?>
              <tr>
                <td colspan="5" class="text-muted text-center">No sales yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentSales as $sale): ?>
                <tr>
                  <td>
                    <a href="sale_view.php?id=<?= (int) $sale['id'] ?>">#<?= (int) $sale['id'] ?></a>
                  </td>
                  <td><?= htmlspecialchars($sale['sale_date']) ?></td>
                  <td><?= htmlspecialchars($sale['customer_name']) ?></td>
                  <td><?= htmlspecialchars($paymentLabels[$sale['payment_method']] ?? $sale['payment_method']) ?></td>
                  <td class="text-end">₱<?= number_format((float) $sale['total'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="bg-white rounded shadow-sm p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h6 mb-0">Recent Purchases</h2>
    <a href="purchases.php" class="small">View all</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Date</th>
          <th>Supplier</th>
          <th class="text-end">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($recentPurchases) === 0): ?>
          <tr>
            <td colspan="4" class="text-muted text-center">No purchases yet.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($recentPurchases as $purchase): ?>
            <tr>
              <td>
                <a href="purchase_view.php?id=<?= (int) $purchase['id'] ?>">#<?= (int) $purchase['id'] ?></a>
              </td>
              <td><?= htmlspecialchars($purchase['purchase_date']) ?></td>
              <td><?= htmlspecialchars($purchase['supplier_name']) ?></td>
              <td class="text-end">₱<?= number_format((float) $purchase['total'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const canvas = document.getElementById('cashflowChart');
  if (!canvas) return;

  const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
  const sales = <?= json_encode($chartSales) ?>;
  const gcashFees = <?= json_encode($chartGcashFees) ?>;
  const expenses = <?= json_encode($chartExpenses) ?>;
  const purchases = <?= json_encode($chartPurchases) ?>;

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Sales',
          data: sales,
          backgroundColor: 'rgba(45, 106, 79, 0.75)',
          borderRadius: 4
        },
        {
          label: 'GCash fees',
          data: gcashFees,
          backgroundColor: 'rgba(13, 110, 253, 0.7)',
          borderRadius: 4
        },
        {
          label: 'Expenses',
          data: expenses,
          backgroundColor: 'rgba(192, 57, 43, 0.7)',
          borderRadius: 4
        },
        {
          label: 'Purchases',
          data: purchases,
          backgroundColor: 'rgba(108, 117, 125, 0.7)',
          borderRadius: 4
        }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: true, position: 'top' }
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

<?php require __DIR__ . '/includes/footer.php'; ?>