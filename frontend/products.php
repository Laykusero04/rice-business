<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Products';
$activePage = 'products';

$riceCategories = [];
$groceryCategories = [];

try {
    $categoryRows = $pdo->query(
        'SELECT id, name, product_type FROM product_categories ORDER BY name ASC'
    )->fetchAll();

    foreach ($categoryRows as $row) {
        if (($row['product_type'] ?? '') === 'GROCERY') {
            $groceryCategories[] = $row;
        } else {
            $riceCategories[] = $row;
        }
    }
} catch (PDOException $e) {
    // product_categories table may not exist until migration is run
}

if (count($riceCategories) === 0) {
    foreach (['Premium', 'Regular', 'Jasmine', 'Special'] as $name) {
        $riceCategories[] = ['id' => 0, 'name' => $name, 'product_type' => 'RICE'];
    }
}
if (count($groceryCategories) === 0) {
    foreach (['Eggs', 'Oil', 'Other'] as $name) {
        $groceryCategories[] = ['id' => 0, 'name' => $name, 'product_type' => 'GROCERY'];
    }
}

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$typeFilter = strtoupper(trim($_GET['type'] ?? 'ALL'));
if (!in_array($typeFilter, ['RICE', 'GROCERY', 'ALL'], true)) {
    $typeFilter = 'ALL';
}

$typeCounts = $pdo->query(
    "SELECT product_type, COUNT(*) AS total
     FROM products
     GROUP BY product_type"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$riceCount = (int) ($typeCounts['RICE'] ?? 0);
$groceryCount = (int) ($typeCounts['GROCERY'] ?? 0);
$allCount = $riceCount + $groceryCount;

$filterQuery = [];
if ($search !== '') {
    $filterQuery['q'] = $search;
}
if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $filterQuery['status'] = $statusFilter;
}

function productsFilterUrl(string $type, array $filterQuery): string
{
    $params = array_merge(['type' => $type], $filterQuery);
    return 'products.php?' . http_build_query($params);
}

$sql = 'SELECT * FROM products WHERE 1=1';
$params = [];

if ($typeFilter !== 'ALL') {
    $sql .= ' AND product_type = ?';
    $params[] = $typeFilter;
}

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
        'category_created' => 'Category added successfully.',
        'category_updated' => 'Category updated successfully.',
        'category_deleted' => 'Category deleted successfully.',
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
        'category_required' => 'Category name is required.',
        'category_invalid' => 'Please choose a valid category.',
        'category_duplicate' => 'That category already exists for this product type.',
        'category_in_use' => 'Cannot delete a category that is used by products.',
        'category_missing' => 'Category not found.',
        'category_save' => 'Could not save the category.',
        'category_delete' => 'Could not delete the category.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Products</h1>
    <p class="text-muted mb-0">Rice is managed by sack (stored in kg). Other items are managed directly by unit (pc/L/ml).</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#categoryModal">
      <i class="bi bi-tags"></i> Categories
    </button>
    <button type="button" class="btn btn-rice" data-bs-toggle="modal" data-bs-target="#riceModal" id="btnAddRice">
      <i class="bi bi-plus-lg"></i> Add Rice
    </button>
    <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#groceryModal" id="btnAddGrocery">
      <i class="bi bi-plus-lg"></i> Add Other Item
    </button>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $typeFilter === 'ALL' ? 'active' : '' ?>" href="<?= htmlspecialchars(productsFilterUrl('ALL', $filterQuery)) ?>">
      All <span class="badge text-bg-secondary ms-1"><?= $allCount ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $typeFilter === 'RICE' ? 'active' : '' ?>" href="<?= htmlspecialchars(productsFilterUrl('RICE', $filterQuery)) ?>">
      Rice <span class="badge text-bg-secondary ms-1"><?= $riceCount ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $typeFilter === 'GROCERY' ? 'active' : '' ?>" href="<?= htmlspecialchars(productsFilterUrl('GROCERY', $filterQuery)) ?>">
      Other items <span class="badge text-bg-secondary ms-1"><?= $groceryCount ?></span>
    </a>
  </li>
</ul>

