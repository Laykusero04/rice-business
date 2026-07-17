<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Purchase Details';
$activePage = 'purchases-history';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    header('Location: /rice-business/frontend/purchases.php');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT p.*, s.name AS supplier_name, s.contact AS supplier_contact
     FROM purchases p
     INNER JOIN suppliers s ON s.id = p.supplier_id
     WHERE p.id = ?
     LIMIT 1'
);
$stmt->execute([$id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    header('Location: /rice-business/frontend/purchases.php?error=notfound');
    exit;
}

$itemStmt = $pdo->prepare(
    'SELECT pi.*, pr.name AS product_name
     FROM purchase_items pi
     INNER JOIN products pr ON pr.id = pi.product_id
     WHERE pi.purchase_id = ?
     ORDER BY pi.id ASC'
);
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$flash = '';
if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'created' => 'Purchase saved. Stock has been updated.',
        'updated' => 'Purchase updated successfully.',
        default => '',
    };
}

$paymentSourceLabels = [
    'business' => 'Business funds',
    'personal' => 'Personal money (mine)',
];
$paymentSource = $purchase['payment_source'] ?? 'business';

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Purchase #<?= (int) $purchase['id'] ?></h1>
    <p class="text-muted mb-0">Stock-in details</p>
  </div>
  <div class="d-flex gap-2">
    <a href="purchase_edit.php?id=<?= (int) $purchase['id'] ?>" class="btn btn-outline-primary">Edit</a>
    <a href="purchases.php" class="btn btn-outline-secondary">Back to History</a>
    <a href="purchase_new.php" class="btn btn-rice">New Purchase</a>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Supplier</div>
      <div class="fw-semibold"><?= htmlspecialchars($purchase['supplier_name']) ?></div>
      <div class="small text-muted"><?= htmlspecialchars($purchase['supplier_contact'] ?? '') ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Date</div>
      <div class="fw-semibold"><?= htmlspecialchars($purchase['purchase_date']) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Paid with</div>
      <div class="fw-semibold">
        <?php if ($paymentSource === 'personal'): ?>
          <span class="badge text-bg-warning"><?= htmlspecialchars($paymentSourceLabels[$paymentSource]) ?></span>
        <?php else: ?>
          <?= htmlspecialchars($paymentSourceLabels[$paymentSource] ?? 'Business funds') ?>
        <?php endif; ?>
      </div>
      <?php if ($paymentSource === 'personal'): ?>
        <div class="small text-muted mt-1">Recorded in Expenses as Owner Investment</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Total</div>
      <div class="fw-semibold fs-5">₱<?= number_format((float) $purchase['total'], 2) ?></div>
    </div>
  </div>
</div>

<?php if (!empty($purchase['notes'])): ?>
  <div class="alert alert-light border mb-4">
    <strong>Notes:</strong> <?= htmlspecialchars($purchase['notes']) ?>
  </div>
<?php endif; ?>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Product</th>
        <th class="text-end">Qty (kg)</th>
        <th class="text-end">Buying Price</th>
        <th class="text-end">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></td>
          <td class="text-end"><?= number_format((float) $item['quantity'], 2) ?></td>
          <td class="text-end">₱<?= number_format((float) $item['buying_price'], 2) ?></td>
          <td class="text-end">₱<?= number_format((float) $item['subtotal'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3" class="text-end fw-semibold">Total</td>
        <td class="text-end fw-bold">₱<?= number_format((float) $purchase['total'], 2) ?></td>
      </tr>
    </tfoot>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
