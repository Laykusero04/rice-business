<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'New Purchase';
$activePage = 'purchases-new';

$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll();
$products = $pdo->query(
    "SELECT id, name, product_type, unit, buying_price, kg_per_sack, stock
     FROM products
     WHERE status = 'active'
     ORDER BY (product_type = 'RICE') DESC, name ASC"
)->fetchAll();

$riceProducts = [];
$otherProducts = [];
foreach ($products as $p) {
    if (($p['product_type'] ?? 'RICE') === 'RICE') {
        $riceProducts[] = $p;
    } else {
        $otherProducts[] = $p;
    }
}

$prefillProductId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
if ($prefillProductId > 0) {
    $validPrefill = false;
    foreach ($products as $p) {
        if ((int) $p['id'] === $prefillProductId) {
            $validPrefill = true;
            break;
        }
    }
    if (!$validPrefill) {
        $prefillProductId = 0;
    }
}

$flash = '';
$flashType = 'danger';

$paymentSourceLabels = [
    'business' => 'Business funds',
    'personal' => 'Personal money (mine)',
];

if (isset($_GET['error'])) {
    $flash = match ($_GET['error']) {
        'required' => 'Supplier and purchase date are required.',
        'items' => 'Add at least one valid product line.',
        'save' => 'Could not save the purchase. Please try again.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">New Purchase</h1>
    <p class="text-muted mb-0">
      Rice is bought by sack (stock added in kg). Other items by unit (pc/L/ml).
      Each line creates a priced <strong>batch</strong> — use Batch / note when the same product has a different buy price.
    </p>
  </div>
  <a href="purchases.php" class="btn btn-outline-secondary">Purchase History</a>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php if (count($suppliers) === 0 || count($products) === 0): ?>
  <div class="alert alert-warning">
    <?php if (count($suppliers) === 0): ?>
      Add at least one <a href="suppliers.php">supplier</a> before creating a purchase.
    <?php endif; ?>
    <?php if (count($products) === 0): ?>
      Add at least one active <a href="products.php">product</a> before creating a purchase.
    <?php endif; ?>
  </div>
<?php else: ?>
  <form method="POST" action="/rice-business/backend/purchase_save.php" id="purchaseForm" class="bg-white rounded shadow-sm p-3 p-md-4">
    <div class="row g-3 mb-4">
      <div class="col-md-5">
        <label for="supplierId" class="form-label">Supplier</label>
        <select class="form-select" id="supplierId" name="supplier_id" required>
          <option value="">Select supplier</option>
          <?php foreach ($suppliers as $supplier): ?>
            <option value="<?= (int) $supplier['id'] ?>"><?= htmlspecialchars($supplier['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label for="purchaseDate" class="form-label">Date</label>
        <input type="date" class="form-control" id="purchaseDate" name="purchase_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="col-md-4">
        <label for="paymentSource" class="form-label">Paid with</label>
        <select class="form-select" id="paymentSource" name="payment_source" required>
          <?php foreach ($paymentSourceLabels as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Personal money is recorded as Owner Investment in Expenses.</div>
      </div>
      <div class="col-12">
        <label for="notes" class="form-label">Notes</label>
        <input type="text" class="form-control" id="notes" name="notes" placeholder="Optional">
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h6 mb-0">Items</h2>
      <button type="button" class="btn btn-sm btn-outline-success" id="btnAddRow">
        <i class="bi bi-plus-lg"></i> Add Row
      </button>
    </div>

    <div class="table-responsive mb-3">
      <table class="table align-middle" id="itemsTable">
        <thead class="table-light">
          <tr>
            <th style="min-width: 200px;">Rice / Product</th>
            <th style="min-width: 140px;">Batch / note</th>
            <th style="min-width: 120px;">Qty</th>
            <th style="min-width: 150px;">Unit price</th>
            <th style="min-width: 140px;">Stock in</th>
            <th style="min-width: 120px;" class="text-end">Subtotal</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
          <tr>
            <td colspan="5" class="text-end fw-semibold">Total</td>
            <td class="text-end fw-bold" id="grandTotal">₱0.00</td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-rice">Save Purchase</button>
      <a href="purchases.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>

  <template id="itemRowTemplate">
    <tr>
      <td>
        <select class="form-select product-select" name="product_id[]" required>
          <option value="">Select product</option>
          <?php if (count($riceProducts) > 0): ?>
            <optgroup label="Rice (by sack)">
              <?php foreach ($riceProducts as $product): ?>
                <?php
                  $kgPerSack = (float) ($product['kg_per_sack'] ?? 25);
                  if ($kgPerSack <= 0) {
                      $kgPerSack = 25;
                  }
                  $sackPrice = (float) $product['buying_price'] * $kgPerSack;
                  $sacksOnHand = (float) $product['stock'] / $kgPerSack;
                ?>
                <option
                  value="<?= (int) $product['id'] ?>"
                  data-product-type="RICE"
                  data-unit="kg"
                  data-kg-per-sack="<?= htmlspecialchars(number_format($kgPerSack, 2, '.', '')) ?>"
                  data-unit-price="<?= htmlspecialchars(number_format($sackPrice, 2, '.', '')) ?>"
                >
                  <?= htmlspecialchars($product['name']) ?>
                  (<?= number_format($sacksOnHand, 2) ?> sack · <?= number_format((float) $product['stock'], 2) ?> kg)
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <?php if (count($otherProducts) > 0): ?>
            <optgroup label="Other items">
              <?php foreach ($otherProducts as $product): ?>
                <?php $unit = $product['unit'] ?? 'pc'; ?>
                <option
                  value="<?= (int) $product['id'] ?>"
                  data-product-type="GROCERY"
                  data-unit="<?= htmlspecialchars($unit) ?>"
                  data-kg-per-sack="25"
                  data-unit-price="<?= htmlspecialchars(number_format((float) $product['buying_price'], 2, '.', '')) ?>"
                >
                  <?= htmlspecialchars($product['name']) ?>
                  (stock: <?= number_format((float) $product['stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>)
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
      </td>
      <td>
        <input
          type="text"
          class="form-control batch-label-input"
          name="batch_label[]"
          maxlength="255"
          placeholder="e.g. Wet season"
        >
      </td>
      <td>
        <input type="number" class="form-control qty-input" name="quantity[]" step="0.01" min="0.01" value="1" required>
        <div class="form-text qty-hint">sack</div>
      </td>
      <td>
        <input type="number" class="form-control unit-price-input" name="unit_price[]" step="0.01" min="0" value="0" required>
        <div class="form-text price-hint">₱ / sack</div>
      </td>
      <td class="kg-cell small text-muted">0.00 kg</td>
      <td class="text-end subtotal-cell">₱0.00</td>
      <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" title="Remove">
          <i class="bi bi-trash"></i>
        </button>
      </td>
    </tr>
  </template>

  <script>
  document.addEventListener('DOMContentLoaded', function () {
    const tbody = document.querySelector('#itemsTable tbody');
    const template = document.getElementById('itemRowTemplate');
    const grandTotalEl = document.getElementById('grandTotal');
    const prefillProductId = <?= (int) $prefillProductId ?>;

    function formatMoney(value) {
      return '₱' + Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function recalc() {
      let total = 0;
      tbody.querySelectorAll('tr').forEach(function (row) {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const unitPrice = parseFloat(row.querySelector('.unit-price-input').value) || 0;
        const option = row.querySelector('.product-select').selectedOptions[0];
        const productType = option && option.dataset.productType ? option.dataset.productType : 'RICE';
        const unit = option && option.dataset.unit ? option.dataset.unit : 'kg';
        const kgPerSack = option && option.dataset.kgPerSack ? parseFloat(option.dataset.kgPerSack) : 25;
        const stockInText = productType === 'RICE'
          ? (qty * kgPerSack).toFixed(2) + ' kg'
          : (unit === 'pc' ? String(Math.round(qty)) : qty.toFixed(2)) + ' ' + unit;
        const subtotal = qty * unitPrice;

        row.querySelector('.kg-cell').textContent = stockInText;
        row.querySelector('.subtotal-cell').textContent = formatMoney(subtotal);
        total += subtotal;
      });
      grandTotalEl.textContent = formatMoney(total);
    }

    function bindRow(row) {
      const productSelect = row.querySelector('.product-select');
      const unitPriceInput = row.querySelector('.unit-price-input');
      const qtyInput = row.querySelector('.qty-input');
      const qtyHint = row.querySelector('.qty-hint');
      const priceHint = row.querySelector('.price-hint');

      productSelect.addEventListener('change', function () {
        const option = productSelect.selectedOptions[0];
        const productType = option && option.dataset.productType ? option.dataset.productType : 'RICE';
        const unit = option && option.dataset.unit ? option.dataset.unit : 'kg';
        if (option && option.dataset.unitPrice) {
          unitPriceInput.value = option.dataset.unitPrice;
        }
        if (productType === 'RICE') {
          qtyHint.textContent = 'sack';
          priceHint.textContent = '₱ / sack';
          qtyInput.step = '0.01';
          qtyInput.min = '0.01';
        } else {
          qtyHint.textContent = unit;
          priceHint.textContent = '₱ / ' + unit;
          if (unit === 'pc') {
            qtyInput.step = '1';
            qtyInput.min = '1';
            qtyInput.value = String(Math.max(1, Math.round(parseFloat(qtyInput.value) || 1)));
          } else {
            qtyInput.step = '0.01';
            qtyInput.min = '0.01';
          }
        }
        recalc();
      });

      qtyInput.addEventListener('input', recalc);
      unitPriceInput.addEventListener('input', recalc);

      row.querySelector('.btn-remove-row').addEventListener('click', function () {
        if (tbody.querySelectorAll('tr').length === 1) {
          return;
        }
        row.remove();
        recalc();
      });
    }

    function addRow(presetProductId) {
      const node = template.content.cloneNode(true);
      const row = node.querySelector('tr');
      tbody.appendChild(row);
      bindRow(row);
      if (presetProductId) {
        const select = row.querySelector('.product-select');
        select.value = String(presetProductId);
        select.dispatchEvent(new Event('change'));
      }
      recalc();
    }

    document.getElementById('btnAddRow').addEventListener('click', function () {
      addRow();
    });
    addRow(prefillProductId > 0 ? prefillProductId : null);
  });
  </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
