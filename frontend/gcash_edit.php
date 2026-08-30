<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/gcash_fees.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Edit GCash Transaction';
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
$tiers = fetchGcashFeeTiers($pdo);
$tiersJs = gcashFeeTiersForJs($pdo);

$flash = '';
$flashType = 'danger';
if (isset($_GET['error'])) {
    $flash = match ($_GET['error']) {
        'invalid' => 'Enter a valid amount, fee (0 or more), and date.',
        'save' => 'Could not update. Try again.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Edit #<?= (int) $row['id'] ?></h1>
  </div>
  <a href="gcash_view.php?id=<?= (int) $row['id'] ?>" class="btn btn-outline-secondary">View</a>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<form method="POST" action="/rice-business/backend/gcash_cashin_save.php" class="bg-white rounded shadow-sm p-3 p-md-4" id="gcashForm">
  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

  <div class="mb-3">
    <label class="form-label d-block">Type</label>
    <div class="btn-group w-100" role="group">
      <input type="radio" class="btn-check" name="txn_type" id="typeCashIn" value="cash_in" <?= $txnType === 'cash_in' ? 'checked' : '' ?>>
      <label class="btn btn-outline-success" for="typeCashIn">Cash-In</label>
      <input type="radio" class="btn-check" name="txn_type" id="typeCashOut" value="cash_out" <?= $txnType === 'cash_out' ? 'checked' : '' ?>>
      <label class="btn btn-outline-primary" for="typeCashOut">Cash-Out</label>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <label for="cashinAmount" class="form-label" id="amountLabel">Amount (₱)</label>
      <input
        type="number"
        class="form-control"
        id="cashinAmount"
        name="cashin_amount"
        step="0.01"
        min="0.01"
        required
        value="<?= htmlspecialchars(number_format((float) $row['cashin_amount'], 2, '.', '')) ?>"
      >
    </div>
    <div class="col-md-6">
      <label for="cashinDate" class="form-label">Date</label>
      <input type="date" class="form-control" id="cashinDate" name="cashin_date" value="<?= htmlspecialchars($row['cashin_date']) ?>" required>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <label for="suggestedFee" class="form-label">Suggested fee</label>
      <input type="text" class="form-control" id="suggestedFee" readonly value="—">
    </div>
    <div class="col-md-4">
      <label for="feeCharged" class="form-label">Fee to charge (₱)</label>
      <input
        type="number"
        class="form-control"
        id="feeCharged"
        name="fee_charged"
        step="0.01"
        min="0"
        required
        value="<?= htmlspecialchars(number_format((float) $row['fee_charged'], 2, '.', '')) ?>"
      >
    </div>
    <div class="col-md-4">
      <label class="form-label" id="totalLabel">Total</label>
      <div class="form-control-plaintext fw-bold fs-5" id="totalCollected">₱0.00</div>
      <div class="form-text" id="totalHint"></div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <label for="customerName" class="form-label">Customer name</label>
      <input type="text" class="form-control" id="customerName" name="customer_name" maxlength="100" value="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
    </div>
    <div class="col-md-6">
      <label for="gcashNumber" class="form-label">GCash number</label>
      <input type="text" class="form-control" id="gcashNumber" name="gcash_number" maxlength="30" value="<?= htmlspecialchars($row['gcash_number'] ?? '') ?>">
    </div>
    <div class="col-md-6">
      <label for="referenceNo" class="form-label">Reference / confirmation</label>
      <input type="text" class="form-control" id="referenceNo" name="reference_no" maxlength="100" value="<?= htmlspecialchars($row['reference_no'] ?? '') ?>">
    </div>
    <div class="col-md-6">
      <label for="notes" class="form-label">Notes</label>
      <input type="text" class="form-control" id="notes" name="notes" maxlength="255" value="<?= htmlspecialchars($row['notes'] ?? '') ?>">
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-rice">Update</button>
    <a href="gcash_view.php?id=<?= (int) $row['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const tiers = <?= json_encode($tiersJs, JSON_UNESCAPED_UNICODE) ?>;
  const amountInput = document.getElementById('cashinAmount');
  const suggestedEl = document.getElementById('suggestedFee');
  const feeInput = document.getElementById('feeCharged');
  const totalEl = document.getElementById('totalCollected');
  const totalLabel = document.getElementById('totalLabel');
  const totalHint = document.getElementById('totalHint');
  const amountLabel = document.getElementById('amountLabel');
  let feeTouched = true;

  function formatMoney(value) {
    return '₱' + Number(value).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function currentType() {
    const checked = document.querySelector('input[name="txn_type"]:checked');
    return checked ? checked.value : 'cash_in';
  }

  function lookupFee(amount) {
    for (let i = 0; i < tiers.length; i++) {
      const t = tiers[i];
      if (amount >= t.min && amount <= t.max) return t.fee;
    }
    return null;
  }

  function updateTypeUi() {
    const isOut = currentType() === 'cash_out';
    amountLabel.textContent = isOut ? 'Cash to give (₱)' : 'Amount to load (₱)';
    totalLabel.textContent = isOut ? 'Deduct from their GCash' : 'Collect from customer';
    totalHint.textContent = 'Amount + fee';
    updateTotals();
  }

  function updateTotals() {
    const amount = parseFloat(amountInput.value) || 0;
    const fee = parseFloat(feeInput.value) || 0;
    totalEl.textContent = formatMoney(amount + fee);
  }

  function syncSuggested() {
    const amount = parseFloat(amountInput.value) || 0;
    const suggested = amount > 0 ? lookupFee(amount) : null;
    suggestedEl.value = suggested === null
      ? (amount > 0 ? 'Enter fee manually' : '—')
      : formatMoney(suggested);
    if (!feeTouched && suggested !== null) {
      feeInput.value = String(suggested);
    }
    updateTotals();
  }

  document.querySelectorAll('input[name="txn_type"]').forEach(function (el) {
    el.addEventListener('change', updateTypeUi);
  });
  amountInput.addEventListener('input', function () {
    feeTouched = false;
    syncSuggested();
  });
  feeInput.addEventListener('input', function () {
    feeTouched = true;
    updateTotals();
  });

  updateTypeUi();
  syncSuggested();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
