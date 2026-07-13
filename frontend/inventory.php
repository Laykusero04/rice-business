<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Inventory';
$activePage = 'inventory';

$typeFilter = trim($_GET['type'] ?? '');
$search = trim($_GET['q'] ?? '');

$products = $pdo->query(
    "SELECT id, name, category, stock, minimum_stock, status
     FROM products
     ORDER BY name ASC"
)->fetchAll();

$movementSql = 'SELECT sm.*, p.name AS product_name
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                WHERE 1=1';
$params = [];

if (in_array($typeFilter, ['IN', 'OUT', 'ADJUSTMENT'], true)) {
    $movementSql .= ' AND sm.type = ?';
    $params[] = $typeFilter;
}

if ($search !== '') {
    $movementSql .= ' AND (p.name LIKE ? OR sm.reference LIKE ? OR sm.notes LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$movementSql .= ' ORDER BY sm.id DESC LIMIT 100';

$movementStmt = $pdo->prepare($movementSql);
$movementStmt->execute($params);
$movements = $movementStmt->fetchAll();

$flash = '';
$flashType = 'success';

if (isset($_GET['success']) && $_GET['success'] === 'adjusted') {
    $flash = 'Stock adjusted successfully.';
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'invalid' => 'Please select a product and enter a non-zero quantity.',
        'stock' => 'Adjustment would make stock negative.',
        'save' => 'Could not adjust stock.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Inventory</h1>
    <p class="text-muted mb-0">Current stock levels and movement history.</p>
  </div>
  <button type="button" class="btn btn-rice" data-bs-toggle="modal" data-bs-target="#adjustModal">
    <i class="bi bi-sliders"></i> Adjust Stock
  </button>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="bg-white rounded shadow-sm p-3">
      <h2 class="h6 mb-3">Current Stock</h2>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th class="text-end">Stock (kg)</th>
              <th class="text-end">Min Stock</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($products) === 0): ?>
              <tr>
                <td colspan="5" class="text-center text-muted">No products found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($products as $product): ?>
                <?php $isLow = (float) $product['stock'] <= (float) $product['minimum_stock']; ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars($product['name']) ?></td>
                  <td><?= htmlspecialchars($product['category']) ?></td>
                  <td class="text-end <?= $isLow ? 'text-danger fw-semibold' : '' ?>">
                    <?= number_format((float) $product['stock'], 2) ?>
                    <?php if ($isLow): ?>
                      <span class="badge text-bg-warning ms-1">Low</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end"><?= number_format((float) $product['minimum_stock'], 2) ?></td>
                  <td>
                    <?php if ($product['status'] === 'active'): ?>
                      <span class="badge text-bg-success">Active</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary">Inactive</span>
                    <?php endif; ?>
                  </td>
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
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h6 mb-0">Stock Movements</h2>
  </div>

  <form class="row g-2 mb-3" method="GET" action="inventory.php">
    <div class="col-md-5">
      <input
        type="search"
        name="q"
        class="form-control"
        placeholder="Search product, reference, or notes..."
        value="<?= htmlspecialchars($search) ?>"
      >
    </div>
    <div class="col-md-3">
      <select name="type" class="form-select">
        <option value="">All types</option>
        <option value="IN" <?= $typeFilter === 'IN' ? 'selected' : '' ?>>Stock In</option>
        <option value="OUT" <?= $typeFilter === 'OUT' ? 'selected' : '' ?>>Stock Out</option>
        <option value="ADJUSTMENT" <?= $typeFilter === 'ADJUSTMENT' ? 'selected' : '' ?>>Adjustment</option>
      </select>
    </div>
    <div class="col-md-4 d-flex gap-2">
      <button type="submit" class="btn btn-outline-secondary">Filter</button>
      <a href="inventory.php" class="btn btn-outline-secondary">Reset</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Date</th>
          <th>Product</th>
          <th>Type</th>
          <th class="text-end">Qty (kg)</th>
          <th>Reference</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($movements) === 0): ?>
          <tr>
            <td colspan="6" class="text-center text-muted py-4">No stock movements found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($movements as $move): ?>
            <tr>
              <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($move['created_at']))) ?></td>
              <td class="fw-semibold"><?= htmlspecialchars($move['product_name']) ?></td>
              <td>
                <?php if ($move['type'] === 'IN'): ?>
                  <span class="badge text-bg-success">IN</span>
                <?php elseif ($move['type'] === 'OUT'): ?>
                  <span class="badge text-bg-danger">OUT</span>
                <?php else: ?>
                  <span class="badge text-bg-secondary">ADJUST</span>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= number_format((float) $move['quantity'], 2) ?></td>
              <td><?= htmlspecialchars($move['reference'] ?? '—') ?></td>
              <td class="small text-muted"><?= htmlspecialchars($move['notes'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="adjustModal" tabindex="-1" aria-labelledby="adjustModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/inventory_adjust.php">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="adjustModalLabel">Adjust Stock</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="adjustProduct" class="form-label">Product</label>
            <select class="form-select" id="adjustProduct" name="product_id" required>
              <option value="">Select product</option>
              <?php foreach ($products as $product): ?>
                <option value="<?= (int) $product['id'] ?>">
                  <?= htmlspecialchars($product['name']) ?>
                  (<?= number_format((float) $product['stock'], 2) ?> kg)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label for="adjustQty" class="form-label">Quantity (kg)</label>
            <input
              type="number"
              class="form-control"
              id="adjustQty"
              name="quantity"
              step="0.01"
              required
              placeholder="Use + to add, - to deduct"
            >
            <div class="form-text">Example: 10 adds stock, -5 deducts stock.</div>
          </div>
          <div class="mb-0">
            <label for="adjustNotes" class="form-label">Notes</label>
            <input type="text" class="form-control" id="adjustNotes" name="notes" placeholder="Optional reason">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice">Save Adjustment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
