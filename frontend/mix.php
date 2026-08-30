<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/stock_lots.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Mix Rice';
$activePage = 'mix';

$products = $pdo->query(
    "SELECT id, name, product_type, unit, kg_per_sack, stock, status
     FROM products
     WHERE status = 'active'
     ORDER BY product_type ASC, name ASC"
)->fetchAll();

$riceProducts = array_values(array_filter(
    $products,
    static fn ($p) => ($p['product_type'] ?? '') === 'RICE'
));

$lotsByProduct = fetchOpenLotsByProduct($pdo);
$openLots = [];
foreach ($lotsByProduct as $lots) {
    foreach ($lots as $lot) {
        if (($lot['product_type'] ?? '') !== 'RICE') {
            continue;
        }
        if (isLotUnsalable((float) $lot['quantity_remaining'])) {
            continue;
        }
        $openLots[] = $lot;
    }
}

$flash = '';
$flashType = 'success';
if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'mixed' => 'Mix saved. New batch #' . (int) ($_GET['lot_id'] ?? 0) . ' is ready to sell.',
        default => '',
    };
}
if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'product' => 'Choose an active output product.',
        'inputs' => 'Add at least one source batch with a valid quantity.',
        'loss' => 'Loss percent is too high — almost nothing would remain.',
        'stock', 'lot' => 'A source batch does not have enough remaining stock.',
        'save' => 'Could not save the mix.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Mix Rice</h1>
    <p class="text-muted mb-0">
      Blend batches into one sellable lot. Total cost follows the pesos taken from each source;
      optional loss % shrinks output kg only (cost stays the same).
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="reports.php?tab=batch" class="btn btn-outline-secondary">By lot / batch</a>
    <a href="inventory.php" class="btn btn-outline-secondary">Inventory</a>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php if (count($riceProducts) === 0 || count($openLots) === 0): ?>
  <div class="alert alert-warning">
    Need active rice products and open batches. Add stock via
    <a href="purchase_new.php">Purchases</a> first.
  </div>
