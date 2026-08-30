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

$allProductsForDropdown = $pdo->query(
    "SELECT id, name, product_type FROM products ORDER BY (product_type = 'RICE') DESC, name ASC"
)->fetchAll();

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
    <p class="text-muted mb-0">
      Sell catalog only: names and sell prices. Stock and buy cost live on
      <strong>batches</strong> from <a href="purchase_new.php">Purchases</a>.
      To rebrand rice, reassign the batch in <a href="inventory.php">Inventory</a>
      — do not create a duplicate product. To blend, use <a href="mix.php">Mix Rice</a>.
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#categoryModal">
      <i class="bi bi-tags"></i> Categories
    </button>
    <button type="button" class="btn btn-rice" data-bs-toggle="modal" data-bs-target="#productModal" data-product-type="RICE" id="btnAddRice">
      <i class="bi bi-plus-lg"></i> Add Rice
    </button>
    <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#productModal" data-product-type="GROCERY" id="btnAddGrocery">
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
            $sackBuyPrice = (float) $product['buying_price'] * $kgPerSack;
            $sackSellPrice = isset($product['selling_price_sack']) && $product['selling_price_sack'] !== null
              ? (float) $product['selling_price_sack']
              : round((float) $product['selling_price'] * $kgPerSack, 2);
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
                ₱<?= number_format($sackBuyPrice, 2) ?> / sack
                <div class="small text-muted">₱<?= number_format((float) $product['buying_price'], 2) ?> / kg</div>
              <?php else: ?>
                ₱<?= number_format((float) $product['buying_price'], 2) ?> / <?= htmlspecialchars($unit) ?>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ($productType === 'RICE'): ?>
                ₱<?= number_format($sackSellPrice, 2) ?> / sack
                <div class="small text-muted">₱<?= number_format((float) $product['selling_price'], 2) ?> / kg (small)</div>
              <?php else: ?>
                ₱<?= number_format((float) $product['selling_price'], 2) ?> / <?= htmlspecialchars($unit) ?>
              <?php endif; ?>
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
                data-bs-toggle="modal"
                data-bs-target="#productModal"
                data-id="<?= (int) $product['id'] ?>"
                data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                data-product-type="<?= htmlspecialchars($productType, ENT_QUOTES) ?>"
                data-category="<?= htmlspecialchars($product['category'], ENT_QUOTES) ?>"
                data-unit="<?= htmlspecialchars($unit, ENT_QUOTES) ?>"
                data-kg-per-sack="<?= htmlspecialchars(number_format($kgPerSack, 2, '.', '')) ?>"
                data-sacks="<?= htmlspecialchars(number_format($sacks, 2, '.', '')) ?>"
                data-sack-price="<?= htmlspecialchars(number_format($sackBuyPrice, 2, '.', '')) ?>"
                data-buying-price="<?= htmlspecialchars(number_format((float) $product['buying_price'], 2, '.', '')) ?>"
                data-stock="<?= htmlspecialchars(number_format($stock, 2, '.', '')) ?>"
                data-selling-price="<?= htmlspecialchars($product['selling_price']) ?>"
                data-selling-price-sack="<?= htmlspecialchars(number_format($sackSellPrice, 2, '.', '')) ?>"
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