<form class="row g-2 mb-3" method="GET" action="products.php">
  <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>">
  <div class="col-md-6">
    <input
      type="search"
      name="q"
      class="form-control"
      placeholder="Search by name or category..."
      value="<?= htmlspecialchars($search) ?>"
    >
  </div>
  <div class="col-md-4">
    <select name="status" class="form-select">
      <option value="">All status</option>
      <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <div class="col-md-2 d-flex gap-2">
    <button type="submit" class="btn btn-outline-secondary">Filter</button>
    <a href="<?= htmlspecialchars(productsFilterUrl($typeFilter, [])) ?>" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Product</th>
        <th>Type</th>
        <th>Category</th>
        <th>Unit</th>
        <th class="text-end">Buy</th>
        <th class="text-end">Sell</th>
        <th class="text-end">Stock</th>
        <th class="text-end">Min Stock</th>
        <th>Status</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($products) === 0): ?>
        <tr>
          <td colspan="10" class="text-center text-muted py-4">
            <?php if ($typeFilter === 'GROCERY'): ?>
              No other items yet. Click <strong>Add Other Item</strong> to add egg, oil, etc.
            <?php elseif ($typeFilter === 'RICE'): ?>
              No rice products yet. Click <strong>Add Rice</strong> to get started.
            <?php else: ?>
              No products found.
            <?php endif; ?>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($products as $product): ?>
          <?php
            $productType = $product['product_type'] ?? 'RICE';
            $unit = $product['unit'] ?? 'kg';
            $kgPerSack = (float) ($product['kg_per_sack'] ?? 25);
            if ($kgPerSack <= 0) {
                $kgPerSack = 25;
            }
            $stock = (float) $product['stock'];
            $minStock = (float) $product['minimum_stock'];
            $sacks = $productType === 'RICE' ? ($stock / $kgPerSack) : 0.0;
            $minSacks = $productType === 'RICE' ? ($minStock / $kgPerSack) : 0.0;
            $sackPrice = (float) $product['buying_price'] * $kgPerSack;
            $isLow = $stock <= $minStock;
          ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($product['name']) ?></td>
            <td>
              <?php if ($productType === 'RICE'): ?>
                <span class="badge text-bg-primary">Rice</span>
              <?php else: ?>
                <span class="badge text-bg-info">Grocery</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($product['category']) ?></td>
            <td><?= htmlspecialchars($unit) ?></td>
            <td class="text-end">
              <?php if ($productType === 'RICE'): ?>
                ₱<?= number_format($sackPrice, 2) ?>
                <div class="small text-muted">₱<?= number_format((float) $product['buying_price'], 2) ?> / kg</div>
              <?php else: ?>
                ₱<?= number_format((float) $product['buying_price'], 2) ?> / <?= htmlspecialchars($unit) ?>
              <?php endif; ?>
            </td>
            <td class="text-end">
              ₱<?= number_format((float) $product['selling_price'], 2) ?> / <?= htmlspecialchars($unit) ?>
            </td>
            <td class="text-end <?= $isLow ? 'text-danger fw-semibold' : '' ?>">
              <?php if ($productType === 'RICE'): ?>
                <?= number_format($sacks, 2) ?> sack
                <div class="small text-muted"><?= number_format($stock, 2) ?> kg</div>
              <?php else: ?>
                <?= number_format($stock, $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
              <?php endif; ?>
              <?php if ($isLow): ?>
                <span class="badge text-bg-warning">Low</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ($productType === 'RICE'): ?>
                <?= number_format($minSacks, 2) ?> sack
                <div class="small text-muted"><?= number_format($minStock, 2) ?> kg</div>
              <?php else: ?>
                <?= number_format($minStock, $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
              <?php endif; ?>
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
                data-id="<?= (int) $product['id'] ?>"
                data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                data-product-type="<?= htmlspecialchars($productType, ENT_QUOTES) ?>"
                data-category="<?= htmlspecialchars($product['category'], ENT_QUOTES) ?>"
                data-unit="<?= htmlspecialchars($unit, ENT_QUOTES) ?>"
                data-kg-per-sack="<?= htmlspecialchars(number_format($kgPerSack, 2, '.', '')) ?>"
                data-sacks="<?= htmlspecialchars(number_format($sacks, 2, '.', '')) ?>"
                data-sack-price="<?= htmlspecialchars(number_format($sackPrice, 2, '.', '')) ?>"
                data-buying-price="<?= htmlspecialchars(number_format((float) $product['buying_price'], 2, '.', '')) ?>"
                data-stock="<?= htmlspecialchars(number_format($stock, 2, '.', '')) ?>"
                data-selling-price="<?= htmlspecialchars($product['selling_price']) ?>"
                data-min-sacks="<?= htmlspecialchars(number_format($minSacks, 2, '.', '')) ?>"
                data-min-stock="<?= htmlspecialchars(number_format($minStock, 2, '.', '')) ?>"
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

<!-- Add / Edit Rice -->
<div class="modal fade" id="riceModal" tabindex="-1" aria-labelledby="riceModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/product_save.php" id="riceForm">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="riceModalLabel">Add Rice</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="riceProductId" value="">
          <input type="hidden" name="product_type" value="RICE">
          <input type="hidden" name="unit" value="kg">

          <p class="small text-muted">Rice is bought by sack from suppliers. Stock is stored in kg automatically.</p>

          <div class="mb-3">
            <label for="riceName" class="form-label">Rice Name</label>
            <input type="text" class="form-control" id="riceName" name="name" required maxlength="100" placeholder="e.g. Dinorado">
          </div>

          <div class="mb-3">
            <label for="riceCategory" class="form-label">Category</label>
            <select class="form-select" id="riceCategory" name="category" required>
              <option value="">Select category</option>
              <?php foreach ($riceCategories as $cat): ?>
                <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="border rounded p-3 mb-3 bg-light">
            <div class="fw-semibold mb-2">Buy from supplier (by sack)</div>
            <div class="row g-3">
              <div class="col-md-4">
                <label for="riceKgPerSack" class="form-label">Kg per sack</label>
                <input type="number" class="form-control rice-sack-calc" id="riceKgPerSack" name="kg_per_sack" step="0.01" min="0.01" value="25" required>
              </div>
              <div class="col-md-4">
                <label for="riceSacks" class="form-label">How many sacks?</label>
                <input type="number" class="form-control rice-sack-calc" id="riceSacks" name="sacks" step="0.01" min="0" value="0" required>
              </div>
              <div class="col-md-4">
                <label for="riceSackPrice" class="form-label">Price per sack (₱)</label>
                <input type="number" class="form-control rice-sack-calc" id="riceSackPrice" name="sack_price" step="0.01" min="0" value="0" required>
              </div>
            </div>
            <div class="small text-muted mt-2" id="riceSackSummary">Stock: 0.00 kg · Buy price: ₱0.00 / kg</div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="riceSellingPrice" class="form-label">Selling price per kg (₱)</label>
              <input type="number" class="form-control" id="riceSellingPrice" name="selling_price" step="0.01" min="0" required>
              <div class="form-text" id="riceIdealSellHint">Ideal: enter sack price to see a suggested sell price</div>
            </div>
            <div class="col-md-6">
              <label for="riceMinSacks" class="form-label">Low stock alert (sacks)</label>
              <input type="number" class="form-control rice-sack-calc" id="riceMinSacks" name="min_sacks" step="0.01" min="0" value="1" required>
            </div>
          </div>

          <div class="mb-0">
            <label for="riceStatus" class="form-label">Status</label>
            <select class="form-select" id="riceStatus" name="status">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice" id="riceSubmitBtn">Save Rice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add / Edit Other Item -->
<div class="modal fade" id="groceryModal" tabindex="-1" aria-labelledby="groceryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/product_save.php" id="groceryForm">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="groceryModalLabel">Add Other Item</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="groceryProductId" value="">
          <input type="hidden" name="product_type" value="GROCERY">

          <p class="small text-muted">For egg, oil, and similar items. Stock and prices are tracked by unit (pc, L, ml).</p>

          <div class="mb-3">
            <label for="groceryName" class="form-label">Item Name</label>
            <input type="text" class="form-control" id="groceryName" name="name" required maxlength="100" placeholder="e.g. Egg">
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="groceryCategory" class="form-label">Category</label>
              <select class="form-select" id="groceryCategory" name="category" required>
                <option value="">Select category</option>
                <?php foreach ($groceryCategories as $cat): ?>
                  <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label for="groceryUnit" class="form-label">Unit</label>
              <select class="form-select" id="groceryUnit" name="unit" required>
                <option value="pc">pc (pieces)</option>
                <option value="L">L (liters)</option>
                <option value="ml">ml</option>
              </select>
            </div>
          </div>

          <div class="border rounded p-3 mb-3 bg-light">
            <div class="fw-semibold mb-2">Stock and cost</div>
            <div class="row g-3">
              <div class="col-md-4">
                <label for="groceryStock" class="form-label">Stock</label>
                <input type="number" class="form-control" id="groceryStock" name="stock" step="0.01" min="0" value="0" required>
              </div>
              <div class="col-md-4">
                <label for="groceryBuyingPrice" class="form-label">Buying price (₱)</label>
                <input type="number" class="form-control" id="groceryBuyingPrice" name="buying_price" step="0.01" min="0" value="0" required>
              </div>
              <div class="col-md-4">
                <label for="groceryMinStock" class="form-label">Low stock alert</label>
                <input type="number" class="form-control" id="groceryMinStock" name="minimum_stock" step="0.01" min="0" value="0" required>
              </div>
            </div>
            <div class="form-text" id="groceryUnitHint">Use whole numbers when unit is pc.</div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="grocerySellingPrice" class="form-label" id="grocerySellingLabel">Selling price (₱)</label>
              <input type="number" class="form-control" id="grocerySellingPrice" name="selling_price" step="0.01" min="0" required>
            </div>
            <div class="col-md-6">
              <label for="groceryStatus" class="form-label">Status</label>
              <select class="form-select" id="groceryStatus" name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-outline-success" id="grocerySubmitBtn">Save Item</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Manage Categories -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="categoryModalLabel">Product Categories</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">Rice categories and other-item categories are managed separately.</p>
        <div class="row g-4">
          <div class="col-md-6">
            <h3 class="h6">Rice categories</h3>
            <form method="POST" action="/rice-business/backend/category_save.php" class="row g-2 mb-3">
              <input type="hidden" name="product_type" value="RICE">
              <div class="col-8">
                <input type="text" name="name" class="form-control form-control-sm" placeholder="New rice category" maxlength="50" required>
              </div>
              <div class="col-4">
                <button type="submit" class="btn btn-sm btn-rice w-100">Add</button>
              </div>
            </form>
            <ul class="list-group list-group-flush">
              <?php foreach ($riceCategories as $cat): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                  <span><?= htmlspecialchars($cat['name']) ?></span>
                  <?php if ((int) ($cat['id'] ?? 0) > 0): ?>
                    <form method="POST" action="/rice-business/backend/category_delete.php" class="d-inline" onsubmit="return confirm('Delete this category?');">
                      <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="col-md-6">
            <h3 class="h6">Other item categories</h3>
            <form method="POST" action="/rice-business/backend/category_save.php" class="row g-2 mb-3">
              <input type="hidden" name="product_type" value="GROCERY">
              <div class="col-8">
                <input type="text" name="name" class="form-control form-control-sm" placeholder="New item category" maxlength="50" required>
              </div>
              <div class="col-4">
                <button type="submit" class="btn btn-sm btn-outline-success w-100">Add</button>
              </div>
            </form>
            <ul class="list-group list-group-flush">
              <?php foreach ($groceryCategories as $cat): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                  <span><?= htmlspecialchars($cat['name']) ?></span>
                  <?php if ((int) ($cat['id'] ?? 0) > 0): ?>
                    <form method="POST" action="/rice-business/backend/category_delete.php" class="d-inline" onsubmit="return confirm('Delete this category?');">
                      <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const riceModalEl = document.getElementById('riceModal');
  const groceryModalEl = document.getElementById('groceryModal');
  const riceModal = bootstrap.Modal.getOrCreateInstance(riceModalEl);
  const groceryModal = bootstrap.Modal.getOrCreateInstance(groceryModalEl);

  function formatMoney(value) {
    return '₱' + Number(value).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function updateRiceSackSummary() {
    const kgPerSack = parseFloat(document.getElementById('riceKgPerSack').value) || 0;
    const sacks = parseFloat(document.getElementById('riceSacks').value) || 0;
    const sackPrice = parseFloat(document.getElementById('riceSackPrice').value) || 0;
    const stockKg = sacks * kgPerSack;
    const buyPerKg = kgPerSack > 0 ? sackPrice / kgPerSack : 0;
    document.getElementById('riceSackSummary').textContent =
      'Stock: ' + stockKg.toFixed(2) + ' kg · Buy price: ' + formatMoney(buyPerKg) + ' / kg';

    const idealHint = document.getElementById('riceIdealSellHint');
    if (buyPerKg > 0) {
      // ~20% over buy cost — matches typical rice margins in this app
      const idealSell = Math.round(buyPerKg * 1.2 * 100) / 100;
      idealHint.textContent =
        'Ideal selling price: ' + formatMoney(idealSell) + ' / kg (~20% over buy)';
    } else {
      idealHint.textContent = 'Ideal: enter sack price to see a suggested sell price';
    }
  }

  function updateGroceryUnitUi() {
    const unit = document.getElementById('groceryUnit').value || 'pc';
    const stock = document.getElementById('groceryStock');
    const minStock = document.getElementById('groceryMinStock');
    document.getElementById('grocerySellingLabel').textContent = 'Selling price (₱) per ' + unit;
    document.getElementById('groceryUnitHint').textContent =
      unit === 'pc' ? 'Use whole numbers when unit is pc.' : 'Decimals are allowed for ' + unit + '.';
    if (unit === 'pc') {
      stock.step = '1';
      minStock.step = '1';
    } else {
      stock.step = '0.01';
      minStock.step = '0.01';
    }
  }

  function resetRiceForm() {
    document.getElementById('riceProductId').value = '';
    document.getElementById('riceName').value = '';
    document.getElementById('riceCategory').value = '';
    document.getElementById('riceKgPerSack').value = '25';
    document.getElementById('riceSacks').value = '0';
    document.getElementById('riceSackPrice').value = '0';
    document.getElementById('riceSellingPrice').value = '';
    document.getElementById('riceMinSacks').value = '1';
    document.getElementById('riceStatus').value = 'active';
    document.getElementById('riceModalLabel').textContent = 'Add Rice';
    document.getElementById('riceSubmitBtn').textContent = 'Save Rice';
    updateRiceSackSummary();
  }

  function resetGroceryForm() {
    document.getElementById('groceryProductId').value = '';
    document.getElementById('groceryName').value = '';
    document.getElementById('groceryCategory').value = '';
    document.getElementById('groceryUnit').value = 'pc';
    document.getElementById('groceryStock').value = '0';
    document.getElementById('groceryBuyingPrice').value = '0';
    document.getElementById('groceryMinStock').value = '0';
    document.getElementById('grocerySellingPrice').value = '';
    document.getElementById('groceryStatus').value = 'active';
    document.getElementById('groceryModalLabel').textContent = 'Add Other Item';
    document.getElementById('grocerySubmitBtn').textContent = 'Save Item';
    updateGroceryUnitUi();
  }

  document.querySelectorAll('.rice-sack-calc').forEach(function (input) {
    input.addEventListener('input', updateRiceSackSummary);
  });
  document.getElementById('groceryUnit').addEventListener('change', updateGroceryUnitUi);

  document.getElementById('btnAddRice').addEventListener('click', resetRiceForm);
  document.getElementById('btnAddGrocery').addEventListener('click', resetGroceryForm);

  document.querySelectorAll('.btn-edit-product').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const isRice = (btn.dataset.productType || 'RICE') === 'RICE';
      if (isRice) {
        document.getElementById('riceProductId').value = btn.dataset.id;
        document.getElementById('riceName').value = btn.dataset.name;
        document.getElementById('riceCategory').value = btn.dataset.category;
        document.getElementById('riceKgPerSack').value = btn.dataset.kgPerSack;
        document.getElementById('riceSacks').value = btn.dataset.sacks;
        document.getElementById('riceSackPrice').value = btn.dataset.sackPrice;
        document.getElementById('riceSellingPrice').value = btn.dataset.sellingPrice;
        document.getElementById('riceMinSacks').value = btn.dataset.minSacks;
        document.getElementById('riceStatus').value = btn.dataset.status;
        document.getElementById('riceModalLabel').textContent = 'Edit Rice';
        document.getElementById('riceSubmitBtn').textContent = 'Update Rice';
        updateRiceSackSummary();
        riceModal.show();
      } else {
        document.getElementById('groceryProductId').value = btn.dataset.id;
        document.getElementById('groceryName').value = btn.dataset.name;
        document.getElementById('groceryCategory').value = btn.dataset.category;
        document.getElementById('groceryUnit').value = btn.dataset.unit || 'pc';
        document.getElementById('groceryStock').value = btn.dataset.stock || '0';
        document.getElementById('groceryBuyingPrice').value = btn.dataset.buyingPrice || '0';
        document.getElementById('groceryMinStock').value = btn.dataset.minStock || '0';
        document.getElementById('grocerySellingPrice').value = btn.dataset.sellingPrice;
        document.getElementById('groceryStatus').value = btn.dataset.status;
        document.getElementById('groceryModalLabel').textContent = 'Edit Other Item';
        document.getElementById('grocerySubmitBtn').textContent = 'Update Item';
        updateGroceryUnitUi();
        groceryModal.show();
      }
    });
  });

  riceModalEl.addEventListener('hidden.bs.modal', resetRiceForm);
  groceryModalEl.addEventListener('hidden.bs.modal', resetGroceryForm);
  updateRiceSackSummary();
  updateGroceryUnitUi();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
