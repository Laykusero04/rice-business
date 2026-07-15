<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Outstanding Utang';
$activePage = 'utang';

$today = date('Y-m-d');

$totals = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
        COALESCE(SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END), 0) AS partial_count,
        COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_count,
        COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END), 0) AS paid_amount,
        COALESCE(SUM(CASE WHEN payment_status = 'partial' THEN total ELSE 0 END), 0) AS partial_amount,
        COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN total ELSE 0 END), 0) AS unpaid_amount,
        COALESCE(SUM(GREATEST(total - amount_paid, 0)), 0) AS outstanding_total,
        COALESCE(SUM(CASE WHEN (total - amount_paid) > 0 THEN 1 ELSE 0 END), 0) AS open_count,
        COALESCE(SUM(amount_paid), 0) AS collected_total
     FROM sales"
)->fetch();

$paidCount = (int) $totals['paid_count'];
$partialCount = (int) $totals['partial_count'];
$unpaidCount = (int) $totals['unpaid_count'];
$paidAmount = (float) $totals['paid_amount'];
$partialAmount = (float) $totals['partial_amount'];
$unpaidAmount = (float) $totals['unpaid_amount'];
$outstandingTotal = (float) $totals['outstanding_total'];
$openCount = (int) $totals['open_count'];
$collectedTotal = (float) $totals['collected_total'];
$salesCount = $paidCount + $partialCount + $unpaidCount;

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

$agingOrder = ['0-30', '31-60', '61-90', '90+'];
$aging = [];
foreach ($agingOrder as $bucket) {
    $aging[$bucket] = ['sale_count' => 0, 'balance' => 0.0];
}
foreach ($agingRows as $row) {
    $bucket = $row['age_bucket'];
    if (isset($aging[$bucket])) {
        $aging[$bucket] = [
            'sale_count' => (int) $row['sale_count'],
            'balance' => (float) $row['balance'],
        ];
    }
}

$largestDebtors = $pdo->query(
    "SELECT c.id,
            COALESCE(c.name, 'Walk-in / Just buying') AS customer_name,
            COALESCE(c.contact, '') AS contact,
            COUNT(*) AS open_sales,
            COALESCE(SUM(s.total - s.amount_paid), 0) AS balance,
            MIN(s.sale_date) AS oldest_sale,
            MAX(s.sale_date) AS newest_sale
     FROM sales s
     LEFT JOIN customers c ON c.id = s.customer_id
     WHERE (s.total - s.amount_paid) > 0.001
     GROUP BY c.id, c.name, c.contact
     ORDER BY balance DESC
     LIMIT 10"
)->fetchAll();

$openSales = $pdo->query(
    "SELECT s.id, s.sale_date, s.total, s.amount_paid, s.payment_status,
            COALESCE(c.name, 'Walk-in / Just buying') AS customer_name,
            DATEDIFF(CURDATE(), s.sale_date) AS days_open
     FROM sales s
     LEFT JOIN customers c ON c.id = s.customer_id
     WHERE (s.total - s.amount_paid) > 0.001
     ORDER BY (s.total - s.amount_paid) DESC, s.sale_date ASC
     LIMIT 25"
)->fetchAll();

$recentlyPaid = $pdo->query(
    "SELECT s.id, s.sale_date, s.total, s.amount_paid, s.notes,
            COALESCE(c.name, 'Walk-in / Just buying') AS customer_name
     FROM sales s
     LEFT JOIN customers c ON c.id = s.customer_id
     WHERE s.payment_status = 'paid'
       AND s.payment_method = 'credit'
       AND s.notes LIKE '%Collected%'
     ORDER BY s.id DESC
     LIMIT 10"
)->fetchAll();

$exportBase = '/rice-business/backend/report_export.php';

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 no-print">
  <div>
    <h1 class="h3 mb-1">Outstanding Utang</h1>
    <p class="text-muted mb-0">Customer credit balances, aging, and recent collections.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
      <i class="bi bi-printer"></i> Print / PDF
    </button>
    <div class="dropdown">
      <button class="btn btn-rice dropdown-toggle" type="button" data-bs-toggle="dropdown">
        <i class="bi bi-download"></i> Export CSV
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= $exportBase ?>?type=utang_summary">Summary</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>?type=utang_open">Open Balances</a></li>
        <li><a class="dropdown-item" href="<?= $exportBase ?>?type=utang_customers">Largest Debtors</a></li>
      </ul>
    </div>
    <a href="sales.php?status=unpaid" class="btn btn-outline-secondary">View unpaid sales</a>
  </div>
</div>

