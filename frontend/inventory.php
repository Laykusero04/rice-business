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
        'writeoff' => 'Leftover batch written off (marked empty / waste). Shrink cost counted against batch profit.',
        'closed' => 'Batch closed. Remaining estimate written off as shrink loss.',
        'undo_writeoff' => 'Write-off undone. Leftover stock restored to that batch.',
        'counted' => 'Physical count saved. System stock now matches what you entered.',
        'renamed' => 'Batch label updated.',
        'reassigned' => 'Batch moved to the new sellable product.',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'invalid' => 'Please select a product and enter a valid quantity.',
        'stock' => 'Adjustment would make stock negative, or the selected batch does not have enough.',
        'lot' => 'Select a stock batch when deducting, writing off, renaming, or reassigning.',
        'product' => 'Choose an active product to reassign this batch to.',
        'not_undoable' => 'That movement cannot be undone (only Write-off / Close can be undone from here).',
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
      Batches are your deliveries / mixes. LOW / NONE are estimates only — they do not change costing.
      Use <strong>Correct stock</strong> when the shelf and the system disagree.
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="mix.php" class="btn btn-outline-secondary">
      <i class="bi bi-intersect"></i> Mix Rice
    </a>
    <a href="reports.php?tab=batch" class="btn btn-outline-secondary">
      <i class="bi bi-graph-up"></i> By lot / batch
    </a>
    <button type="button" class="btn btn-rice" data-bs-toggle="modal" data-bs-target="#correctModal">
      <i class="bi bi-clipboard-check"></i> Correct stock
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
      <h2 class="h6 mb-3">Open Batches</h2>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Product</th>
              <th>Batch</th>
              <th>Buy price</th>
              <th class="text-end">Remaining</th>
              <th>Date</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($openLots) === 0): ?>
              <tr>
                <td colspan="6" class="text-center text-muted">No open batches. Buy stock via Purchases or Mix Rice.</td>
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
                  $millName = trim((string) ($lot['mill_name'] ?? ''));
                  $lotKind = (string) ($lot['lot_kind'] ?? 'purchase');
                  $remaining = round((float) $lot['quantity_remaining'], 2);
                  $lotNone = isLotUnsalable($remaining);
                  $lotLow = isLotLow($remaining);
                  $lotId = (int) $lot['id'];
                ?>
                <tr class="<?= $lotNone ? 'table-secondary' : ($lotLow ? 'table-warning' : '') ?>">
                  <td class="fw-semibold"><?= htmlspecialchars($lot['product_name']) ?></td>
                  <td class="small">
                    <?php if ($lotKind === 'mix'): ?>
                      <span class="badge text-bg-info">MIX</span>
                    <?php endif; ?>
                    <?php if ($lotNone): ?>
                      <span class="badge text-bg-dark">NONE</span>
                    <?php elseif ($lotLow): ?>
                      <span class="badge text-bg-warning">LOW</span>
                    <?php endif; ?>
                    <?= $batchNote !== '' ? htmlspecialchars($batchNote) : '—' ?>
                    <?php if ($millName !== ''): ?>
                      <div class="text-muted"><?= htmlspecialchars($millName) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($priceLabel) ?></td>
                  <td class="text-end">
                    <?= number_format($remaining, $unit === 'pc' ? 0 : 2) ?>
                    <?= htmlspecialchars($unit) ?>
                  </td>
                  <td class="small text-muted"><?= htmlspecialchars($lot['purchased_at']) ?></td>
                  <td class="text-end">
                    <div class="dropdown">
                      <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        Manage
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                          <button
                            type="button"
                            class="dropdown-item"
                            data-bs-toggle="modal"
                            data-bs-target="#renameLotModal"
                            data-lot-id="<?= $lotId ?>"
                            data-notes="<?= htmlspecialchars($batchNote, ENT_QUOTES) ?>"
                            data-mill="<?= htmlspecialchars($millName, ENT_QUOTES) ?>"
                          >Rename / label</button>
                        </li>
                        <li>
                          <button
                            type="button"
                            class="dropdown-item"
                            data-bs-toggle="modal"
                            data-bs-target="#reassignLotModal"
                            data-lot-id="<?= $lotId ?>"
                            data-product-id="<?= (int) $lot['product_id'] ?>"
                          >Reassign product</button>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                          <button
                            type="button"
                            class="dropdown-item text-danger"
                            data-bs-toggle="modal"
                            data-bs-target="#correctModal"
                            data-mode="writeoff"
                            data-reason="writeoff"
                            data-product-id="<?= (int) $lot['product_id'] ?>"
                            data-lot-id="<?= $lotId ?>"
                          >Write off leftover</button>
                        </li>
                        <li>
                          <button
                            type="button"
                            class="dropdown-item text-danger"
                            data-bs-toggle="modal"
                            data-bs-target="#correctModal"
                            data-mode="writeoff"
                            data-reason="close"
                            data-product-id="<?= (int) $lot['product_id'] ?>"
                            data-lot-id="<?= $lotId ?>"
                          >Close batch (physically empty)</button>
                        </li>
                      </ul>
                    </div>
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
              $canUndoWriteoff = str_starts_with($ref, 'WRITEOFF') || str_starts_with($ref, 'CLOSE');
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

