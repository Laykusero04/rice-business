<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/stock_lots.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Inventory';
$activePage = 'inventory';

$typeFilter = trim($_GET['type'] ?? '');
$search = trim($_GET['q'] ?? '');

$products = $pdo->query(
    "SELECT id, name, category, unit, product_type, kg_per_sack, stock, minimum_stock, status, buying_price
     FROM products
     ORDER BY name ASC"
)->fetchAll();

$lotsByProduct = fetchOpenLotsByProduct($pdo);

$openLots = [];
foreach ($lotsByProduct as $lots) {
    foreach ($lots as $lot) {
        $openLots[] = $lot;
    }
}

$lotsForJs = [];
foreach ($lotsByProduct as $pid => $lots) {
    $lotsForJs[(string) $pid] = array_map(static function ($lot) {
        return [
            'id' => (int) $lot['id'],
            'label' => formatLotLabel($lot),
            'remaining' => (float) $lot['quantity_remaining'],
        ];
    }, $lots);
}

$movementSql = 'SELECT sm.*, p.name AS product_name, p.unit AS product_unit
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

if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'adjusted' => 'Stock adjusted successfully.',
        'writeoff' => 'Leftover batch written off (marked empty / waste).',
        'undo_writeoff' => 'Write-off undone. Leftover stock restored to that batch.',
        'counted' => 'Physical count saved. System stock now matches what you entered.',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'invalid' => 'Please select a product and enter a valid quantity.',
        'stock' => 'Adjustment would make stock negative, or the selected batch does not have enough.',
        'lot' => 'Select a stock batch when deducting or writing off.',
        'not_undoable' => 'That movement cannot be undone (only Write-off can be undone from here).',
        'save' => 'Could not update stock.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Inventory</h1>
    <p class="text-muted mb-0">
      Batches under 1 kg show as <strong>LOW</strong>; under 0.05 kg as <strong>NONE</strong>.
      Write off leftovers when the sack is physically empty, or set a physical count.
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#countModal">
      <i class="bi bi-clipboard-check"></i> Set Physical Stock
    </button>
    <button type="button" class="btn btn-rice" data-bs-toggle="modal" data-bs-target="#adjustModal">
      <i class="bi bi-sliders"></i> Adjust Stock
    </button>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-3">Current Stock</h2>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th class="text-end">Stock</th>
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
                <?php
                  $unit = $product['unit'] ?? 'kg';
                  $isLow = (float) $product['stock'] <= (float) $product['minimum_stock'];
                ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars($product['name']) ?></td>
                  <td><?= htmlspecialchars($product['category']) ?></td>
                  <td class="text-end <?= $isLow ? 'text-danger fw-semibold' : '' ?>">
                    <?= number_format((float) $product['stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
                    <?php if ($isLow): ?>
                      <span class="badge text-bg-warning ms-1">Low</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <?= number_format((float) $product['minimum_stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
                  </td>
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

  <div class="col-lg-6">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <h2 class="h6 mb-3">Open Batches (by buy price)</h2>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Product</th>
              <th>Batch</th>
              <th>Buy price</th>
              <th class="text-end">Remaining</th>
              <th>Date</th>
              <th class="text-end">Fix</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($openLots) === 0): ?>
              <tr>
                <td colspan="6" class="text-center text-muted">No open batches. Buy stock via Purchases.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($openLots as $lot): ?>
                <?php
                  $unit = $lot['unit'] ?? 'kg';
                  $productType = $lot['product_type'] ?? 'RICE';
                  $kgPerSack = (float) ($lot['kg_per_sack'] ?? 25);
                  if ($kgPerSack <= 0) {
                      $kgPerSack = 25;
                  }
                  $buy = (float) $lot['buying_price'];
                  if ($productType === 'RICE') {
                      $priceLabel = '₱' . number_format($buy * $kgPerSack, 2) . '/sack';
                  } else {
                      $priceLabel = '₱' . number_format($buy, 2) . '/' . $unit;
                  }
                  $batchNote = trim((string) ($lot['notes'] ?? ''));
                  $remaining = round((float) $lot['quantity_remaining'], 2);
                  $lotNone = isLotUnsalable($remaining);
                  $lotLow = isLotLow($remaining);
                ?>
                <tr class="<?= $lotNone ? 'table-secondary' : ($lotLow ? 'table-warning' : '') ?>">
                  <td class="fw-semibold"><?= htmlspecialchars($lot['product_name']) ?></td>
                  <td class="small">
                    <?php if ($lotNone): ?>
                      <span class="badge text-bg-dark">NONE</span>
                    <?php elseif ($lotLow): ?>
                      <span class="badge text-bg-warning">LOW</span>
                    <?php endif; ?>
                    <?= $batchNote !== '' ? htmlspecialchars($batchNote) : '—' ?>
                  </td>
                  <td><?= htmlspecialchars($priceLabel) ?></td>
                  <td class="text-end">
                    <?= number_format($remaining, $unit === 'pc' ? 0 : 2) ?>
                    <?= htmlspecialchars($unit) ?>
                  </td>
                  <td class="small text-muted"><?= htmlspecialchars($lot['purchased_at']) ?></td>
                  <td class="text-end">
                    <form
                      method="POST"
                      action="/rice-business/backend/inventory_writeoff.php"
                      class="d-inline"
                      onsubmit="return confirm('Write off <?= htmlspecialchars(number_format($remaining, 2), ENT_QUOTES) ?> <?= htmlspecialchars($unit, ENT_QUOTES) ?> leftover on this batch? Use when the sack is physically empty.');"
                    >
                      <input type="hidden" name="stock_lot_id" value="<?= (int) $lot['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger" title="Write off leftover">
                        Write off
                      </button>
                    </form>
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
          <th class="text-end">Qty</th>
          <th>Reference</th>
          <th>Notes</th>
          <th class="text-end">Undo</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($movements) === 0): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-4">No stock movements found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($movements as $move): ?>
            <?php
              $unit = $move['product_unit'] ?? 'kg';
              $ref = (string) ($move['reference'] ?? '');
              $canUndoWriteoff = str_starts_with($ref, 'WRITEOFF');
            ?>
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
              <td class="text-end">
                <?= number_format((float) $move['quantity'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>
              </td>
              <td><?= htmlspecialchars($ref !== '' ? $ref : '—') ?></td>
              <td class="small text-muted"><?= htmlspecialchars($move['notes'] ?? '—') ?></td>
              <td class="text-end">
                <?php if ($canUndoWriteoff): ?>
                  <form
                    method="POST"
                    action="/rice-business/backend/inventory_undo_writeoff.php"
                    class="d-inline"
                    onsubmit="return confirm('Undo this write-off and restore the leftover to the batch?');"
                  >
                    <input type="hidden" name="movement_id" value="<?= (int) $move['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Undo</button>
                  </form>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="countModal" tabindex="-1" aria-labelledby="countModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/inventory_set_stock.php">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="countModalLabel">Set Physical Stock</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">
            Weigh or count what is actually on hand. The system updates batches so data matches the floor
            (useful when the sack looks empty but leftover kg remains on screen).
          </p>
          <div class="mb-3">
            <label for="countProduct" class="form-label">Product</label>
            <select class="form-select" id="countProduct" name="product_id" required>
              <option value="">Select product</option>
              <?php foreach ($products as $product): ?>
                <?php $unit = $product['unit'] ?? 'kg'; ?>
                <option
                  value="<?= (int) $product['id'] ?>"
                  data-unit="<?= htmlspecialchars($unit) ?>"
                  data-stock="<?= htmlspecialchars(number_format((float) $product['stock'], 2, '.', '')) ?>"
                >
                  <?= htmlspecialchars($product['name']) ?>
                  (system: <?= number_format((float) $product['stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label for="countQty" class="form-label" id="countQtyLabel">Physical quantity</label>
            <input
              type="number"
              class="form-control"
              id="countQty"
              name="physical_qty"
              step="0.01"
              min="0"
              required
              placeholder="What you actually have"
            >
            <div class="form-text" id="countQtyHelp">Enter 0 if the sack is empty.</div>
          </div>
          <div class="mb-0">
            <label for="countNotes" class="form-label">Notes</label>
            <input type="text" class="form-control" id="countNotes" name="notes" placeholder="Optional, e.g. emptied sack">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice">Save Physical Count</button>
        </div>
      </form>
    </div>
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
                <?php $unit = $product['unit'] ?? 'kg'; ?>
                <option value="<?= (int) $product['id'] ?>" data-unit="<?= htmlspecialchars($unit) ?>">
                  <?= htmlspecialchars($product['name']) ?>
                  (<?= number_format((float) $product['stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label for="adjustLot" class="form-label">Batch</label>
            <select class="form-select" id="adjustLot" name="stock_lot_id">
              <option value="">Select batch</option>
            </select>
            <div class="form-text" id="adjustLotHelp">
              Required when deducting. When adding, pick a batch to top up, or leave blank to create a new batch at the current buy price.
            </div>
          </div>
          <div class="mb-3">
            <label for="adjustQty" class="form-label" id="adjustQtyLabel">Quantity</label>
            <input
              type="number"
              class="form-control"
              id="adjustQty"
              name="quantity"
              step="0.01"
              required
              placeholder="Use + to add, - to deduct"
            >
            <div class="form-text" id="adjustQtyHelp">Example: 10 adds stock, -5 deducts stock.</div>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
  const productSelect = document.getElementById('adjustProduct');
  const lotSelect = document.getElementById('adjustLot');
  const qtyInput = document.getElementById('adjustQty');
  const qtyLabel = document.getElementById('adjustQtyLabel');
  const qtyHelp = document.getElementById('adjustQtyHelp');
  const lotsByProduct = <?= json_encode($lotsForJs, JSON_UNESCAPED_UNICODE) ?>;

  const countProduct = document.getElementById('countProduct');
  const countQty = document.getElementById('countQty');
  const countQtyLabel = document.getElementById('countQtyLabel');
  const countQtyHelp = document.getElementById('countQtyHelp');

  function populateLots() {
    const productId = productSelect.value;
    const lots = lotsByProduct[productId] || [];
    lotSelect.innerHTML = '';

    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = lots.length ? 'Select batch (or blank if adding new)' : 'No open batch — leave blank to create';
    lotSelect.appendChild(blank);

    lots.forEach(function (lot) {
      const opt = document.createElement('option');
      opt.value = String(lot.id);
      opt.textContent = lot.label;
      lotSelect.appendChild(opt);
    });
  }

  function updateAdjustUi() {
    const option = productSelect.selectedOptions[0];
    const unit = option && option.dataset.unit ? option.dataset.unit : 'kg';
    qtyLabel.textContent = 'Quantity (' + unit + ')';
    if (unit === 'pc') {
      qtyInput.step = '1';
      qtyHelp.textContent = 'Example: 10 adds stock, -5 deducts stock. Use whole numbers for pc.';
    } else {
      qtyInput.step = '0.01';
      qtyHelp.textContent = 'Example: 10 adds stock, -5 deducts stock.';
    }
    populateLots();
  }

  function updateCountUi() {
    const option = countProduct.selectedOptions[0];
    const unit = option && option.dataset.unit ? option.dataset.unit : 'kg';
    const stock = option && option.dataset.stock ? option.dataset.stock : '0';
    countQtyLabel.textContent = 'Physical quantity (' + unit + ')';
    countQty.step = unit === 'pc' ? '1' : '0.01';
    countQtyHelp.textContent = 'System currently shows ' + stock + ' ' + unit + '. Enter 0 if empty.';
    if (option && option.value) {
      countQty.value = stock;
    }
  }

  productSelect.addEventListener('change', updateAdjustUi);
  countProduct.addEventListener('change', updateCountUi);
  updateAdjustUi();
  updateCountUi();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
