<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Purchases';
$activePage = 'purchases-history';

$search = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$sql = 'SELECT p.*, s.name AS supplier_name,
               (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = p.id) AS item_count
        FROM purchases p
        INNER JOIN suppliers s ON s.id = p.supplier_id
        WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND s.name LIKE ?';
    $params[] = '%' . $search . '%';
}

if ($from !== '') {
    $sql .= ' AND p.purchase_date >= ?';
    $params[] = $from;
}

if ($to !== '') {
    $sql .= ' AND p.purchase_date <= ?';
    $params[] = $to;
}

$sql .= ' ORDER BY p.purchase_date DESC, p.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$paymentSourceLabels = [
    'business' => 'Business funds',
    'personal' => 'Personal',
];

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Purchase History</h1>
    <p class="text-muted mb-0">All stock-in records from suppliers.</p>
  </div>
  <a href="purchase_new.php" class="btn btn-rice">
    <i class="bi bi-plus-lg"></i> New Purchase
  </a>
</div>

<form class="row g-2 mb-3" method="GET" action="purchases.php">
  <div class="col-md-4">
    <input
      type="search"
      name="q"
      class="form-control"
      placeholder="Search supplier..."
      value="<?= htmlspecialchars($search) ?>"
    >
  </div>
  <div class="col-md-2">
    <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
  </div>
  <div class="col-md-2">
    <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
  </div>
  <div class="col-md-4 d-flex gap-2">
    <button type="submit" class="btn btn-outline-secondary">Filter</button>
    <a href="purchases.php" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>#</th>
        <th>Date</th>
        <th>Supplier</th>
        <th>Paid with</th>
        <th class="text-end">Items</th>
        <th class="text-end">Total</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($purchases) === 0): ?>
        <tr>
          <td colspan="7" class="text-center text-muted py-4">No purchases yet.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($purchases as $purchase): ?>
          <tr>
            <td><?= (int) $purchase['id'] ?></td>
            <td><?= htmlspecialchars($purchase['purchase_date']) ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($purchase['supplier_name']) ?></td>
            <td>
              <?php
                $paymentSource = $purchase['payment_source'] ?? 'business';
                if ($paymentSource === 'personal'):
              ?>
                <span class="badge text-bg-warning"><?= htmlspecialchars($paymentSourceLabels[$paymentSource]) ?></span>
              <?php else: ?>
                <span class="text-muted small"><?= htmlspecialchars($paymentSourceLabels[$paymentSource]) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= (int) $purchase['item_count'] ?></td>
            <td class="text-end">₱<?= number_format((float) $purchase['total'], 2) ?></td>
            <td class="text-end text-nowrap">
              <a href="purchase_view.php?id=<?= (int) $purchase['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
              <a href="purchase_edit.php?id=<?= (int) $purchase['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