<div class="modal fade" id="correctModal" tabindex="-1" aria-labelledby="correctModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/inventory_adjust.php" id="correctForm">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="correctModalLabel">Correct stock</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label d-block">How</label>
            <div class="btn-group w-100" role="group" aria-label="Correction mode">
              <input type="radio" class="btn-check" name="mode" id="modeCount" value="count" checked>
              <label class="btn btn-outline-secondary" for="modeCount">Count</label>
              <input type="radio" class="btn-check" name="mode" id="modeAdjust" value="adjust">
              <label class="btn btn-outline-secondary" for="modeAdjust">Adjust</label>
              <input type="radio" class="btn-check" name="mode" id="modeWriteoff" value="writeoff">
              <label class="btn btn-outline-danger" for="modeWriteoff">Write-off</label>
            </div>
          </div>

          <div class="alert alert-light border small mb-3" id="correctHelp">
            Weigh or count what is on the shelf. The system will match batches to that number.
          </div>

          <div class="mb-3">
            <label for="correctProduct" class="form-label">Product</label>
            <select class="form-select" id="correctProduct" name="product_id" required>
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

          <div class="mb-3 d-none" id="correctLotWrap">
            <label for="correctLot" class="form-label">Batch</label>
            <select class="form-select" id="correctLot" name="stock_lot_id">
              <option value="">Select batch</option>
            </select>
            <div class="form-text" id="correctLotHelp">Required when deducting.</div>
          </div>

          <div class="mb-3 d-none" id="correctReasonWrap">
            <label class="form-label d-block">Write-off as</label>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="reason" id="reasonWriteoff" value="writeoff" checked>
              <label class="form-check-label" for="reasonWriteoff">Leftover / waste</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="reason" id="reasonClose" value="close">
              <label class="form-check-label" for="reasonClose">Close batch (physically empty)</label>
            </div>
          </div>

          <div class="mb-3" id="correctQtyWrap">
            <label for="correctQty" class="form-label" id="correctQtyLabel">Physical quantity</label>
            <input
              type="number"
              class="form-control"
              id="correctQty"
              name="quantity"
              step="0.01"
              min="0"
              required
              placeholder="What you actually have"
            >
            <div class="form-text" id="correctQtyHelp">Enter 0 if the sack is empty.</div>
          </div>

          <div class="mb-0">
            <label for="correctNotes" class="form-label">Notes</label>
            <input type="text" class="form-control" id="correctNotes" name="notes" placeholder="Optional">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice" id="correctSubmit">Save count</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="renameLotModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/inventory_rename_lot.php">
        <div class="modal-header">
          <h2 class="modal-title fs-5">Rename batch</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="stock_lot_id" id="renameLotId" value="">
          <div class="mb-3">
            <label for="renameNotes" class="form-label">Batch label (what cashiers see)</label>
            <input type="text" class="form-control" id="renameNotes" name="notes" maxlength="255" placeholder="e.g. Wet season · Truck 2">
          </div>
          <div class="mb-0">
            <label for="renameMill" class="form-label">Mill / supplier name (optional)</label>
            <input type="text" class="form-control" id="renameMill" name="mill_name" maxlength="255" placeholder="What the supplier called it">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice">Save label</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="reassignLotModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/inventory_reassign_lot.php">
        <div class="modal-header">
          <h2 class="modal-title fs-5">Reassign batch to another product</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">
            Use when you rename / rebrand rice without creating a new orphan product.
            Cost and purchase history stay on this batch.
          </p>
          <input type="hidden" name="stock_lot_id" id="reassignLotId" value="">
          <div class="mb-0">
            <label for="reassignProduct" class="form-label">Sell as</label>
            <select class="form-select" id="reassignProduct" name="product_id" required>
              <option value="">Select product</option>
              <?php foreach ($products as $product): ?>
                <?php if (($product['status'] ?? '') !== 'active') { continue; } ?>
                <option value="<?= (int) $product['id'] ?>">
                  <?= htmlspecialchars($product['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice">Reassign</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const lotsByProduct = <?= json_encode($lotsForJs, JSON_UNESCAPED_UNICODE) ?>;
  const form = document.getElementById('correctForm');
  const modal = document.getElementById('correctModal');
  const productSelect = document.getElementById('correctProduct');
  const lotSelect = document.getElementById('correctLot');
  const lotWrap = document.getElementById('correctLotWrap');
  const lotHelp = document.getElementById('correctLotHelp');
  const qtyInput = document.getElementById('correctQty');
  const qtyWrap = document.getElementById('correctQtyWrap');
  const qtyLabel = document.getElementById('correctQtyLabel');
  const qtyHelp = document.getElementById('correctQtyHelp');
  const reasonWrap = document.getElementById('correctReasonWrap');
  const help = document.getElementById('correctHelp');
  const submitBtn = document.getElementById('correctSubmit');

  const renameModal = document.getElementById('renameLotModal');
  if (renameModal) {
    renameModal.addEventListener('show.bs.modal', function (event) {
      const btn = event.relatedTarget;
      if (!btn) return;
      document.getElementById('renameLotId').value = btn.getAttribute('data-lot-id') || '';
      document.getElementById('renameNotes').value = btn.getAttribute('data-notes') || '';
      document.getElementById('renameMill').value = btn.getAttribute('data-mill') || '';
    });
  }

  const reassignModal = document.getElementById('reassignLotModal');
  if (reassignModal) {
    reassignModal.addEventListener('show.bs.modal', function (event) {
      const btn = event.relatedTarget;
      if (!btn) return;
      document.getElementById('reassignLotId').value = btn.getAttribute('data-lot-id') || '';
      document.getElementById('reassignProduct').value = btn.getAttribute('data-product-id') || '';
    });
  }

  function currentMode() {
    const checked = document.querySelector('#correctForm input[name="mode"]:checked');
    return checked ? checked.value : 'count';
  }

  function selectedUnit() {
    const option = productSelect.selectedOptions[0];
    return option && option.dataset.unit ? option.dataset.unit : 'kg';
  }

  function selectedStock() {
    const option = productSelect.selectedOptions[0];
    return option && option.dataset.stock ? option.dataset.stock : '0';
  }

  function populateLots(preferredLotId) {
    const productId = productSelect.value;
    const lots = lotsByProduct[productId] || [];
    const previous = preferredLotId || lotSelect.value;
    const mode = currentMode();
    lotSelect.innerHTML = '';

    const blank = document.createElement('option');
    blank.value = '';
    if (mode === 'writeoff') {
      blank.textContent = lots.length ? 'Select batch' : 'No open batch';
    } else if (mode === 'adjust') {
      blank.textContent = lots.length
        ? 'Select batch (or blank if adding new)'
        : 'No open batch — leave blank to create';
    } else {
      blank.textContent = 'Select batch';
    }
    lotSelect.appendChild(blank);

    lots.forEach(function (lot) {
      const opt = document.createElement('option');
      opt.value = String(lot.id);
      opt.textContent = lot.label;
      opt.dataset.remaining = String(lot.remaining);
      lotSelect.appendChild(opt);
    });

    if (previous && [...lotSelect.options].some(function (o) { return o.value === String(previous); })) {
      lotSelect.value = String(previous);
    }
  }

  function updateWriteoffQty() {
    if (currentMode() !== 'writeoff') {
      return;
    }
    const lotOption = lotSelect.selectedOptions[0];
    const remaining = lotOption && lotOption.dataset.remaining
      ? parseFloat(lotOption.dataset.remaining)
      : NaN;
    const unit = selectedUnit();
    if (!isNaN(remaining) && lotSelect.value) {
      qtyInput.value = unit === 'pc' ? String(Math.round(remaining)) : remaining.toFixed(2);
      qtyHelp.textContent = 'Entire leftover on this batch will be written off as shrink.';
    } else {
      qtyInput.value = '';
      qtyHelp.textContent = 'Choose a batch. Leftover becomes shrink on Batch Profit.';
    }
  }

  function updateModeUi(opts) {
    const options = opts || {};
    const mode = currentMode();
    const unit = selectedUnit();
    const isPc = unit === 'pc';
    const skipPrefill = !!options.skipPrefill;

    lotWrap.classList.toggle('d-none', mode === 'count');
    reasonWrap.classList.toggle('d-none', mode !== 'writeoff');
    qtyWrap.classList.remove('d-none');

    lotSelect.required = mode === 'writeoff';
    qtyInput.readOnly = mode === 'writeoff';
    qtyInput.classList.toggle('bg-light', mode === 'writeoff');
    qtyInput.required = mode !== 'writeoff';

    if (mode === 'count') {
      help.textContent = 'Weigh or count what is on the shelf. The system will match batches to that number.';
      qtyLabel.textContent = 'Physical quantity (' + unit + ')';
      qtyInput.min = '0';
      qtyInput.removeAttribute('placeholder');
      qtyInput.placeholder = 'What you actually have';
      qtyInput.step = isPc ? '1' : '0.01';
      qtyHelp.textContent = productSelect.value
        ? ('System currently shows ' + selectedStock() + ' ' + unit + '. Enter 0 if empty.')
        : 'Enter 0 if the sack is empty.';
      submitBtn.textContent = 'Save count';
      if (!skipPrefill && productSelect.value) {
        qtyInput.value = selectedStock();
      }
    } else if (mode === 'adjust') {
      help.textContent = 'Add or deduct a known amount. Use + to add, − to deduct. Pick a batch when deducting.';
      qtyLabel.textContent = 'Quantity (' + unit + ')';
      qtyInput.removeAttribute('min');
      qtyInput.placeholder = 'Use + to add, − to deduct';
      qtyInput.step = isPc ? '1' : '0.01';
      qtyHelp.textContent = isPc
        ? 'Example: 10 adds stock, −5 deducts stock. Use whole numbers for pc.'
        : 'Example: 10 adds stock, −5 deducts stock.';
      lotHelp.textContent = 'Required when deducting. When adding, pick a batch to top up, or leave blank to create a new batch at the current buy price.';
      submitBtn.textContent = 'Save adjustment';
      if (!skipPrefill) {
        qtyInput.value = '';
      }
    } else {
      help.textContent = 'Zero leftover on a batch (empty sack / waste). Shrink cost hits Batch Profit.';
      qtyLabel.textContent = 'Leftover to write off (' + unit + ')';
      qtyInput.min = '0';
      qtyInput.placeholder = '';
      qtyInput.step = isPc ? '1' : '0.01';
      lotHelp.textContent = 'Required. The leftover on this batch will be zeroed.';
      submitBtn.textContent = document.getElementById('reasonClose').checked
        ? 'Close batch'
        : 'Write off leftover';
    }

    populateLots(options.lotId || null);
    if (mode === 'writeoff') {
      updateWriteoffQty();
    }
  }

  function applyOpener(btn) {
    const mode = (btn && btn.getAttribute('data-mode')) || 'count';
    const reason = (btn && btn.getAttribute('data-reason')) || 'writeoff';
    const productId = (btn && btn.getAttribute('data-product-id')) || '';
    const lotId = (btn && btn.getAttribute('data-lot-id')) || '';

    const modeEl = document.querySelector('#correctForm input[name="mode"][value="' + mode + '"]');
    if (modeEl) {
      modeEl.checked = true;
    }
    if (reason === 'close') {
      document.getElementById('reasonClose').checked = true;
    } else {
      document.getElementById('reasonWriteoff').checked = true;
    }

    if (productId) {
      productSelect.value = productId;
    }

    updateModeUi({ skipPrefill: mode !== 'count', lotId: lotId });
  }

  document.querySelectorAll('#correctForm input[name="mode"]').forEach(function (el) {
    el.addEventListener('change', function () {
      updateModeUi();
    });
  });
  document.querySelectorAll('#correctForm input[name="reason"]').forEach(function (el) {
    el.addEventListener('change', function () {
      if (currentMode() === 'writeoff') {
        submitBtn.textContent = document.getElementById('reasonClose').checked
          ? 'Close batch'
          : 'Write off leftover';
      }
    });
  });
  productSelect.addEventListener('change', function () {
    updateModeUi({ skipPrefill: currentMode() === 'adjust' });
  });
  lotSelect.addEventListener('change', updateWriteoffQty);

  if (form) {
    form.addEventListener('submit', function (event) {
      const mode = currentMode();
      const qty = parseFloat(qtyInput.value) || 0;
      if (mode === 'adjust' && qty < 0 && !lotSelect.value) {
        event.preventDefault();
        lotSelect.focus();
        lotHelp.textContent = 'Pick a batch to deduct from.';
        return;
      }
      if (mode === 'writeoff') {
        const isClose = document.getElementById('reasonClose').checked;
        const ok = confirm(
          isClose
            ? 'Close this batch? Remaining estimate becomes shrink loss.'
            : 'Write off leftover on this batch? Shrink cost hits batch profit.'
        );
        if (!ok) {
          event.preventDefault();
        }
      }
    });
  }

  if (modal) {
    modal.addEventListener('show.bs.modal', function (event) {
      applyOpener(event.relatedTarget);
    });
    modal.addEventListener('hidden.bs.modal', function () {
      form.reset();
      document.getElementById('modeCount').checked = true;
      document.getElementById('reasonWriteoff').checked = true;
      updateModeUi();
    });
  }

  updateModeUi();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