<?php else: ?>
  <form method="POST" action="/rice-business/backend/inventory_mix_save.php" class="bg-white rounded shadow-sm p-3 p-md-4" id="mixForm">
    <div class="row g-3 mb-4">
      <div class="col-md-5">
        <label for="outputProduct" class="form-label">Sell as (output product)</label>
        <select class="form-select" id="outputProduct" name="output_product_id" required>
          <option value="">Select product</option>
          <?php foreach ($riceProducts as $product): ?>
            <option value="<?= (int) $product['id'] ?>">
              <?= htmlspecialchars($product['name']) ?>
              (<?= number_format((float) $product['stock'], 2) ?> kg)
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Use an existing name or create a mix product on Products first.</div>
      </div>
      <div class="col-md-4">
        <label for="batchLabel" class="form-label">New batch label</label>
        <input type="text" class="form-control" id="batchLabel" name="batch_label" maxlength="255"
               placeholder="e.g. Store Mix A · Mar 30" required>
      </div>
      <div class="col-md-3">
        <label for="lossPercent" class="form-label">Loss % (optional)</label>
        <input type="number" class="form-control" id="lossPercent" name="loss_percent"
               step="0.1" min="0" max="50" value="0">
        <div class="form-text">e.g. spill / moisture. Cost pesos stay fully invested.</div>
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h6 mb-0">Source batches</h2>
      <button type="button" class="btn btn-sm btn-outline-success" id="btnAddSource">
        <i class="bi bi-plus-lg"></i> Add source
      </button>
    </div>

    <div class="table-responsive mb-3">
      <table class="table align-middle" id="sourcesTable">
        <thead class="table-light">
          <tr>
            <th style="min-width: 280px;">Batch</th>
            <th style="min-width: 140px;">Qty (kg)</th>
            <th style="min-width: 120px;">Est. cost</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
          <tr>
            <td class="text-end fw-semibold">Totals</td>
            <td class="fw-bold" id="totalQty">0.00 kg</td>
            <td class="fw-bold" id="totalCost">₱0.00</td>
            <td></td>
          </tr>
          <tr>
            <td class="text-end text-muted">Output after loss</td>
            <td colspan="2" class="text-muted" id="outputPreview">0.00 kg · ₱0.00/kg</td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-rice">Save Mix</button>
      <a href="inventory.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>

  <template id="sourceRowTemplate">
    <tr>
      <td>
        <select class="form-select source-lot" name="source_lot_id[]" required>
          <option value="">Select batch</option>
          <?php foreach ($openLots as $lot): ?>
            <?php
              $remaining = round((float) $lot['quantity_remaining'], 2);
              $buy = (float) $lot['buying_price'];
            ?>
            <option
              value="<?= (int) $lot['id'] ?>"
              data-remaining="<?= htmlspecialchars(number_format($remaining, 2, '.', '')) ?>"
              data-buy="<?= htmlspecialchars(number_format($buy, 2, '.', '')) ?>"
            >
              <?= htmlspecialchars($lot['product_name']) ?> · <?= htmlspecialchars(formatLotLabel($lot)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <input type="number" class="form-control source-qty" name="quantity[]" step="0.01" min="0.01" required>
        <div class="form-text source-max text-muted">max —</div>
      </td>
      <td class="source-cost small">₱0.00</td>
      <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-source" title="Remove">
          <i class="bi bi-trash"></i>
        </button>
      </td>
    </tr>
  </template>

  <script>
  document.addEventListener('DOMContentLoaded', function () {
    const tbody = document.querySelector('#sourcesTable tbody');
    const template = document.getElementById('sourceRowTemplate');
    const totalQtyEl = document.getElementById('totalQty');
    const totalCostEl = document.getElementById('totalCost');
    const outputPreviewEl = document.getElementById('outputPreview');
    const lossEl = document.getElementById('lossPercent');

    function money(n) {
      return '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function refreshRow(row) {
      const select = row.querySelector('.source-lot');
      const qtyInput = row.querySelector('.source-qty');
      const maxEl = row.querySelector('.source-max');
      const costEl = row.querySelector('.source-cost');
      const opt = select.options[select.selectedIndex];
      const remaining = opt && opt.value ? parseFloat(opt.dataset.remaining || '0') : 0;
      const buy = opt && opt.value ? parseFloat(opt.dataset.buy || '0') : 0;
      maxEl.textContent = remaining > 0 ? ('max ' + remaining.toFixed(2) + ' kg') : 'max —';
      qtyInput.max = remaining > 0 ? remaining.toFixed(2) : '';
      const qty = parseFloat(qtyInput.value || '0') || 0;
      costEl.textContent = money(qty * buy);
      refreshTotals();
    }

    function refreshTotals() {
      let qty = 0;
      let cost = 0;
      tbody.querySelectorAll('tr').forEach(function (row) {
        const select = row.querySelector('.source-lot');
        const qtyInput = row.querySelector('.source-qty');
        const opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) return;
        const buy = parseFloat(opt.dataset.buy || '0') || 0;
        const q = parseFloat(qtyInput.value || '0') || 0;
        qty += q;
        cost += q * buy;
      });
      const loss = Math.max(0, Math.min(50, parseFloat(lossEl.value || '0') || 0));
      const outQty = qty * (1 - loss / 100);
      const perKg = outQty > 0 ? cost / outQty : 0;
      totalQtyEl.textContent = qty.toFixed(2) + ' kg';
      totalCostEl.textContent = money(cost);
      outputPreviewEl.textContent = outQty.toFixed(2) + ' kg · ' + money(perKg) + '/kg';
    }

    function addRow() {
      const node = template.content.cloneNode(true);
      const row = node.querySelector('tr');
      row.querySelector('.source-lot').addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        const remaining = opt && opt.value ? parseFloat(opt.dataset.remaining || '0') : 0;
        const qtyInput = row.querySelector('.source-qty');
        if (remaining > 0 && (!qtyInput.value || parseFloat(qtyInput.value) > remaining)) {
          qtyInput.value = remaining.toFixed(2);
        }
        refreshRow(row);
      });
      row.querySelector('.source-qty').addEventListener('input', function () { refreshRow(row); });
      row.querySelector('.btn-remove-source').addEventListener('click', function () {
        if (tbody.querySelectorAll('tr').length <= 1) return;
        row.remove();
        refreshTotals();
      });
      tbody.appendChild(node);
      refreshTotals();
    }

    document.getElementById('btnAddSource').addEventListener('click', addRow);
    lossEl.addEventListener('input', refreshTotals);
    addRow();
    addRow();
  });
  </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
