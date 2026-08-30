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

$allRiceForDropdown = $pdo->query(
    "SELECT id, name FROM products WHERE product_type = 'RICE' ORDER BY name ASC"
)->fetchAll();
$allGroceryForDropdown = $pdo->query(
    "SELECT id, name FROM products WHERE product_type = 'GROCERY' ORDER BY name ASC"
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
      Products are the catalog. Add stock as priced <strong>batches</strong> via
      <a href="purchase_new.php">New Purchase</a> — same product can have different buy prices.
    </p>
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

<!-- Add / Edit Rice -->
<div class="modal fade" id="riceModal" tabindex="-1" aria-labelledby="riceModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="riceModalLabel">Add Rice</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="riceAddModeWrap" class="mb-3">
          <label class="form-label d-block">What do you want to do?</label>
          <div class="btn-group w-100" role="group" aria-label="Rice add mode">
            <input type="radio" class="btn-check" name="riceAddMode" id="riceModeNew" value="new" checked>
            <label class="btn btn-outline-secondary" for="riceModeNew">New product</label>
            <input type="radio" class="btn-check" name="riceAddMode" id="riceModeExisting" value="existing">
            <label class="btn btn-outline-secondary" for="riceModeExisting">Existing (add batch)</label>
          </div>
        </div>

        <div id="riceExistingPanel" class="d-none">
          <p class="small text-muted">
            Same rice, different buy price? Pick the product, then stock it as a new batch on a purchase.
          </p>
          <div class="mb-3">
            <label for="riceExistingSelect" class="form-label">Rice product</label>
            <select class="form-select" id="riceExistingSelect">
              <option value="">Select rice…</option>
              <?php foreach ($allRiceForDropdown as $rp): ?>
                <option value="<?= (int) $rp['id'] ?>"><?= htmlspecialchars($rp['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <a href="purchase_new.php" class="btn btn-rice w-100 disabled" id="riceContinuePurchase" aria-disabled="true">
            Continue to New Purchase
          </a>
        </div>

        <form method="POST" action="/rice-business/backend/product_save.php" id="riceForm">
          <div id="riceCatalogFields">
            <input type="hidden" name="id" id="riceProductId" value="">
            <input type="hidden" name="product_type" value="RICE">
            <input type="hidden" name="unit" value="kg">

            <p class="small text-muted" id="riceCatalogHint">
              Catalog only — stock comes from Purchases as batches (by sack).
            </p>

            <div class="mb-3" id="riceStockReadonlyWrap" hidden>
              <label class="form-label">Current stock</label>
              <div class="form-control-plaintext" id="riceStockReadonly">—</div>
              <div class="form-text">Change stock by adding a batch on New Purchase.</div>
            </div>

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

            <div class="mb-0">
              <label for="riceStatus" class="form-label">Status</label>
              <select class="form-select" id="riceStatus" name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
          <div class="modal-footer px-0 pb-0" id="riceFormFooter">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rice" id="riceSubmitBtn">Save Rice</button>
          </div>
        </form>
      </div>
      <div class="modal-footer d-none" id="riceExistingFooter">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- Add / Edit Other Item -->
<div class="modal fade" id="groceryModal" tabindex="-1" aria-labelledby="groceryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="groceryModalLabel">Add Other Item</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="groceryAddModeWrap" class="mb-3">
          <label class="form-label d-block">What do you want to do?</label>
          <div class="btn-group w-100" role="group" aria-label="Item add mode">
            <input type="radio" class="btn-check" name="groceryAddMode" id="groceryModeNew" value="new" checked>
            <label class="btn btn-outline-secondary" for="groceryModeNew">New product</label>
            <input type="radio" class="btn-check" name="groceryAddMode" id="groceryModeExisting" value="existing">
            <label class="btn btn-outline-secondary" for="groceryModeExisting">Existing (add batch)</label>
          </div>
        </div>

        <div id="groceryExistingPanel" class="d-none">
          <p class="small text-muted">
            Same item, different buy price? Pick it, then stock a new batch on a purchase.
          </p>
          <div class="mb-3">
            <label for="groceryExistingSelect" class="form-label">Product</label>
            <select class="form-select" id="groceryExistingSelect">
              <option value="">Select item…</option>
              <?php foreach ($allGroceryForDropdown as $gp): ?>
                <option value="<?= (int) $gp['id'] ?>"><?= htmlspecialchars($gp['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <a href="purchase_new.php" class="btn btn-outline-success w-100 disabled" id="groceryContinuePurchase" aria-disabled="true">
            Continue to New Purchase
          </a>
        </div>

        <form method="POST" action="/rice-business/backend/product_save.php" id="groceryForm">
          <div id="groceryCatalogFields">
            <input type="hidden" name="id" id="groceryProductId" value="">
            <input type="hidden" name="product_type" value="GROCERY">

            <p class="small text-muted" id="groceryCatalogHint">
              Catalog only — stock comes from Purchases as batches.
            </p>

            <div class="mb-3" id="groceryStockReadonlyWrap" hidden>
              <label class="form-label">Current stock</label>
              <div class="form-control-plaintext" id="groceryStockReadonly">—</div>
              <div class="form-text">Change stock by adding a batch on New Purchase.</div>
            </div>

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

            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label for="grocerySellingPrice" class="form-label" id="grocerySellingLabel">Selling price (₱)</label>
                <input type="number" class="form-control" id="grocerySellingPrice" name="selling_price" step="0.01" min="0" required>
              </div>
              <div class="col-md-6">
                <label for="groceryMinStock" class="form-label">Low stock alert</label>
                <input type="number" class="form-control" id="groceryMinStock" name="minimum_stock" step="0.01" min="0" value="0" required>
                <div class="form-text" id="groceryUnitHint">Use whole numbers when unit is pc.</div>
              </div>
            </div>

            <div class="mb-0">
              <label for="groceryStatus" class="form-label">Status</label>
              <select class="form-select" id="groceryStatus" name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
          <div class="modal-footer px-0 pb-0" id="groceryFormFooter">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-outline-success" id="grocerySubmitBtn">Save Item</button>
          </div>
        </form>
      </div>
      <div class="modal-footer d-none" id="groceryExistingFooter">
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
  const riceModalEl = document.getElementById('riceModal');
  const groceryModalEl = document.getElementById('groceryModal');
  const riceModal = bootstrap.Modal.getOrCreateInstance(riceModalEl);
  const groceryModal = bootstrap.Modal.getOrCreateInstance(groceryModalEl);

  function updateGroceryUnitUi() {
    const unit = document.getElementById('groceryUnit').value || 'pc';
    const minStock = document.getElementById('groceryMinStock');
    document.getElementById('grocerySellingLabel').textContent = 'Selling price (₱) per ' + unit;
    document.getElementById('groceryUnitHint').textContent =
      unit === 'pc' ? 'Use whole numbers when unit is pc.' : 'Decimals are allowed for ' + unit + '.';
    minStock.step = unit === 'pc' ? '1' : '0.01';
  }

  function setRiceAddMode(mode) {
    const isExisting = mode === 'existing';
    document.getElementById('riceExistingPanel').classList.toggle('d-none', !isExisting);
    document.getElementById('riceCatalogFields').classList.toggle('d-none', isExisting);
    document.getElementById('riceFormFooter').classList.toggle('d-none', isExisting);
    document.getElementById('riceExistingFooter').classList.toggle('d-none', !isExisting);
    document.getElementById('riceModeNew').checked = !isExisting;
    document.getElementById('riceModeExisting').checked = isExisting;
  }

  function setGroceryAddMode(mode) {
    const isExisting = mode === 'existing';
    document.getElementById('groceryExistingPanel').classList.toggle('d-none', !isExisting);
    document.getElementById('groceryCatalogFields').classList.toggle('d-none', isExisting);
    document.getElementById('groceryFormFooter').classList.toggle('d-none', isExisting);
    document.getElementById('groceryExistingFooter').classList.toggle('d-none', !isExisting);
    document.getElementById('groceryModeNew').checked = !isExisting;
    document.getElementById('groceryModeExisting').checked = isExisting;
  }

  function updateRiceContinueLink() {
    const sel = document.getElementById('riceExistingSelect');
    const link = document.getElementById('riceContinuePurchase');
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

  function updateGroceryContinueLink() {
    const sel = document.getElementById('groceryExistingSelect');
    const link = document.getElementById('groceryContinuePurchase');
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

  function resetRiceForm() {
    document.getElementById('riceProductId').value = '';
    document.getElementById('riceName').value = '';
    document.getElementById('riceCategory').value = '';
    document.getElementById('riceKgPerSack').value = '25';
    document.getElementById('riceSellingPrice').value = '';
    document.getElementById('riceSellingPriceSack').value = '';
    document.getElementById('riceMinSacks').value = '1';
    document.getElementById('riceStatus').value = 'active';
    document.getElementById('riceModalLabel').textContent = 'Add Rice';
    document.getElementById('riceSubmitBtn').textContent = 'Save Rice';
    document.getElementById('riceAddModeWrap').classList.remove('d-none');
    document.getElementById('riceStockReadonlyWrap').hidden = true;
    document.getElementById('riceExistingSelect').value = '';
    setRiceAddMode('new');
    updateRiceContinueLink();
  }

  function resetGroceryForm() {
    document.getElementById('groceryProductId').value = '';
    document.getElementById('groceryName').value = '';
    document.getElementById('groceryCategory').value = '';
    document.getElementById('groceryUnit').value = 'pc';
    document.getElementById('groceryMinStock').value = '0';
    document.getElementById('grocerySellingPrice').value = '';
    document.getElementById('groceryStatus').value = 'active';
    document.getElementById('groceryModalLabel').textContent = 'Add Other Item';
    document.getElementById('grocerySubmitBtn').textContent = 'Save Item';
    document.getElementById('groceryAddModeWrap').classList.remove('d-none');
    document.getElementById('groceryStockReadonlyWrap').hidden = true;
    document.getElementById('groceryExistingSelect').value = '';
    setGroceryAddMode('new');
    updateGroceryContinueLink();
    updateGroceryUnitUi();
  }

  document.getElementById('riceModeNew').addEventListener('change', function () {
    if (this.checked) setRiceAddMode('new');
  });
  document.getElementById('riceModeExisting').addEventListener('change', function () {
    if (this.checked) setRiceAddMode('existing');
  });
  document.getElementById('groceryModeNew').addEventListener('change', function () {
    if (this.checked) setGroceryAddMode('new');
  });
  document.getElementById('groceryModeExisting').addEventListener('change', function () {
    if (this.checked) setGroceryAddMode('existing');
  });
  document.getElementById('riceExistingSelect').addEventListener('change', updateRiceContinueLink);
  document.getElementById('groceryExistingSelect').addEventListener('change', updateGroceryContinueLink);
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
        document.getElementById('riceSellingPrice').value = btn.dataset.sellingPrice;
        document.getElementById('riceSellingPriceSack').value = btn.dataset.sellingPriceSack || '';
        document.getElementById('riceMinSacks').value = btn.dataset.minSacks;
        document.getElementById('riceStatus').value = btn.dataset.status;
        document.getElementById('riceModalLabel').textContent = 'Edit Rice';
        document.getElementById('riceSubmitBtn').textContent = 'Update Rice';
        document.getElementById('riceAddModeWrap').classList.add('d-none');
        setRiceAddMode('new');
        const stockKg = parseFloat(btn.dataset.stock) || 0;
        const sacks = parseFloat(btn.dataset.sacks) || 0;
        document.getElementById('riceStockReadonly').textContent =
          sacks.toFixed(2) + ' sack (' + stockKg.toFixed(2) + ' kg)';
        document.getElementById('riceStockReadonlyWrap').hidden = false;
        riceModal.show();
      } else {
        document.getElementById('groceryProductId').value = btn.dataset.id;
        document.getElementById('groceryName').value = btn.dataset.name;
        document.getElementById('groceryCategory').value = btn.dataset.category;
        document.getElementById('groceryUnit').value = btn.dataset.unit || 'pc';
        document.getElementById('groceryMinStock').value = btn.dataset.minStock || '0';
        document.getElementById('grocerySellingPrice').value = btn.dataset.sellingPrice;
        document.getElementById('groceryStatus').value = btn.dataset.status;
        document.getElementById('groceryModalLabel').textContent = 'Edit Other Item';
        document.getElementById('grocerySubmitBtn').textContent = 'Update Item';
        document.getElementById('groceryAddModeWrap').classList.add('d-none');
        setGroceryAddMode('new');
        const unit = btn.dataset.unit || 'pc';
        const stock = parseFloat(btn.dataset.stock) || 0;
        const decimals = unit === 'pc' ? 0 : 2;
        document.getElementById('groceryStockReadonly').textContent =
          stock.toFixed(decimals) + ' ' + unit;
        document.getElementById('groceryStockReadonlyWrap').hidden = false;
        updateGroceryUnitUi();
        groceryModal.show();
      }
    });
  });

  riceModalEl.addEventListener('hidden.bs.modal', resetRiceForm);
  groceryModalEl.addEventListener('hidden.bs.modal', resetGroceryForm);
  updateGroceryUnitUi();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
