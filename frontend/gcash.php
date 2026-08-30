<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/gcash_fees.php';
requireLogin();

$user = currentUser();
$pageTitle = 'GCash History';
$activePage = 'gcash-history';

$search = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$typeFilter = normalizeGcashTxnType($_GET['type'] ?? '');
$filterAll = !isset($_GET['type']) || $_GET['type'] === '' || $_GET['type'] === 'all';
if ($filterAll) {
    $typeFilter = '';
}

$sql = 'SELECT * FROM gcash_cashins WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND (customer_name LIKE ? OR gcash_number LIKE ? OR reference_no LIKE ? OR notes LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($from !== '') {
    $sql .= ' AND cashin_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $sql .= ' AND cashin_date <= ?';
    $params[] = $to;
}
if ($typeFilter !== '') {
    $sql .= ' AND txn_type = ?';
    $params[] = $typeFilter;
}

$sql .= ' ORDER BY cashin_date DESC, id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalFees = 0.0;
$totalIn = 0.0;
$totalOut = 0.0;
foreach ($rows as $row) {
    $totalFees += (float) $row['fee_charged'];
    $type = $row['txn_type'] ?? 'cash_in';
    if ($type === 'cash_out') {
        $totalOut += (float) $row['cashin_amount'];
    } else {
        $totalIn += (float) $row['cashin_amount'];
    }
}

$tiers = fetchGcashFeeTiers($pdo);

$flash = '';
$flashType = 'success';
if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'deleted' => 'Transaction deleted.',
        default => '',
    };
}
if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'notfound' => 'Record not found.',
        'delete' => 'Could not delete.',
        'invalid' => 'Invalid request.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">GCash History</h1>
    <p class="text-muted mb-0">
      Cash-in = load to GCash · Cash-out = withdraw cash. Fee income is what you keep.
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="gcash_new.php?type=cash_in" class="btn btn-outline-success">New Cash-In</a>
    <a href="gcash_new.php?type=cash_out" class="btn btn-outline-primary">New Cash-Out</a>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3">
      <div class="small text-muted">Cash-in volume</div>
      <div class="fs-5 fw-semibold">₱<?= number_format($totalIn, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3">
      <div class="small text-muted">Cash-out volume</div>
      <div class="fs-5 fw-semibold">₱<?= number_format($totalOut, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3">
      <div class="small text-muted">Fee income</div>
      <div class="fs-5 fw-semibold text-success">₱<?= number_format($totalFees, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3">
      <div class="small text-muted">Transactions</div>
      <div class="fs-5 fw-semibold"><?= count($rows) ?></div>
    </div>
  </div>
</div>

<form class="row g-2 mb-3" method="GET" action="gcash.php">
  <div class="col-md-3">
    <input type="search" name="q" class="form-control" placeholder="Search name, number..." value="<?= htmlspecialchars($search) ?>">
  </div>
  <div class="col-md-2">
    <select name="type" class="form-select">
      <option value="all" <?= $filterAll ? 'selected' : '' ?>>All types</option>
      <option value="cash_in" <?= $typeFilter === 'cash_in' ? 'selected' : '' ?>>Cash-In</option>
      <option value="cash_out" <?= $typeFilter === 'cash_out' ? 'selected' : '' ?>>Cash-Out</option>
    </select>
  </div>
  <div class="col-md-2">
    <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
  </div>
  <div class="col-md-2">
    <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
  </div>
  <div class="col-md-3 d-flex gap-2">
    <button type="submit" class="btn btn-outline-secondary">Filter</button>
    <a href="gcash.php" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="table-responsive bg-white rounded shadow-sm mb-4">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Date</th>
        <th>Type</th>
        <th>Customer / GCash #</th>
        <th class="text-end">Amount</th>
        <th class="text-end">Fee</th>
        <th class="text-end">Total</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($rows) === 0): ?>
        <tr>
          <td colspan="7" class="text-center text-muted py-4">No transactions yet.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $row): ?>
          <?php
            $isOut = ($row['txn_type'] ?? 'cash_in') === 'cash_out';
            $suggested = (float) $row['suggested_fee'];
            $charged = (float) $row['fee_charged'];
          ?>
          <tr>
            <td><?= htmlspecialchars($row['cashin_date']) ?></td>
            <td>
              <?php if ($isOut): ?>
                <span class="badge text-bg-primary">Cash-Out</span>
              <?php else: ?>
                <span class="badge text-bg-success">Cash-In</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($row['customer_name'] ?: '—') ?></div>
              <div class="small text-muted"><?= htmlspecialchars($row['gcash_number'] ?: '') ?></div>
            </td>
            <td class="text-end">₱<?= number_format((float) $row['cashin_amount'], 2) ?></td>
            <td class="text-end">
              ₱<?= number_format($charged, 2) ?>
              <?php if ($charged + 0.001 < $suggested): ?>
                <span class="badge text-bg-warning">disc.</span>
              <?php endif; ?>
            </td>
            <td class="text-end fw-semibold">
              ₱<?= number_format((float) $row['total_collected'], 2) ?>
              <div class="small text-muted"><?= $isOut ? 'from GCash' : 'cash collected' ?></div>
            </td>
            <td class="text-end text-nowrap">
              <a href="gcash_view.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
              <a href="gcash_edit.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="bg-white rounded shadow-sm p-3">
  <h2 class="h6 mb-3">Rate card (cash-in &amp; cash-out)</h2>
  <div class="table-responsive" style="max-width: 360px;">
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr><th>Amount</th><th class="text-end">Fee</th></tr>
      </thead>
      <tbody>
        <?php foreach ($tiers as $tier): ?>
          <tr>
            <td>₱<?= number_format((float) $tier['min_amount'], 0) ?>–₱<?= number_format((float) $tier['max_amount'], 0) ?></td>
            <td class="text-end">₱<?= number_format((float) $tier['fee'], 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
