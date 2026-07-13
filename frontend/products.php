<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Products';
$activePage = 'products';

$categories = ['Premium', 'Regular', 'Jasmine', 'Special', 'Other'];

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$sql = 'SELECT * FROM products WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND (name LIKE ? OR category LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $sql .= ' AND status = ?';
    $params[] = $statusFilter;
}

$sql .= ' ORDER BY name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$flash = '';
$flashType = 'success';

if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'created' => 'Product added successfully.',
        'updated' => 'Product updated successfully.',
        'deleted' => 'Product deleted successfully.',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'required' => 'Product name and category are required.',
        'invalid' => 'Please check the values you entered.',
        'save' => 'Could not save the product.',
        'delete' => 'Could not delete the product.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Products</h1>
    <p class="text-muted mb-0">Manage rice types by sack. Stock is stored in kg automatically.</p>
  </div>
  <button type="button" class="btn btn-rice" data-bs-toggle="modal" data-bs-target="#productModal" id="btnAddProduct">
    <i class="bi bi-plus-lg"></i> Add Product
  </button>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<form class="row g-2 mb-3" method="GET" action="products.php">
  <div class="col-md-5">
    <input
      type="search"
      name="q"
      class="form-control"
      placeholder="Search by name or category..."
      value="<?= htmlspecialchars($search) ?>"
    >
  </div>
  <div class="col-md-3">
    <select name="status" class="form-select">
      <option value="">All status</option>
      <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <div class="col-md-4 d-flex gap-2">
    <button type="submit" class="btn btn-outline-secondary">Filter</button>
    <a href="products.php" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Product</th>
        <th>Category</th>
        <th class="text-end">Sack</th>
        <th class="text-end">Buy / sack</th>
        <th class="text-end">Sell / kg</th>
        <th class="text-end">Stock</th>
        <th class="text-end">Min Stock</th>
        <th>Status</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($products) === 0): ?>
        <tr>
          <td colspan="9" class="text-center text-muted py-4">No products found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($products as $product): ?>
          <?php
            $kgPerSack = (float) ($product['kg_per_sack'] ?? 25);
            if ($kgPerSack <= 0) {
                $kgPerSack = 25;
            }
            $stockKg = (float) $product['stock'];
            $minKg = (float) $product['minimum_stock'];
            $sacks = $stockKg / $kgPerSack;
            $minSacks = $minKg / $kgPerSack;
            $sackPrice = (float) $product['buying_price'] * $kgPerSack;
            $isLow = $stockKg <= $minKg;
          ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($product['name']) ?></td>
            <td><?= htmlspecialchars($product['category']) ?></td>
            <td class="text-end"><?= number_format($kgPerSack, 0) ?> kg</td>
            <td class="text-end">₱<?= number_format($sackPrice, 2) ?></td>
            <td class="text-end">₱<?= number_format((float) $product['selling_price'], 2) ?></td>
            <td class="text-end <?= $isLow ? 'text-danger fw-semibold' : '' ?>">
              <?= number_format($sacks, 2) ?> sack
              <div class="small text-muted"><?= number_format($stockKg, 2) ?> kg</div>
              <?php if ($isLow): ?>
                <span class="badge text-bg-warning">Low</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?= number_format($minSacks, 2) ?> sack
              <div class="small text-muted"><?= number_format($minKg, 2) ?> kg</div>
            </td>
            <td>
              <?php if ($product['status'] === 'active'): ?>
                <span class="badge text-bg-success">Active</span>
              <?php else: ?>
                <span class="badge text-bg-secondary">Inactive</span>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <button
                type="button"
                class="btn btn-sm btn-outline-primary btn-edit-product"
                data-bs-toggle="modal"
                data-bs-target="#productModal"
                data-id="<?= (int) $product['id'] ?>"
                data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                data-category="<?= htmlspecialchars($product['category'], ENT_QUOTES) ?>"
                data-kg-per-sack="<?= htmlspecialchars(number_format($kgPerSack, 2, '.', '')) ?>"
                data-sacks="<?= htmlspecialchars(number_format($sacks, 2, '.', '')) ?>"
                data-sack-price="<?= htmlspecialchars(number_format($sackPrice, 2, '.', '')) ?>"
                data-selling-price="<?= htmlspecialchars($product['selling_price']) ?>"
                data-min-sacks="<?= htmlspecialchars(number_format($minSacks, 2, '.', '')) ?>"
                data-status="<?= htmlspecialchars($product['status']) ?>"
              >
                Edit
              </button>
              <form
                method="POST"
                action="/rice-business/backend/product_delete.php"
                class="d-inline"
                onsubmit="return confirm('Delete this product?');"
              >
                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/product_save.php">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="productModalLabel">Add Product</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="productId" value="">

          <div class="mb-3">
            <label for="productName" class="form-label">Product Name</label>
            <input type="text" class="form-control" id="productName" name="name" required maxlength="100" placeholder="e.g. RC 160">
          </div>

          <div class="mb-3">
            <label for="productCategory" class="form-label">Category</label>
            <select class="form-select" id="productCategory" name="category" required>
              <option value="">Select category</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="border rounded p-3 mb-3 bg-light">
            <div class="fw-semibold mb-2">Buy from supplier (by sack)</div>
            <div class="row g-3">
              <div class="col-md-4">
                <label for="kgPerSack" class="form-label">Kg per sack</label>
                <input type="number" class="form-control sack-calc" id="kgPerSack" name="kg_per_sack" step="0.01" min="0.01" value="25" required>
              </div>
              <div class="col-md-4">
                <label for="sacks" class="form-label">How many sacks?</label>
                <input type="number" class="form-control sack-calc" id="sacks" name="sacks" step="0.01" min="0" value="0" required>
              </div>
              <div class="col-md-4">
                <label for="sackPrice" class="form-label">Price per sack (₱)</label>
                <input type="number" class="form-control sack-calc" id="sackPrice" name="sack_price" step="0.01" min="0" value="0" required>
              </div>
            </div>
            <div class="small text-muted mt-2" id="sackSummary">
              Stock: 0.00 kg · Buy price: ₱0.00 / kg
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="sellingPrice" class="form-label">Selling Price per kg (₱)</label>
              <input type="number" class="form-control" id="sellingPrice" name="selling_price" step="0.01" min="0" required>
            </div>
            <div class="col-md-6">
              <label for="minSacks" class="form-label">Low stock alert (sacks)</label>
              <input type="number" class="form-control sack-calc" id="minSacks" name="min_sacks" step="0.01" min="0" value="1" required>
            </div>
          </div>

          <div class="mb-0">
            <label for="productStatus" class="form-label">Status</label>
            <select class="form-select" id="productStatus" name="status">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice" id="productSubmitBtn">Save Product</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('productModal');
  const title = document.getElementById('productModalLabel');
  const submitBtn = document.getElementById('productSubmitBtn');
  const summary = document.getElementById('sackSummary');

  function formatMoney(value) {
    return '₱' + Number(value).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function updateSackSummary() {
    const kgPerSack = parseFloat(document.getElementById('kgPerSack').value) || 0;
    const sacks = parseFloat(document.getElementById('sacks').value) || 0;
    const sackPrice = parseFloat(document.getElementById('sackPrice').value) || 0;
    const stockKg = sacks * kgPerSack;
    const buyPerKg = kgPerSack > 0 ? sackPrice / kgPerSack : 0;

    summary.textContent =
      'Stock: ' + stockKg.toFixed(2) + ' kg · Buy price: ' + formatMoney(buyPerKg) + ' / kg';
  }

  function resetForm() {
    document.getElementById('productId').value = '';
    document.getElementById('productName').value = '';
    document.getElementById('productCategory').value = '';
    document.getElementById('kgPerSack').value = '25';
    document.getElementById('sacks').value = '0';
    document.getElementById('sackPrice').value = '0';
    document.getElementById('sellingPrice').value = '';
    document.getElementById('minSacks').value = '1';
    document.getElementById('productStatus').value = 'active';
    title.textContent = 'Add Product';
    submitBtn.textContent = 'Save Product';
    updateSackSummary();
  }

  document.querySelectorAll('.sack-calc').forEach(function (input) {
    input.addEventListener('input', updateSackSummary);
  });

  document.getElementById('btnAddProduct').addEventListener('click', resetForm);

  document.querySelectorAll('.btn-edit-product').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('productId').value = btn.dataset.id;
      document.getElementById('productName').value = btn.dataset.name;
      document.getElementById('productCategory').value = btn.dataset.category;
      document.getElementById('kgPerSack').value = btn.dataset.kgPerSack;
      document.getElementById('sacks').value = btn.dataset.sacks;
      document.getElementById('sackPrice').value = btn.dataset.sackPrice;
      document.getElementById('sellingPrice').value = btn.dataset.sellingPrice;
      document.getElementById('minSacks').value = btn.dataset.minSacks;
      document.getElementById('productStatus').value = btn.dataset.status;
      title.textContent = 'Edit Product';
      submitBtn.textContent = 'Update Product';
      updateSackSummary();
    });
  });

  modal.addEventListener('hidden.bs.modal', resetForm);
  updateSackSummary();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
