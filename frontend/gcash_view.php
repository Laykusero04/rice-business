<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/gcash_fees.php';
requireLogin();

$user = currentUser();
$pageTitle = 'GCash Details';
$activePage = 'gcash-history';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /rice-business/frontend/gcash.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM gcash_cashins WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    header('Location: /rice-business/frontend/gcash.php?error=notfound');
    exit;
}

$txnType = normalizeGcashTxnType($row['txn_type'] ?? 'cash_in');
$isOut = $txnType === 'cash_out';
$summary = gcashMoneySummary($txnType, (float) $row['cashin_amount'], (float) $row['fee_charged']);

$flash = '';
if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'created' => 'Transaction saved.',
        'updated' => 'Transaction updated.',
        default => '',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">
      <?= $isOut ? 'Cash-Out' : 'Cash-In' ?> #<?= (int) $row['id'] ?>
    </h1>
    <p class="text-muted mb-0"><?= htmlspecialchars($row['cashin_date']) ?></p>
  </div>
  <div class="d-flex gap-2">
    <a href="gcash_new.php?id=<?= (int) $row['id'] ?>" class="btn btn-outline-primary">Edit</a>
    <form
      method="POST"
      action="/rice-business/backend/gcash_cashin_delete.php"
      class="d-inline"
      onsubmit="return confirm('Delete this record?');"
    >
      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <button type="submit" class="btn btn-outline-danger">Delete</button>
    </form>
    <a href="gcash.php" class="btn btn-outline-secondary">History</a>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="alert alert-light border mb-4">
  <?php if ($isOut): ?>
    Give the customer <strong>₱<?= number_format($summary['cash_to_customer'], 2) ?></strong> cash.
    Deduct <strong>₱<?= number_format($summary['deduct_from_gcash'], 2) ?></strong> from their GCash
    (amount + fee). You keep the fee.
  <?php else: ?>
    Collect <strong>₱<?= number_format($summary['collect_from_customer'], 2) ?></strong> cash from the customer.
    Load <strong>₱<?= number_format($summary['amount'], 2) ?></strong> to their GCash. You keep the fee.
  <?php endif; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="small text-muted"><?= $isOut ? 'Cash given' : 'Amount loaded' ?></div>
      <div class="fs-5 fw-semibold">₱<?= number_format((float) $row['cashin_amount'], 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="small text-muted">Suggested fee</div>
      <div class="fs-5 fw-semibold text-muted">₱<?= number_format((float) $row['suggested_fee'], 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="small text-muted">Fee charged</div>
      <div class="fs-5 fw-semibold text-success">₱<?= number_format((float) $row['fee_charged'], 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="small text-muted"><?= $isOut ? 'Deducted from GCash' : 'Collected from customer' ?></div>
      <div class="fs-5 fw-semibold">₱<?= number_format((float) $row['total_collected'], 2) ?></div>
    </div>
  </div>
</div>

<div class="bg-white rounded shadow-sm p-3">
  <dl class="row mb-0">
    <dt class="col-sm-3">Type</dt>
    <dd class="col-sm-9">
      <?php if ($isOut): ?>
        <span class="badge text-bg-primary">Cash-Out</span>
      <?php else: ?>
        <span class="badge text-bg-success">Cash-In</span>
      <?php endif; ?>
    </dd>
    <dt class="col-sm-3">Customer</dt>
    <dd class="col-sm-9"><?= htmlspecialchars($row['customer_name'] ?: '—') ?></dd>
    <dt class="col-sm-3">GCash number</dt>
    <dd class="col-sm-9"><?= htmlspecialchars($row['gcash_number'] ?: '—') ?></dd>
    <dt class="col-sm-3">Reference</dt>
    <dd class="col-sm-9"><?= htmlspecialchars($row['reference_no'] ?: '—') ?></dd>
    <dt class="col-sm-3">Notes</dt>
    <dd class="col-sm-9"><?= htmlspecialchars($row['notes'] ?: '—') ?></dd>
  </dl>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