<!-- Add / Edit product (RICE | GROCERY) -->
<div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="productModalLabel">Add Rice</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="productAddModeWrap" class="mb-3">
          <label class="form-label d-block">What do you want to do?</label>
          <div class="btn-group w-100" role="group" aria-label="Product add mode">
            <input type="radio" class="btn-check" name="productAddMode" id="productModeNew" value="new" checked>
            <label class="btn btn-outline-secondary" for="productModeNew">New product</label>
            <input type="radio" class="btn-check" name="productAddMode" id="productModeExisting" value="existing">
            <label class="btn btn-outline-secondary" for="productModeExisting">Existing (add batch)</label>
          </div>
        </div>

        <div id="productExistingPanel" class="d-none">
          <p class="small text-muted" id="productExistingHint">
            Same rice, different buy price? Pick the product, then stock it as a new batch on a purchase.
          </p>
          <div class="mb-3">
            <label for="productExistingSelect" class="form-label" id="productExistingLabel">Rice product</label>
            <select class="form-select" id="productExistingSelect">
              <option value="">Select…</option>
              <?php foreach ($allProductsForDropdown as $ep): ?>
                <option
                  value="<?= (int) $ep['id'] ?>"
                  data-product-type="<?= htmlspecialchars($ep['product_type'] ?? 'RICE') ?>"
                >
                  <?= htmlspecialchars($ep['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <a href="purchase_new.php" class="btn btn-rice w-100 disabled" id="productContinuePurchase" aria-disabled="true">
            Continue to New Purchase
          </a>
        </div>

        <form method="POST" action="/rice-business/backend/product_save.php" id="productForm">
          <div id="productCatalogFields">
            <input type="hidden" name="id" id="productId" value="">
            <input type="hidden" name="product_type" id="productType" value="RICE">

            <p class="small text-muted" id="productCatalogHint">
              Catalog only — stock comes from Purchases as batches (by sack).
            </p>

            <div class="mb-3" id="productStockReadonlyWrap" hidden>
              <label class="form-label">Current stock</label>
              <div class="form-control-plaintext" id="productStockReadonly">—</div>
              <div class="form-text">Change stock by adding a batch on New Purchase.</div>
            </div>

            <div class="mb-3">
              <label for="productName" class="form-label" id="productNameLabel">Rice Name</label>
              <input type="text" class="form-control" id="productName" name="name" required maxlength="100" placeholder="e.g. Dinorado">
            </div>

            <div id="riceFields">
              <input type="hidden" name="unit" id="riceUnit" value="kg">

              <div class="mb-3">
                <label for="riceCategory" class="form-label">Category</label>
                <select class="form-select" id="riceCategory" name="category" required>
                  <option value="">Select category</option>
                  <?php foreach ($riceCategories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="mb-3">
                <label for="riceKgPerSack" class="form-label">Kg per sack</label>
                <input type="number" class="form-control" id="riceKgPerSack" name="kg_per_sack" step="0.01" min="0.01" value="25" required>
              </div>

              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label for="riceSellingPrice" class="form-label">Sell per kg — small (₱)</label>
                  <input type="number" class="form-control" id="riceSellingPrice" name="selling_price" step="0.01" min="0" required>
                  <div class="form-text">Scooped / small kg (higher for waste)</div>
                </div>
                <div class="col-md-6">
                  <label for="riceSellingPriceSack" class="form-label">Sell per sack (₱)</label>
                  <input type="number" class="form-control" id="riceSellingPriceSack" name="selling_price_sack" step="0.01" min="0" required>
                  <div class="form-text">Whole sack price</div>
                </div>
              </div>

              <div class="mb-3">
                <label for="riceMinSacks" class="form-label">Low stock alert (sacks)</label>
                <input type="number" class="form-control" id="riceMinSacks" name="min_sacks" step="0.01" min="0" value="1" required>
              </div>
            </div>

            <div id="groceryFields" hidden>
              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label for="groceryCategory" class="form-label">Category</label>
                  <select class="form-select" id="groceryCategory" name="category" data-require-when-on="1" disabled>
                    <option value="">Select category</option>
                    <?php foreach ($groceryCategories as $cat): ?>
                      <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label for="groceryUnit" class="form-label">Unit</label>
                  <select class="form-select" id="groceryUnit" name="unit" data-require-when-on="1" disabled>
                    <option value="pc">pc (pieces)</option>
                    <option value="L">L (liters)</option>
                    <option value="ml">ml</option>
                  </select>
                </div>
              </div>

              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label for="grocerySellingPrice" class="form-label" id="grocerySellingLabel">Selling price (₱)</label>
                  <input type="number" class="form-control" id="grocerySellingPrice" name="selling_price" step="0.01" min="0" data-require-when-on="1" disabled>
                </div>
                <div class="col-md-6">
                  <label for="groceryMinStock" class="form-label">Low stock alert</label>
                  <input type="number" class="form-control" id="groceryMinStock" name="minimum_stock" step="0.01" min="0" value="0" data-require-when-on="1" disabled>
                  <div class="form-text" id="groceryUnitHint">Use whole numbers when unit is pc.</div>
                </div>
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
          <div class="modal-footer px-0 pb-0" id="productFormFooter">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rice" id="productSubmitBtn">Save Rice</button>
          </div>
        </form>
      </div>
      <div class="modal-footer d-none" id="productExistingFooter">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
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
  const modalEl = document.getElementById('productModal');
  const typeInput = document.getElementById('productType');
  const riceFields = document.getElementById('riceFields');
  const groceryFields = document.getElementById('groceryFields');

  function currentType() {
    return typeInput.value === 'GROCERY' ? 'GROCERY' : 'RICE';
  }

  function setFieldsEnabled(wrap, enabled) {
    wrap.querySelectorAll('input, select, textarea').forEach(function (el) {
      el.disabled = !enabled;
      if (el.dataset.requireWhenOn === '1') {
        el.required = enabled;
      }
    });
  }

  function updateGroceryUnitUi() {
    const unit = document.getElementById('groceryUnit').value || 'pc';
    const minStock = document.getElementById('groceryMinStock');
    document.getElementById('grocerySellingLabel').textContent = 'Selling price (₱) per ' + unit;
    document.getElementById('groceryUnitHint').textContent =
      unit === 'pc' ? 'Use whole numbers when unit is pc.' : 'Decimals are allowed for ' + unit + '.';
    minStock.step = unit === 'pc' ? '1' : '0.01';
  }

  function filterExistingOptions() {
    const type = currentType();
    const sel = document.getElementById('productExistingSelect');
    sel.querySelectorAll('option[data-product-type]').forEach(function (opt) {
      opt.hidden = opt.dataset.productType !== type;
    });
    const selected = sel.selectedOptions[0];
    if (selected && selected.dataset.productType && selected.dataset.productType !== type) {
      sel.value = '';
    }
  }

  function setProductType(type) {
    const rice = type !== 'GROCERY';
    typeInput.value = rice ? 'RICE' : 'GROCERY';

    riceFields.hidden = !rice;
    groceryFields.hidden = rice;
    setFieldsEnabled(riceFields, rice);
    setFieldsEnabled(groceryFields, !rice);

    document.getElementById('productModalLabel').textContent = rice ? 'Add Rice' : 'Add Other Item';
    document.getElementById('productNameLabel').textContent = rice ? 'Rice Name' : 'Item Name';
    document.getElementById('productName').placeholder = rice ? 'e.g. Dinorado' : 'e.g. Egg';
    document.getElementById('productCatalogHint').textContent = rice
      ? 'Catalog only — stock comes from Purchases as batches (by sack).'
      : 'Catalog only — stock comes from Purchases as batches.';
    document.getElementById('productExistingHint').textContent = rice
      ? 'Same rice, different buy price? Pick the product, then stock it as a new batch on a purchase.'
      : 'Same item, different buy price? Pick it, then stock a new batch on a purchase.';
    document.getElementById('productExistingLabel').textContent = rice ? 'Rice product' : 'Product';
    document.getElementById('productSubmitBtn').textContent = rice ? 'Save Rice' : 'Save Item';
    document.getElementById('productSubmitBtn').classList.toggle('btn-outline-success', !rice);
    document.getElementById('productSubmitBtn').classList.toggle('btn-rice', rice);

    filterExistingOptions();
    if (!rice) {
      updateGroceryUnitUi();
    }
  }

  function setAddMode(mode) {
    const isExisting = mode === 'existing';
    document.getElementById('productExistingPanel').classList.toggle('d-none', !isExisting);
    document.getElementById('productCatalogFields').classList.toggle('d-none', isExisting);
    document.getElementById('productFormFooter').classList.toggle('d-none', isExisting);
    document.getElementById('productExistingFooter').classList.toggle('d-none', !isExisting);
    document.getElementById('productModeNew').checked = !isExisting;
    document.getElementById('productModeExisting').checked = isExisting;
  }

  function updateContinueLink() {
    const sel = document.getElementById('productExistingSelect');
    const link = document.getElementById('productContinuePurchase');
    const id = sel.value;
    if (id) {
      link.href = 'purchase_new.php?product_id=' + encodeURIComponent(id);
      link.classList.remove('disabled');
      link.removeAttribute('aria-disabled');
    } else {
      link.href = 'purchase_new.php';
      link.classList.add('disabled');
      link.setAttribute('aria-disabled', 'true');
    }
  }

  function resetForm(type) {
    document.getElementById('productId').value = '';
    document.getElementById('productName').value = '';
    document.getElementById('riceCategory').value = '';
    document.getElementById('riceKgPerSack').value = '25';
    document.getElementById('riceSellingPrice').value = '';
    document.getElementById('riceSellingPriceSack').value = '';
    document.getElementById('riceMinSacks').value = '1';
    document.getElementById('groceryCategory').value = '';
    document.getElementById('groceryUnit').value = 'pc';
    document.getElementById('groceryMinStock').value = '0';
    document.getElementById('grocerySellingPrice').value = '';
    document.getElementById('productStatus').value = 'active';
    document.getElementById('productAddModeWrap').classList.remove('d-none');
    document.getElementById('productStockReadonlyWrap').hidden = true;
    document.getElementById('productExistingSelect').value = '';
    setProductType(type || 'RICE');
    setAddMode('new');
    updateContinueLink();
  }

  function fillEdit(btn) {
    const type = btn.getAttribute('data-product-type') || 'RICE';
    const rice = type !== 'GROCERY';
    setProductType(type);
    setAddMode('new');

    document.getElementById('productId').value = btn.getAttribute('data-id') || '';
    document.getElementById('productName').value = btn.getAttribute('data-name') || '';
    document.getElementById('productStatus').value = btn.getAttribute('data-status') || 'active';
    document.getElementById('productAddModeWrap').classList.add('d-none');
    document.getElementById('productModalLabel').textContent = rice ? 'Edit Rice' : 'Edit Other Item';
    document.getElementById('productSubmitBtn').textContent = rice ? 'Update Rice' : 'Update Item';

    if (rice) {
      document.getElementById('riceCategory').value = btn.getAttribute('data-category') || '';
      document.getElementById('riceKgPerSack').value = btn.getAttribute('data-kg-per-sack') || '25';
      document.getElementById('riceSellingPrice').value = btn.getAttribute('data-selling-price') || '';
      document.getElementById('riceSellingPriceSack').value = btn.getAttribute('data-selling-price-sack') || '';
      document.getElementById('riceMinSacks').value = btn.getAttribute('data-min-sacks') || '1';
      const stockKg = parseFloat(btn.getAttribute('data-stock')) || 0;
      const sacks = parseFloat(btn.getAttribute('data-sacks')) || 0;
      document.getElementById('productStockReadonly').textContent =
        sacks.toFixed(2) + ' sack (' + stockKg.toFixed(2) + ' kg)';
    } else {
      document.getElementById('groceryCategory').value = btn.getAttribute('data-category') || '';
      document.getElementById('groceryUnit').value = btn.getAttribute('data-unit') || 'pc';
      document.getElementById('groceryMinStock').value = btn.getAttribute('data-min-stock') || '0';
      document.getElementById('grocerySellingPrice').value = btn.getAttribute('data-selling-price') || '';
      const unit = btn.getAttribute('data-unit') || 'pc';
      const stock = parseFloat(btn.getAttribute('data-stock')) || 0;
      const decimals = unit === 'pc' ? 0 : 2;
      document.getElementById('productStockReadonly').textContent =
        stock.toFixed(decimals) + ' ' + unit;
      updateGroceryUnitUi();
    }
    document.getElementById('productStockReadonlyWrap').hidden = false;
  }

  document.getElementById('productModeNew').addEventListener('change', function () {
    if (this.checked) setAddMode('new');
  });
  document.getElementById('productModeExisting').addEventListener('change', function () {
    if (this.checked) setAddMode('existing');
  });
  document.getElementById('productExistingSelect').addEventListener('change', updateContinueLink);
  document.getElementById('groceryUnit').addEventListener('change', updateGroceryUnitUi);

  modalEl.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    const type = (btn && btn.getAttribute('data-product-type')) || 'RICE';
    if (btn && btn.classList.contains('btn-edit-product')) {
      fillEdit(btn);
    } else {
      resetForm(type);
    }
  });
  modalEl.addEventListener('hidden.bs.modal', function () {
    resetForm('RICE');
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
