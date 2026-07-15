<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Sales';
$activePage = 'sales-history';

$search = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT s.*,
               COALESCE(c.name, 'Walk-in / Just buying') AS customer_name,
               (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= ' AND (c.name LIKE ? OR s.payment_method LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($from !== '') {
    $sql .= ' AND s.sale_date >= ?';
    $params[] = $from;
}

if ($to !== '') {
    $sql .= ' AND s.sale_date <= ?';
    $params[] = $to;
}

if ($statusFilter === 'unpaid' || $statusFilter === 'partial' || $statusFilter === 'paid') {
    $sql .= ' AND s.payment_status = ?';
    $params[] = $statusFilter;
}

$sql .= ' ORDER BY s.sale_date DESC, s.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$paymentLabels = [
    'cash' => 'Cash',
    'gcash' => 'GCash',
    'bank' => 'Bank Transfer',
    'credit' => 'Lend (Utang)',
];

$flash = '';
$flashType = 'success';

if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'deleted' => 'Sale deleted. Stock has been restored.',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'notfound' => 'Sale not found.',
        'invalid' => 'Invalid sale selected.',
        'delete' => 'Could not delete the sale.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Sales History</h1>
    <p class="text-muted mb-0">Paid sales and rice lends (utang).</p>
  </div>
  <a href="sale_new.php" class="btn btn-rice">
    <i class="bi bi-plus-lg"></i> New Sale
  </a>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<form class="row g-2 mb-3" method="GET" action="sales.php">
  <div class="col-md-3">
    <input
      type="search"
      name="q"
      class="form-control"
      placeholder="Search customer or payment..."
      value="<?= htmlspecialchars($search) ?>"
    >
  </div>
  <div class="col-md-2">
    <select name="status" class="form-select">
      <option value="">All status</option>
      <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid (utang)</option>
      <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
      <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
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
    <a href="sales.php" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>#</th>
        <th>Date</th>
        <th>Customer</th>
        <th>Payment</th>
        <th>Status</th>
        <th class="text-end">Total</th>
        <th class="text-end">Balance</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($sales) === 0): ?>
        <tr>
          <td colspan="8" class="text-center text-muted py-4">No sales yet.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($sales as $sale): ?>
          <?php
            $balance = (float) $sale['total'] - (float) $sale['amount_paid'];
            $status = $sale['payment_status'] ?? 'paid';
          ?>
          <tr>
            <td><?= (int) $sale['id'] ?></td>
            <td><?= htmlspecialchars($sale['sale_date']) ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($sale['customer_name']) ?></td>
            <td><?= htmlspecialchars($paymentLabels[$sale['payment_method']] ?? $sale['payment_method']) ?></td>
            <td>
              <?php if ($status === 'unpaid'): ?>
                <span class="badge text-bg-danger">Unpaid</span>
              <?php elseif ($status === 'partial'): ?>
                <span class="badge text-bg-warning">Partial</span>
              <?php else: ?>
                <span class="badge text-bg-success">Paid</span>
              <?php endif; ?>
            </td>
            <td class="text-end">₱<?= number_format((float) $sale['total'], 2) ?></td>
            <td class="text-end <?= $balance > 0 ? 'text-danger fw-semibold' : '' ?>">
              ₱<?= number_format(max(0, $balance), 2) ?>
            </td>
            <td class="text-end text-nowrap">
              <a href="sale_view.php?id=<?= (int) $sale['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
              <a href="sale_edit.php?id=<?= (int) $sale['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
              <form
                method="POST"
                action="/rice-business/backend/sale_delete.php"
                class="d-inline"
                onsubmit="return confirm('Delete this sale? Stock will be restored.');"
              >
                <input type="hidden" name="id" value="<?= (int) $sale['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
