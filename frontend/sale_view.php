<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Sale Details';
$activePage = 'sales-history';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    header('Location: /rice-business/frontend/sales.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT s.*, COALESCE(c.name, 'Walk-in') AS customer_name, c.contact AS customer_contact
     FROM sales s
     LEFT JOIN customers c ON c.id = s.customer_id
     WHERE s.id = ?
     LIMIT 1"
);
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    header('Location: /rice-business/frontend/sales.php?error=notfound');
    exit;
}

$itemStmt = $pdo->prepare(
    'SELECT si.*, pr.name AS product_name
     FROM sale_items si
     INNER JOIN products pr ON pr.id = si.product_id
     WHERE si.sale_id = ?
     ORDER BY si.id ASC'
);
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$paymentLabels = [
    'cash' => 'Cash',
    'gcash' => 'GCash',
    'bank' => 'Bank Transfer',
    'credit' => 'Lend (Utang)',
];

$total = (float) $sale['total'];
$amountPaid = (float) ($sale['amount_paid'] ?? 0);
$balance = max(0, $total - $amountPaid);
$status = $sale['payment_status'] ?? 'paid';

$flash = '';
$flashType = 'success';

if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'created' => $sale['payment_method'] === 'credit'
            ? 'Lend saved. Stock deducted. Waiting for customer payment.'
            : 'Sale saved. Stock has been deducted.',
        'collected' => 'Payment recorded successfully.',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'amount' => 'Enter a valid payment amount.',
        'save' => 'Could not record the payment.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Sale #<?= (int) $sale['id'] ?></h1>
    <p class="text-muted mb-0">
      <?= $sale['payment_method'] === 'credit' ? 'Lend / utang details' : 'Sale details' ?>
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="sales.php" class="btn btn-outline-secondary">Back to History</a>
    <a href="sale_new.php" class="btn btn-rice">New Sale</a>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Customer</div>
      <div class="fw-semibold"><?= htmlspecialchars($sale['customer_name']) ?></div>
      <?php if (!empty($sale['customer_contact'])): ?>
        <div class="small text-muted"><?= htmlspecialchars($sale['customer_contact']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Date</div>
      <div class="fw-semibold"><?= htmlspecialchars($sale['sale_date']) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Payment</div>
      <div class="fw-semibold"><?= htmlspecialchars($paymentLabels[$sale['payment_method']] ?? $sale['payment_method']) ?></div>
      <?php if ($status === 'unpaid'): ?>
        <span class="badge text-bg-danger mt-1">Unpaid</span>
      <?php elseif ($status === 'partial'): ?>
        <span class="badge text-bg-warning mt-1">Partial</span>
      <?php else: ?>
        <span class="badge text-bg-success mt-1">Paid</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Total</div>
      <div class="fw-semibold fs-5">₱<?= number_format($total, 2) ?></div>
      <div class="small text-muted">Paid: ₱<?= number_format($amountPaid, 2) ?></div>
      <div class="small <?= $balance > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
        Balance: ₱<?= number_format($balance, 2) ?>
      </div>
    </div>
  </div>
</div>

<?php if ($balance > 0): ?>
  <div class="bg-white rounded shadow-sm p-3 p-md-4 mb-4">
    <h2 class="h6 mb-3">Record payment (bayad ng utang)</h2>
    <form method="POST" action="/rice-business/backend/sale_collect.php" class="row g-3 align-items-end">
      <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
      <div class="col-md-4">
        <label for="collectAmount" class="form-label">Amount received (₱)</label>
        <input
          type="number"
          class="form-control"
          id="collectAmount"
          name="amount"
          step="0.01"
          min="0.01"
          max="<?= htmlspecialchars(number_format($balance, 2, '.', '')) ?>"
          value="<?= htmlspecialchars(number_format($balance, 2, '.', '')) ?>"
          required
        >
        <div class="form-text">Remaining balance: ₱<?= number_format($balance, 2) ?></div>
      </div>
      <div class="col-md-3">
        <label for="collectMethod" class="form-label">Received via</label>
        <select class="form-select" id="collectMethod" name="collect_method" required>
          <option value="cash">Cash</option>
          <option value="gcash">GCash</option>
          <option value="bank">Bank Transfer</option>
        </select>
      </div>
      <div class="col-md-3">
        <label for="collectNotes" class="form-label">Notes</label>
        <input type="text" class="form-control" id="collectNotes" name="notes" placeholder="Optional">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-rice w-100">Collect</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php if (!empty($sale['notes'])): ?>
  <div class="alert alert-light border mb-4">
    <strong>Notes:</strong> <?= htmlspecialchars($sale['notes']) ?>
  </div>
<?php endif; ?>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Product</th>
        <th class="text-end">Qty (kg)</th>
        <th class="text-end">Price</th>
        <th class="text-end">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></td>
          <td class="text-end"><?= number_format((float) $item['quantity'], 2) ?></td>
          <td class="text-end">₱<?= number_format((float) $item['price'], 2) ?></td>
          <td class="text-end">₱<?= number_format((float) $item['subtotal'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3" class="text-end fw-semibold">Total</td>
        <td class="text-end fw-bold">₱<?= number_format($total, 2) ?></td>
      </tr>
    </tfoot>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