<p class="small text-muted mb-3">As of <?= htmlspecialchars($today) ?></p>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Total outstanding</div>
      <div class="fs-4 fw-bold text-danger">₱<?= number_format($outstandingTotal, 2) ?></div>
      <div class="small text-muted"><?= $openCount ?> open sale(s)</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Collected (all time)</div>
      <div class="fs-4 fw-bold text-success">₱<?= number_format($collectedTotal, 2) ?></div>
      <div class="small text-muted">Sum of amount paid</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Unpaid sales</div>
      <div class="fs-4 fw-bold">₱<?= number_format($unpaidAmount, 2) ?></div>
      <div class="small text-muted"><?= $unpaidCount ?> sale(s)</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Partial payments</div>
      <div class="fs-4 fw-bold text-warning">₱<?= number_format($partialAmount, 2) ?></div>
      <div class="small text-muted"><?= $partialCount ?> sale(s)</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-1">Paid vs Unpaid Sales</h2>
      <p class="small text-muted mb-3">All sales by payment status</p>
      <?php if ($salesCount === 0): ?>
        <p class="text-muted mb-0">No sales yet.</p>
      <?php else: ?>
        <canvas id="statusChart" height="180"></canvas>
        <div class="table-responsive mt-3">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Status</th>
                <th class="text-end">Sales</th>
                <th class="text-end">Amount</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><span class="badge text-bg-success">Paid</span></td>
                <td class="text-end"><?= $paidCount ?></td>
                <td class="text-end">₱<?= number_format($paidAmount, 2) ?></td>
              </tr>
              <tr>
                <td><span class="badge text-bg-warning">Partial</span></td>
                <td class="text-end"><?= $partialCount ?></td>
                <td class="text-end">₱<?= number_format($partialAmount, 2) ?></td>
              </tr>
              <tr>
                <td><span class="badge text-bg-danger">Unpaid</span></td>
                <td class="text-end"><?= $unpaidCount ?></td>
                <td class="text-end">₱<?= number_format($unpaidAmount, 2) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-1">Aging of Receivables</h2>
      <p class="small text-muted mb-3">Open balances by days since sale date</p>
      <?php if ($outstandingTotal <= 0): ?>
        <p class="text-muted mb-0">No outstanding utang. All caught up.</p>
      <?php else: ?>
        <canvas id="agingChart" height="120"></canvas>
        <div class="table-responsive mt-3">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Age</th>
                <th class="text-end">Open sales</th>
                <th class="text-end">Balance</th>
                <th class="text-end">Share</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($aging as $bucket => $data): ?>
                <?php
                  $share = $outstandingTotal > 0
                      ? ($data['balance'] / $outstandingTotal) * 100
                      : 0.0;
                  $riskClass = match ($bucket) {
                      '61-90' => 'text-warning',
                      '90+' => 'text-danger fw-semibold',
                      default => '',
                  };
                ?>
                <tr>
                  <td class="<?= $riskClass ?>"><?= htmlspecialchars($bucket) ?> days</td>
                  <td class="text-end"><?= $data['sale_count'] ?></td>
                  <td class="text-end <?= $riskClass ?>">₱<?= number_format($data['balance'], 2) ?></td>
                  <td class="text-end"><?= number_format($share, 1) ?>%</td>
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
      <h2 class="h6 mb-3">Largest Unpaid Customers</h2>
      <?php if (count($largestDebtors) === 0): ?>
        <p class="text-muted mb-0">No unpaid balances.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Customer</th>
                <th class="text-end">Open</th>
                <th class="text-end">Balance</th>
                <th>Oldest</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($largestDebtors as $row): ?>
                <?php
                  $daysOld = (int) ((strtotime($today) - strtotime($row['oldest_sale'])) / 86400);
                ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= htmlspecialchars($row['customer_name']) ?></div>
                    <?php if ($row['contact'] !== ''): ?>
                      <div class="small text-muted"><?= htmlspecialchars($row['contact']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end"><?= (int) $row['open_sales'] ?></td>
                  <td class="text-end text-danger fw-semibold">
                    ₱<?= number_format((float) $row['balance'], 2) ?>
                  </td>
                  <td>
                    <div><?= htmlspecialchars($row['oldest_sale']) ?></div>
                    <div class="small text-muted"><?= $daysOld ?> day(s)</div>
                  </td>
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
      <h2 class="h6 mb-3">Recently Paid Accounts</h2>
      <?php if (count($recentlyPaid) === 0): ?>
        <p class="text-muted mb-0">No collected utang yet.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Sale</th>
                <th class="text-end">Paid</th>
                <th>Last collection</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentlyPaid as $row): ?>
                <?php
                  $lastLine = '';
                  $notes = trim((string) ($row['notes'] ?? ''));
                  if ($notes !== '') {
                      $lines = preg_split("/\r\n|\n|\r/", $notes);
                      $collectedLines = array_values(array_filter($lines, static function ($line) {
                          return stripos($line, 'Collected') !== false;
                      }));
                      if (count($collectedLines) > 0) {
                          $lastLine = $collectedLines[count($collectedLines) - 1];
                      }
                  }
                ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars($row['customer_name']) ?></td>
                  <td>
                    <a href="sale_view.php?id=<?= (int) $row['id'] ?>">#<?= (int) $row['id'] ?></a>
                    <div class="small text-muted"><?= htmlspecialchars($row['sale_date']) ?></div>
                  </td>
                  <td class="text-end text-success fw-semibold">
                    ₱<?= number_format((float) $row['total'], 2) ?>
                  </td>
                  <td class="small"><?= htmlspecialchars($lastLine !== '' ? $lastLine : 'Fully paid') ?></td>
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
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h6 mb-0">Open Utang Sales</h2>
        <span class="small text-muted">Top 25 by balance — follow up oldest / largest first</span>
      </div>
      <?php if (count($openSales) === 0): ?>
        <p class="text-muted mb-0">No open balances.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Sale</th>
                <th>Customer</th>
                <th>Date</th>
                <th class="text-end">Total</th>
                <th class="text-end">Paid</th>
                <th class="text-end">Balance</th>
                <th class="text-end">Days</th>
                <th class="no-print"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($openSales as $row): ?>
                <?php
                  $balance = (float) $row['total'] - (float) $row['amount_paid'];
                  $days = (int) $row['days_open'];
                  $ageClass = $days > 90 ? 'text-danger fw-semibold' : ($days > 60 ? 'text-warning' : '');
                  $status = $row['payment_status'];
                ?>
                <tr>
                  <td>
                    <a href="sale_view.php?id=<?= (int) $row['id'] ?>">#<?= (int) $row['id'] ?></a>
                    <?php if ($status === 'partial'): ?>
                      <span class="badge text-bg-warning">Partial</span>
                    <?php else: ?>
                      <span class="badge text-bg-danger">Unpaid</span>
                    <?php endif; ?>
                  </td>
                  <td class="fw-semibold"><?= htmlspecialchars($row['customer_name']) ?></td>
                  <td><?= htmlspecialchars($row['sale_date']) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['total'], 2) ?></td>
                  <td class="text-end">₱<?= number_format((float) $row['amount_paid'], 2) ?></td>
                  <td class="text-end text-danger fw-semibold">₱<?= number_format($balance, 2) ?></td>
                  <td class="text-end <?= $ageClass ?>"><?= $days ?></td>
                  <td class="text-end no-print">
                    <a class="btn btn-sm btn-outline-success" href="sale_view.php?id=<?= (int) $row['id'] ?>">
                      Collect
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const statusCanvas = document.getElementById('statusChart');
  if (statusCanvas) {
    new Chart(statusCanvas, {
      type: 'doughnut',
      data: {
        labels: ['Paid', 'Partial', 'Unpaid'],
        datasets: [{
          data: [
            <?= (float) $paidAmount ?>,
            <?= (float) $partialAmount ?>,
            <?= (float) $unpaidAmount ?>
          ],
          backgroundColor: [
            'rgba(45, 106, 79, 0.85)',
            'rgba(201, 162, 39, 0.85)',
            'rgba(185, 68, 68, 0.85)'
          ]
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                const value = Number(ctx.raw || 0);
                return ctx.label + ': ₱' + value.toLocaleString(undefined, {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2
                });
              }
            }
          }
        }
      }
    });
  }

  const agingCanvas = document.getElementById('agingChart');
  if (agingCanvas) {
    new Chart(agingCanvas, {
      type: 'bar',
      data: {
        labels: ['0–30 days', '31–60 days', '61–90 days', '90+ days'],
        datasets: [{
          label: 'Outstanding (₱)',
          data: [
            <?= (float) $aging['0-30']['balance'] ?>,
            <?= (float) $aging['31-60']['balance'] ?>,
            <?= (float) $aging['61-90']['balance'] ?>,
            <?= (float) $aging['90+']['balance'] ?>
          ],
          backgroundColor: [
            'rgba(45, 106, 79, 0.75)',
            'rgba(201, 162, 39, 0.75)',
            'rgba(217, 119, 6, 0.8)',
            'rgba(185, 68, 68, 0.85)'
          ],
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
  }
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
