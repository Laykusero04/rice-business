<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/gcash_fees.php';
requireLogin();

$user = currentUser();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;
$row = null;

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM gcash_cashins WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        header('Location: /rice-business/frontend/gcash.php?error=notfound');
        exit;
    }

    $defaultType = normalizeGcashTxnType($row['txn_type'] ?? 'cash_in');
} else {
    $defaultType = normalizeGcashTxnType($_GET['type'] ?? 'cash_in');
}

$pageTitle = $isEdit ? 'Edit GCash Transaction' : 'New GCash Transaction';
$activePage = $isEdit ? 'gcash-history' : 'gcash-new';

$tiers = fetchGcashFeeTiers($pdo);
$tiersJs = gcashFeeTiersForJs($pdo);

$flash = '';
$flashType = 'danger';
if (isset($_GET['error'])) {
    $flash = match ($_GET['error']) {
        'invalid' => 'Enter a valid amount, fee (0 or more), and date.',
        'save' => $isEdit ? 'Could not update. Try again.' : 'Could not save. Try again.',
        default => 'Something went wrong.',
    };
}

$amountVal = $isEdit ? number_format((float) $row['cashin_amount'], 2, '.', '') : '';
$feeVal = $isEdit ? number_format((float) $row['fee_charged'], 2, '.', '') : '0';
$dateVal = $isEdit ? (string) $row['cashin_date'] : date('Y-m-d');
$customerNameVal = $isEdit ? (string) ($row['customer_name'] ?? '') : '';
$gcashNumberVal = $isEdit ? (string) ($row['gcash_number'] ?? '') : '';
$referenceNoVal = $isEdit ? (string) ($row['reference_no'] ?? '') : '';
$notesVal = $isEdit ? (string) ($row['notes'] ?? '') : '';

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1"><?= $isEdit ? 'Edit #' . (int) $row['id'] : 'New GCash Transaction' ?></h1>
    <p class="text-muted mb-0">
      <strong>Cash-in</strong> = customer gives you cash, you load their GCash.
      <strong>Cash-out</strong> = you give them cash, money leaves their GCash.
    </p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($isEdit): ?>
      <a href="gcash_view.php?id=<?= (int) $row['id'] ?>" class="btn btn-outline-secondary">View</a>
    <?php endif; ?>
    <a href="gcash.php" class="btn btn-outline-secondary">History</a>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <form method="POST" action="/rice-business/backend/gcash_cashin_save.php" class="bg-white rounded shadow-sm p-3 p-md-4" id="gcashForm">
      <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <?php endif; ?>

      <div class="mb-3">
        <label class="form-label d-block">Type</label>
        <div class="btn-group w-100" role="group" aria-label="Transaction type">
          <input type="radio" class="btn-check" name="txn_type" id="typeCashIn" value="cash_in" <?= $defaultType === 'cash_in' ? 'checked' : '' ?>>
          <label class="btn btn-outline-success" for="typeCashIn">Cash-In (load)</label>
          <input type="radio" class="btn-check" name="txn_type" id="typeCashOut" value="cash_out" <?= $defaultType === 'cash_out' ? 'checked' : '' ?>>
          <label class="btn btn-outline-primary" for="typeCashOut">Cash-Out (withdraw)</label>
        </div>
      </div>

      <div class="alert alert-light border small" id="typeHelp">
        Customer pays you cash. You send the amount to their GCash. You keep the fee.
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
            placeholder="e.g. 1000"
            <?php if ($amountVal !== ''): ?>value="<?= htmlspecialchars($amountVal) ?>"<?php endif; ?>
          >
          <div class="form-text" id="amountHint">Amount loaded to their GCash</div>
        </div>
        <div class="col-md-6">
          <label for="cashinDate" class="form-label">Date</label>
          <input type="date" class="form-control" id="cashinDate" name="cashin_date" value="<?= htmlspecialchars($dateVal) ?>" required>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <label for="suggestedFee" class="form-label">Suggested fee</label>
          <input type="text" class="form-control" id="suggestedFee" readonly value="—">
          <div class="form-text">From your rate card</div>
        </div>
        <div class="col-md-4">
          <label for="feeCharged" class="form-label">Fee to charge (₱)</label>
          <input type="number" class="form-control" id="feeCharged" name="fee_charged" step="0.01" min="0" value="<?= htmlspecialchars($feeVal) ?>" required>
          <div class="form-text">Lower or raise for this customer</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" id="totalLabel">Collect from customer</label>
          <div class="form-control-plaintext fw-bold fs-5 text-success" id="totalCollected">₱0.00</div>
          <div class="form-text" id="totalHint">Amount + fee</div>
        </div>
      </div>

      <hr class="my-4">
      <h2 class="h6 mb-3">Optional details</h2>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label for="customerName" class="form-label">Customer name</label>
          <input type="text" class="form-control" id="customerName" name="customer_name" maxlength="100" placeholder="Optional" value="<?= htmlspecialchars($customerNameVal) ?>">
        </div>
        <div class="col-md-6">
          <label for="gcashNumber" class="form-label">GCash number</label>
          <input type="text" class="form-control" id="gcashNumber" name="gcash_number" maxlength="30" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($gcashNumberVal) ?>">
        </div>
        <div class="col-md-6">
          <label for="referenceNo" class="form-label">Reference / confirmation</label>
          <input type="text" class="form-control" id="referenceNo" name="reference_no" maxlength="100" placeholder="From GCash receipt" value="<?= htmlspecialchars($referenceNoVal) ?>">
        </div>
        <div class="col-md-6">
          <label for="notes" class="form-label">Notes</label>
          <input type="text" class="form-control" id="notes" name="notes" maxlength="255" placeholder="Optional" value="<?= htmlspecialchars($notesVal) ?>">
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-rice" id="submitBtn"><?= $isEdit ? 'Update' : 'Save Cash-In' ?></button>
        <?php if ($isEdit): ?>
          <a href="gcash_view.php?id=<?= (int) $row['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
        <?php else: ?>
          <a href="gcash.php" class="btn btn-outline-secondary">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="col-lg-5">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-3">Your fee rate card</h2>
      <p class="small text-muted">Same rates for cash-in and cash-out (change fee per customer anytime).</p>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Amount</th>
              <th class="text-end">Fee</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tiers as $tier): ?>
              <tr>
                <td>
                  ₱<?= number_format((float) $tier['min_amount'], 0) ?>
                  –
                  ₱<?= number_format((float) $tier['max_amount'], 0) ?>
                </td>
                <td class="text-end fw-semibold">₱<?= number_format((float) $tier['fee'], 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const tiers = <?= json_encode($tiersJs, JSON_UNESCAPED_UNICODE) ?>;
  const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
  const amountInput = document.getElementById('cashinAmount');
  const suggestedEl = document.getElementById('suggestedFee');
  const feeInput = document.getElementById('feeCharged');
  const totalEl = document.getElementById('totalCollected');
  const totalLabel = document.getElementById('totalLabel');
  const totalHint = document.getElementById('totalHint');
  const amountLabel = document.getElementById('amountLabel');
  const amountHint = document.getElementById('amountHint');
  const typeHelp = document.getElementById('typeHelp');
  const submitBtn = document.getElementById('submitBtn');
  let feeTouched = isEdit;

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
      if (amount >= t.min && amount <= t.max) {
        return t.fee;
      }
    }
    return null;
  }

  function updateTypeUi() {
    const isOut = currentType() === 'cash_out';
    if (isOut) {
      typeHelp.innerHTML = '<strong>Cash-out:</strong> Give the customer cash. Deduct <em>amount + fee</em> from their GCash. You keep the fee.';
      amountLabel.textContent = 'Cash to give (₱)';
      amountHint.textContent = 'Cash the customer receives from you';
      totalLabel.textContent = 'Deduct from their GCash';
      totalHint.textContent = 'Amount + fee';
      submitBtn.textContent = isEdit ? 'Update' : 'Save Cash-Out';
      totalEl.classList.remove('text-success');
      totalEl.classList.add('text-primary');
    } else {
      typeHelp.innerHTML = '<strong>Cash-in:</strong> Collect cash from the customer. Load the amount to their GCash. You keep the fee.';
      amountLabel.textContent = 'Amount to load (₱)';
      amountHint.textContent = 'Amount loaded to their GCash wallet';
      totalLabel.textContent = 'Collect from customer';
      totalHint.textContent = 'Amount + fee';
      submitBtn.textContent = isEdit ? 'Update' : 'Save Cash-In';
      totalEl.classList.add('text-success');
      totalEl.classList.remove('text-primary');
    }
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

    if (suggested === null) {
      suggestedEl.value = amount > 0 ? 'Enter fee manually' : '—';
      if (!feeTouched) {
        feeInput.value = '0';
      }
    } else {
      suggestedEl.value = formatMoney(suggested);
      if (!feeTouched) {
        feeInput.value = String(suggested);
      }
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
