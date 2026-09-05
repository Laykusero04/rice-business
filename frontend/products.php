<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/stock_lots.php';
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

$lotsByProduct = fetchOpenLotsByProduct($pdo);

$nameSuggestions = [];
try {
    $nameSuggestions = $pdo->query('SELECT name FROM rice_names ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $nameSuggestions = [];
}
foreach ($pdo->query("SELECT name FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN) as $n) {
    if (!in_array($n, $nameSuggestions, true)) {
        $nameSuggestions[] = $n;
    }
}
sort($nameSuggestions, SORT_NATURAL | SORT_FLAG_CASE);

$nextBatchNo = 1;
try {
    $counter = $pdo->query('SELECT next_batch_no FROM batch_counters WHERE id = 1')->fetch();
    if ($counter) {
        $nextBatchNo = max(1, (int) $counter['next_batch_no']);
    }
} catch (PDOException $e) {
    $nextBatchNo = 1;
}
$nextBatchLabel = 'Batch-' . $nextBatchNo;

$purchaseBatches = [];
try {
    $purchaseBatches = fetchPurchaseBatchesForSelect($pdo);
} catch (Throwable $e) {
    $purchaseBatches = [];
}

$flash = '';
$flashType = 'success';

if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'created' => 'Rice saved. If you entered sacks, that stock batch is ready for sales.',
        'updated' => 'Product updated successfully.',
        'deleted' => 'Product deleted successfully.',
        'deactivated' => 'Product has sales/purchase history, so it was set to Inactive instead of deleted.',
        'category_created' => 'Category added successfully.',
        'category_updated' => 'Category updated successfully.',
        'category_deleted' => 'Category deleted successfully.',
        'batch_added' => 'Sell batch linked to purchase cost. Sales from this batch will show profit on Reports → By lot / batch.',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'required' => 'Product name is required.',
        'invalid' => 'Please check the values you entered.',
        'sacks' => 'Enter how many sacks are in this batch (each sack is usually 25 kg).',
        'purchase_batch' => 'Choose which purchase batch paid for this mix (so profit can be tracked).',
        'save' => 'Could not save the product.',
        'delete' => 'Could not delete the product.',
        'in_use' => 'This product already has sales/purchase history and is Inactive. It cannot be permanently deleted.',
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
      <strong>1.</strong> Purchase = money spent (Batch-N).
      <strong>2.</strong> Product = sell type (your mixes).
      <strong>3.</strong> + Add batch = link that mix to a purchase + sacks.
      <strong>4.</strong> Sale = pick product + batch (by sack or by kg).
      Profit: <a href="reports.php?tab=batch">Reports → By lot / batch</a>.
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

<?php
$showCategoryCol = $typeFilter !== 'RICE';
$colspan = $showCategoryCol ? 7 : 6;
?>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Product</th>
        <th>Type</th>
        <?php if ($showCategoryCol): ?>
          <th>Category</th>
        <?php endif; ?>
        <th class="text-end">Sell</th>
        <th style="min-width: 200px;">Batch</th>
        <th>Status</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($products) === 0): ?>
        <tr>
          <td colspan="<?= (int) $colspan ?>" class="text-center text-muted py-4">
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
            $sackSellPrice = isset($product['selling_price_sack']) && $product['selling_price_sack'] !== null
              ? (float) $product['selling_price_sack']
              : round((float) $product['selling_price'] * $kgPerSack, 2);
            $productLots = $lotsByProduct[(int) $product['id']] ?? [];
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
            <?php if ($showCategoryCol): ?>
              <td><?= htmlspecialchars($product['category']) ?></td>
            <?php endif; ?>
            <td class="text-end">
              <?php if ($productType === 'RICE'): ?>
                <?php if ($sackSellPrice > 0): ?>
                  ₱<?= number_format($sackSellPrice, 2) ?> / sack
                  <div class="small text-muted">₱<?= number_format((float) $product['selling_price'], 0) ?> / kg</div>
                <?php else: ?>
                  ₱<?= number_format((float) $product['selling_price'], 0) ?> / kg
                <?php endif; ?>
              <?php else: ?>
                ₱<?= number_format((float) $product['selling_price'], 2) ?> / <?= htmlspecialchars($unit) ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if (count($productLots) > 0): ?>
                <select class="form-select form-select-sm" aria-label="Batches for <?= htmlspecialchars($product['name']) ?>">
                  <?php foreach ($productLots as $lot): ?>
                    <option value="<?= (int) $lot['id'] ?>"><?= htmlspecialchars(formatLotLabel($lot)) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <span class="small text-muted">No batch yet</span>
              <?php endif; ?>
              <?php if ($product['status'] === 'active'): ?>
                <button
                  type="button"
                  class="btn btn-link btn-sm p-0 btn-add-batch"
                  data-bs-toggle="modal"
                  data-bs-target="#addBatchModal"
                  data-product-id="<?= (int) $product['id'] ?>"
                  data-product-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                  data-product-type="<?= htmlspecialchars($productType, ENT_QUOTES) ?>"
                  data-kg-per-sack="<?= htmlspecialchars(number_format($kgPerSack, 2, '.', '')) ?>"
                >+ Add batch</button>
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
                data-sacks="0"
                data-sack-price="0"
                data-buying-price="<?= htmlspecialchars(number_format((float) $product['buying_price'], 2, '.', '')) ?>"
                data-stock="<?= htmlspecialchars(number_format((float) $product['stock'], 2, '.', '')) ?>"
                data-selling-price="<?= htmlspecialchars($product['selling_price']) ?>"
                data-selling-price-sack="<?= htmlspecialchars(number_format($sackSellPrice, 2, '.', '')) ?>"
                data-min-sacks="0"
                data-min-stock="<?= htmlspecialchars(number_format((float) $product['minimum_stock'], 2, '.', '')) ?>"
                data-status="<?= htmlspecialchars($product['status']) ?>"
              >
                Edit
              </button>
              <form
                method="POST"
                action="/rice-business/backend/product_delete.php"
                class="d-inline"
                onsubmit="return confirm('Delete this product?\n\nIf it has sales or purchase history, it will be set to Inactive instead so records stay intact.');"
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
      <form method="POST" action="/rice-business/backend/product_save.php" id="productForm">
        <div class="modal-body">
          <input type="hidden" name="id" id="productId" value="">
          <input type="hidden" name="product_type" id="productType" value="RICE">

          <p class="small text-muted" id="productCatalogHint">
            One product = one sell type (e.g. Mix A). After you mix, click <strong>+ Add batch</strong> and name that batch.
          </p>

          <div class="mb-3">
            <label for="productName" class="form-label" id="productNameLabel">Rice Name</label>
            <div class="rice-combo" id="productNameCombo">
              <div class="input-group">
                <input
                  type="text"
                  class="form-control"
                  id="productName"
                  name="name"
                  required
                  maxlength="100"
                  placeholder="Type or pick name"
                  autocomplete="off"
                  role="combobox"
                  aria-autocomplete="list"
                  aria-expanded="false"
                >
                <button type="button" class="btn btn-outline-secondary" id="productNameToggle" tabindex="-1" title="Show names">
                  <i class="bi bi-chevron-down"></i>
                </button>
              </div>
              <div class="rice-combo-menu" id="productNameMenu" role="listbox"></div>
            </div>
            <div class="form-text">Suggestions from purchase rice names and existing products.</div>
          </div>

          <div id="riceFields">
            <input type="hidden" name="unit" id="riceUnit" value="kg">
            <input type="hidden" name="category" id="riceCategory" value="Rice">

            <div class="mb-3">
              <label for="riceKgPerSack" class="form-label">1 sack = how many kg?</label>
              <input type="number" class="form-control" id="riceKgPerSack" name="kg_per_sack" step="0.01" min="0.01" value="25" required>
              <div class="form-text">Usually 25. You still enter stock as <strong>sacks</strong>, not kg.</div>
            </div>

            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label for="riceSellingPrice" class="form-label">Sell per kg (₱)</label>
                <input type="number" class="form-control" id="riceSellingPrice" name="selling_price" step="1" min="1" required>
                <div class="form-text" id="riceKgPriceHint">Whole number only (e.g. 53).</div>
              </div>
              <div class="col-md-6">
                <label for="riceSellingPriceSack" class="form-label">Sell per sack (₱)</label>
                <input type="number" class="form-control" id="riceSellingPriceSack" name="selling_price_sack" step="0.01" min="0" value="">
                <div class="form-text">Optional — only if you also sell whole sacks.</div>
              </div>
            </div>

            <div class="border rounded p-3 mb-0 bg-light" id="riceStockSacksWrap">
              <div class="fw-semibold mb-2">Stock now (by sack)</div>
              <p class="small text-muted mb-3">
                Enter <strong>sacks</strong> for stock.
                Pick a purchase batch only if this came from a recorded buy (for profit).
                Leave purchase empty for <strong>old stock</strong> (no batch link).
              </p>

              <div class="mb-3">
                <label for="riceOpeningSacks" class="form-label">How many sacks?</label>
                <input type="number" class="form-control" id="riceOpeningSacks" name="opening_sacks" step="0.01" min="0" value="" placeholder="e.g. 10">
                <div class="form-text" id="riceOpeningSacksHint">Example: 10 sacks (system uses 10 × 25 kg inside)</div>
              </div>

              <div class="mb-3">
                <label for="riceSourcePurchase" class="form-label">From purchase batch (optional)</label>
                <select class="form-select" id="riceSourcePurchase" name="source_purchase_id">
                  <option value="">Old stock — no purchase batch</option>
                  <?php foreach ($purchaseBatches as $pb): ?>
                    <option
                      value="<?= (int) $pb['id'] ?>"
                      data-remaining="<?= htmlspecialchars(number_format($pb['remaining_cost'], 2, '.', '')) ?>"
                      data-total="<?= htmlspecialchars(number_format($pb['total'], 2, '.', '')) ?>"
                      data-batch-label="<?= htmlspecialchars($pb['batch_label'], ENT_QUOTES) ?>"
                    >
                      <?= htmlspecialchars($pb['label']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="mb-0" id="riceCostWrap">
                <label for="riceCostAmount" class="form-label">Cost from that purchase (₱)</label>
                <input type="number" class="form-control" id="riceCostAmount" name="cost_amount" step="0.01" min="0" value="">
                <div class="form-text" id="riceCostHint">Only needed when you pick a purchase batch.</div>
              </div>
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
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice" id="productSubmitBtn">Save Rice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add batch -->
<div class="modal fade" id="addBatchModal" tabindex="-1" aria-labelledby="addBatchModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/batch_create.php" id="addBatchForm">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="addBatchModalLabel">Add batch</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="product_id" id="batchProductId" value="">
          <input type="hidden" name="redirect" value="<?= htmlspecialchars(productsFilterUrl($typeFilter, $filterQuery)) ?>">

          <p class="mb-3">
            Product: <strong id="batchProductName">—</strong>
          </p>
          <p class="small text-muted">
            Link this mix to the <strong>purchase batch</strong> that paid for the rice.
            Name this mix so you can tell it apart from your other batches.
            Approximate kg is OK for small sales.
          </p>

          <div class="mb-3">
            <label for="batchSourcePurchase" class="form-label">From purchase batch (optional)</label>
            <select class="form-select" id="batchSourcePurchase" name="source_purchase_id">
              <option value="">Old stock — no purchase batch</option>
              <?php foreach ($purchaseBatches as $pb): ?>
                <option
                  value="<?= (int) $pb['id'] ?>"
                  data-remaining="<?= htmlspecialchars(number_format($pb['remaining_cost'], 2, '.', '')) ?>"
                  data-total="<?= htmlspecialchars(number_format($pb['total'], 2, '.', '')) ?>"
                  data-batch-label="<?= htmlspecialchars($pb['batch_label'], ENT_QUOTES) ?>"
                >
                  <?= htmlspecialchars($pb['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Leave empty for old stock. Pick a purchase to track profit on that batch.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Linked batch name</label>
            <input type="hidden" id="batchLabel" name="batch_label" value="Old stock">
            <div class="form-control-plaintext fw-semibold" id="batchLabelDisplay">Old stock</div>
            <div class="form-text">Uses the purchase batch name when linked; otherwise Old stock.</div>
          </div>

          <div class="mb-3" id="batchSacksWrap">
            <label for="batchSacks" class="form-label" id="batchSacksLabel">Number of sacks (estimate OK)</label>
            <input
              type="number"
              class="form-control"
              id="batchSacks"
              name="sacks"
              step="0.01"
              min="0.01"
              value="1"
              required
            >
            <div class="form-text" id="batchSacksHint">
              Each sack = <span id="batchKgPerSackText">25</span> kg
              → <strong id="batchKgTotal">25</strong> kg total (guide only)
            </div>
          </div>

          <div class="mb-3" id="batchCostWrap">
            <label for="batchCostAmount" class="form-label">Cost from that purchase (₱)</label>
            <input
              type="number"
              class="form-control"
              id="batchCostAmount"
              name="cost_amount"
              step="0.01"
              min="0"
              value=""
            >
            <div class="form-text" id="batchCostHint">
              Only needed when linked to a purchase.
            </div>
          </div>

          <div class="mb-0">
            <label for="batchBuyPrice" class="form-label" id="batchBuyPriceLabel">Buy price / sack (optional override)</label>
            <input type="number" class="form-control" id="batchBuyPrice" name="buying_price_sack" step="0.01" min="0" value="0">
            <div class="form-text">Usually leave 0 — cost above is used for profit.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice">Save batch</button>
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
  const modalEl = document.getElementById('productModal');
  const typeInput = document.getElementById('productType');
  const riceFields = document.getElementById('riceFields');
  const groceryFields = document.getElementById('groceryFields');
  const nameSuggestions = <?= json_encode(array_values($nameSuggestions), JSON_UNESCAPED_UNICODE) ?>;
  const nextBatchLabel = <?= json_encode($nextBatchLabel, JSON_UNESCAPED_UNICODE) ?>;

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

  function setProductType(type) {
    const rice = type !== 'GROCERY';
    typeInput.value = rice ? 'RICE' : 'GROCERY';

    riceFields.hidden = !rice;
    groceryFields.hidden = rice;
    setFieldsEnabled(riceFields, rice);
    setFieldsEnabled(groceryFields, !rice);
    const stockWrap = document.getElementById('riceStockSacksWrap');
    if (stockWrap && !document.getElementById('productId').value) {
      stockWrap.hidden = !rice;
    }

    document.getElementById('productModalLabel').textContent = rice ? 'Add Rice' : 'Add Other Item';
    document.getElementById('productNameLabel').textContent = rice ? 'Rice Name' : 'Item Name';
    document.getElementById('productName').placeholder = rice ? 'Type or pick rice name' : 'Type item name';
    document.getElementById('productCatalogHint').textContent = rice
      ? 'Add by sacks for stock. Sell per kg is required; sell per sack is optional.'
      : 'Add the item, then use + Add batch when you receive stock.';
    document.getElementById('productSubmitBtn').textContent = rice ? 'Save Rice' : 'Save Item';
    document.getElementById('productSubmitBtn').classList.toggle('btn-outline-success', !rice);
    document.getElementById('productSubmitBtn').classList.toggle('btn-rice', rice);

    if (!rice) {
      updateGroceryUnitUi();
    }
  }

  function normalizeName(value) {
    return String(value || '').trim().toLowerCase();
  }

  function scoreName(name, query) {
    const n = normalizeName(name);
    const q = normalizeName(query);
    if (q === '') return 1;
    if (n === q) return 1000;
    if (n.startsWith(q)) return 800 - Math.min(n.length, 100);
    const idx = n.indexOf(q);
    if (idx >= 0) return 600 - idx;
    let ni = 0;
    for (let qi = 0; qi < q.length; qi++) {
      ni = n.indexOf(q[qi], ni);
      if (ni === -1) return 0;
      ni += 1;
    }
    return 200 - Math.min(n.length, 100);
  }

  function filterNames(query, limit) {
    return nameSuggestions
      .map(function (name) { return { name: name, score: scoreName(name, query) }; })
      .filter(function (row) { return row.score > 0; })
      .sort(function (a, b) {
        if (b.score !== a.score) return b.score - a.score;
        return a.name.localeCompare(b.name);
      })
      .slice(0, limit || 12)
      .map(function (row) { return row.name; });
  }

  const nameInput = document.getElementById('productName');
  const nameMenu = document.getElementById('productNameMenu');
  const nameToggle = document.getElementById('productNameToggle');
  const nameCombo = document.getElementById('productNameCombo');

  function closeNameMenu() {
    nameMenu.classList.remove('is-open');
    nameMenu.innerHTML = '';
    nameInput.setAttribute('aria-expanded', 'false');
  }

  function openNameMenu(query, forceAll) {
    const q = forceAll ? '' : (query != null ? query : nameInput.value);
    const matches = filterNames(q, forceAll ? 50 : 12);
    nameMenu.innerHTML = '';
    if (matches.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'rice-combo-empty';
      empty.textContent = q ? 'No close match — keep typing a new name.' : 'No saved names yet.';
      nameMenu.appendChild(empty);
    } else {
      matches.forEach(function (name, index) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rice-combo-item' + (index === 0 ? ' is-active' : '');
        btn.setAttribute('role', 'option');
        btn.textContent = name;
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
          nameInput.value = name;
          closeNameMenu();
        });
        nameMenu.appendChild(btn);
      });
    }
    nameMenu.classList.add('is-open');
    nameInput.setAttribute('aria-expanded', 'true');
  }

  function moveNameActive(delta) {
    const items = Array.from(nameMenu.querySelectorAll('.rice-combo-item'));
    if (items.length === 0) return;
    let idx = items.findIndex(function (el) { return el.classList.contains('is-active'); });
    if (idx < 0) idx = 0;
    else {
      items[idx].classList.remove('is-active');
      idx = (idx + delta + items.length) % items.length;
    }
    items[idx].classList.add('is-active');
    items[idx].scrollIntoView({ block: 'nearest' });
  }

  nameInput.addEventListener('input', function () {
    openNameMenu(nameInput.value, false);
  });
  nameInput.addEventListener('focus', function () {
    openNameMenu(nameInput.value, nameInput.value.trim() === '');
  });
  nameInput.addEventListener('keydown', function (e) {
    const open = nameMenu.classList.contains('is-open');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!open) openNameMenu(nameInput.value, nameInput.value.trim() === '');
      else moveNameActive(1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (open) moveNameActive(-1);
    } else if (e.key === 'Enter' && open) {
      const active = nameMenu.querySelector('.rice-combo-item.is-active');
      if (active) {
        e.preventDefault();
        nameInput.value = active.textContent;
        closeNameMenu();
      }
    } else if (e.key === 'Escape') {
      closeNameMenu();
    }
  });
  nameToggle.addEventListener('click', function () {
    if (nameMenu.classList.contains('is-open')) closeNameMenu();
    else {
      nameInput.focus();
      openNameMenu('', true);
    }
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('#productNameCombo')) closeNameMenu();
  });

  function resetForm(type) {
    document.getElementById('productId').value = '';
    document.getElementById('productName').value = '';
    document.getElementById('riceCategory').value = 'Rice';
    document.getElementById('riceKgPerSack').value = '25';
    document.getElementById('riceSellingPrice').value = '';
    document.getElementById('riceSellingPriceSack').value = '';
    document.getElementById('riceOpeningSacks').value = '';
    document.getElementById('riceSourcePurchase').value = '';
    document.getElementById('riceCostAmount').value = '';
    document.getElementById('riceStockSacksWrap').hidden = false;
    document.getElementById('groceryCategory').value = '';
    document.getElementById('groceryUnit').value = 'pc';
    document.getElementById('groceryMinStock').value = '0';
    document.getElementById('grocerySellingPrice').value = '';
    document.getElementById('productStatus').value = 'active';
    setProductType(type || 'RICE');
    updateRiceOpeningHint();
    syncRiceCostFromPurchase();
  }

  function fillEdit(btn) {
    const type = btn.getAttribute('data-product-type') || 'RICE';
    const rice = type !== 'GROCERY';
    setProductType(type);

    document.getElementById('productId').value = btn.getAttribute('data-id') || '';
    document.getElementById('productName').value = btn.getAttribute('data-name') || '';
    document.getElementById('productStatus').value = btn.getAttribute('data-status') || 'active';
    document.getElementById('productModalLabel').textContent = rice ? 'Edit Rice' : 'Edit Other Item';
    document.getElementById('productSubmitBtn').textContent = rice ? 'Update Rice' : 'Update Item';
    document.getElementById('riceStockSacksWrap').hidden = true;

    if (rice) {
      document.getElementById('riceCategory').value = 'Rice';
      document.getElementById('riceKgPerSack').value = btn.getAttribute('data-kg-per-sack') || '25';
      document.getElementById('riceSellingPrice').value = Math.round(parseFloat(btn.getAttribute('data-selling-price') || '0') || 0) || '';
      document.getElementById('riceSellingPriceSack').value = btn.getAttribute('data-selling-price-sack') || '';
    } else {
      document.getElementById('groceryCategory').value = btn.getAttribute('data-category') || '';
      document.getElementById('groceryUnit').value = btn.getAttribute('data-unit') || 'pc';
      document.getElementById('groceryMinStock').value = btn.getAttribute('data-min-stock') || '0';
      document.getElementById('grocerySellingPrice').value = btn.getAttribute('data-selling-price') || '';
      updateGroceryUnitUi();
    }
  }

  document.getElementById('groceryUnit').addEventListener('change', updateGroceryUnitUi);

  const riceKgPerSackEl = document.getElementById('riceKgPerSack');
  const riceSellKgEl = document.getElementById('riceSellingPrice');
  const riceSellSackEl = document.getElementById('riceSellingPriceSack');
  const riceOpeningSacksEl = document.getElementById('riceOpeningSacks');
  const riceSourcePurchaseEl = document.getElementById('riceSourcePurchase');
  const riceCostAmountEl = document.getElementById('riceCostAmount');
  let riceKgPriceManual = false;

  function riceKgPerSack() {
    const kg = parseFloat(riceKgPerSackEl.value) || 0;
    return kg > 0 ? kg : 25;
  }

  function updateRiceOpeningHint() {
    const sacks = parseFloat(riceOpeningSacksEl.value) || 0;
    const kgEach = riceKgPerSack();
    const totalKg = Math.round(sacks * kgEach * 100) / 100;
    document.getElementById('riceOpeningSacksHint').textContent = sacks > 0
      ? (sacks + ' sack' + (sacks === 1 ? '' : 's') + ' → system keeps ' + totalKg + ' kg inside (you type sacks only)')
      : ('Example: 10 sacks (1 sack = ' + kgEach + ' kg inside)');
  }

  function syncKgFromSack() {
    if (riceKgPriceManual) return;
    const sack = parseFloat(riceSellSackEl.value);
    if (!(sack > 0) || riceSellSackEl.value === '') return;
    const perKg = Math.round(sack / riceKgPerSack());
    riceSellKgEl.value = String(perKg > 0 ? perKg : 1);
    document.getElementById('riceKgPriceHint').textContent =
      'From sack ÷ ' + riceKgPerSack() + ' kg (whole pesos). You can change this.';
  }

  function syncRiceCostFromPurchase() {
    const opt = riceSourcePurchaseEl.options[riceSourcePurchaseEl.selectedIndex];
    const costWrap = document.getElementById('riceCostWrap');
    if (!opt || !opt.value) {
      riceCostAmountEl.value = '';
      riceCostAmountEl.required = false;
      if (costWrap) costWrap.style.display = 'none';
      document.getElementById('riceCostHint').textContent = 'Only needed when you pick a purchase batch.';
      return;
    }
    if (costWrap) costWrap.style.display = '';
    const remaining = parseFloat(opt.getAttribute('data-remaining')) || 0;
    const total = parseFloat(opt.getAttribute('data-total')) || 0;
    riceCostAmountEl.value = remaining > 0 ? remaining.toFixed(2) : (total > 0 ? total.toFixed(2) : '');
    riceCostAmountEl.required = true;
    document.getElementById('riceCostHint').textContent =
      '₱' + remaining.toFixed(2) + ' left on this purchase.';
  }

  riceSellSackEl.addEventListener('input', function () {
    riceKgPriceManual = false;
    syncKgFromSack();
  });
  riceKgPerSackEl.addEventListener('input', function () {
    syncKgFromSack();
    updateRiceOpeningHint();
  });
  riceSellKgEl.addEventListener('input', function () {
    riceKgPriceManual = true;
    const n = Math.round(parseFloat(riceSellKgEl.value) || 0);
    if (riceSellKgEl.value !== '' && String(n) !== riceSellKgEl.value) {
      riceSellKgEl.value = String(n > 0 ? n : '');
    }
    document.getElementById('riceKgPriceHint').textContent =
      'Whole pesos only (e.g. 53).';
  });
  riceOpeningSacksEl.addEventListener('input', updateRiceOpeningHint);
  riceSourcePurchaseEl.addEventListener('change', syncRiceCostFromPurchase);

  document.getElementById('productForm').addEventListener('submit', function (e) {
    if (typeInput.value !== 'RICE') return;

    const sack = parseFloat(riceSellSackEl.value) || 0;
    let kgPrice = Math.round(parseFloat(riceSellKgEl.value) || 0);
    if (!(kgPrice > 0) && sack > 0) {
      kgPrice = Math.max(1, Math.round(sack / riceKgPerSack()));
    }
    if (kgPrice > 0) {
      riceSellKgEl.value = String(kgPrice);
    }

    const isEdit = !!document.getElementById('productId').value;
    const opening = parseFloat(riceOpeningSacksEl.value) || 0;
    if (!isEdit && opening > 0 && riceSourcePurchaseEl.value) {
      if (!(parseFloat(riceCostAmountEl.value) > 0)) {
        e.preventDefault();
        alert('Enter the cost from that purchase (or choose Old stock — no purchase batch).');
        riceCostAmountEl.focus();
      }
    }
  });

  modalEl.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    const type = (btn && btn.getAttribute('data-product-type')) || 'RICE';
    riceKgPriceManual = !!(btn && btn.classList.contains('btn-edit-product'));
    if (btn && btn.classList.contains('btn-edit-product')) {
      fillEdit(btn);
    } else {
      resetForm(type);
    }
  });
  modalEl.addEventListener('hidden.bs.modal', function () {
    closeNameMenu();
    riceKgPriceManual = false;
    resetForm('RICE');
  });

  const batchModal = document.getElementById('addBatchModal');
  const batchSacksInput = document.getElementById('batchSacks');
  const batchSourcePurchase = document.getElementById('batchSourcePurchase');
  const batchCostAmount = document.getElementById('batchCostAmount');
  let batchKgPerSack = 25;

  function updateBatchKgHint() {
    const sacks = parseFloat(batchSacksInput.value) || 0;
    const kg = Math.round(sacks * batchKgPerSack * 100) / 100;
    document.getElementById('batchKgPerSackText').textContent = String(batchKgPerSack);
    document.getElementById('batchKgTotal').textContent = kg.toFixed(2);
  }

  function syncCostFromPurchase() {
    const opt = batchSourcePurchase.options[batchSourcePurchase.selectedIndex];
    const labelInput = document.getElementById('batchLabel');
    const labelDisplay = document.getElementById('batchLabelDisplay');
    const costWrap = document.getElementById('batchCostWrap');
    if (!opt || !opt.value) {
      batchCostAmount.value = '';
      batchCostAmount.required = false;
      labelInput.value = 'Old stock';
      labelDisplay.textContent = 'Old stock';
      if (costWrap) costWrap.style.display = 'none';
      document.getElementById('batchCostHint').textContent =
        'Only needed when linked to a purchase.';
      return;
    }
    if (costWrap) costWrap.style.display = '';
    batchCostAmount.required = true;
    const remaining = parseFloat(opt.getAttribute('data-remaining')) || 0;
    const total = parseFloat(opt.getAttribute('data-total')) || 0;
    const purchaseBatch = opt.getAttribute('data-batch-label') || '';
    batchCostAmount.value = remaining > 0 ? remaining.toFixed(2) : (total > 0 ? total.toFixed(2) : '');
    labelInput.value = purchaseBatch;
    labelDisplay.textContent = purchaseBatch !== '' ? purchaseBatch : ('Purchase #' + opt.value);
    document.getElementById('batchCostHint').textContent =
      '₱' + remaining.toFixed(2) + ' left on this purchase. Change if this mix used only part of it.';
  }

  batchSacksInput.addEventListener('input', updateBatchKgHint);
  batchSourcePurchase.addEventListener('change', syncCostFromPurchase);

  batchModal.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    if (!btn) return;
    document.getElementById('batchProductId').value = btn.getAttribute('data-product-id') || '';
    document.getElementById('batchProductName').textContent = btn.getAttribute('data-product-name') || '—';
    document.getElementById('batchLabel').value = 'Old stock';
    document.getElementById('batchLabelDisplay').textContent = 'Old stock';
    document.getElementById('batchBuyPrice').value = '0';
    batchSacksInput.value = '1';
    batchSourcePurchase.value = '';
    batchCostAmount.value = '';
    batchCostAmount.required = false;

    const isRice = (btn.getAttribute('data-product-type') || 'RICE') === 'RICE';
    batchKgPerSack = parseFloat(btn.getAttribute('data-kg-per-sack')) || 25;
    if (batchKgPerSack <= 0) batchKgPerSack = 25;

    document.getElementById('batchBuyPriceLabel').textContent = isRice
      ? 'Buy price / sack (optional override)'
      : 'Buy price / unit (optional override)';
    document.getElementById('batchSacksLabel').textContent = isRice
      ? 'Number of sacks (estimate OK)'
      : 'Quantity';
    document.getElementById('batchSacksHint').style.display = isRice ? '' : 'none';
    updateBatchKgHint();
    syncCostFromPurchase();
  });
  batchModal.addEventListener('shown.bs.modal', function () {
    batchSacksInput.focus();
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
