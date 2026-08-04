<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/stock_lots.php';
requireLogin();

$user = currentUser();
$pageTitle = 'New Sale';
$activePage = 'sales-new';

$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name ASC')->fetchAll();
$products = $pdo->query(
    "SELECT id, name, product_type, unit, selling_price, stock
     FROM products
     WHERE status = 'active'
     ORDER BY (product_type = 'RICE') DESC, name ASC"
)->fetchAll();

$lotsByProduct = fetchOpenLotsByProduct($pdo);

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

$riceProducts = [];
$otherProducts = [];
foreach ($products as $p) {
    if (($p['product_type'] ?? 'RICE') === 'RICE') {
        $riceProducts[] = $p;
    } else {
        $otherProducts[] = $p;
    }
}

$flash = '';
$flashType = 'danger';

if (isset($_GET['error'])) {
    $flash = match ($_GET['error']) {
        'required' => 'Sale date is required.',
        'customer' => 'Enter the borrower name for walk-in utang, or select a customer.',
        'items' => 'Add at least one valid product line and choose a stock stack.',
        'stock' => 'Not enough stock for '
            . htmlspecialchars($_GET['product'] ?? 'selected product')
            . '.',
        'lot' => 'Choose a valid stock stack for each item.',
        'save' => 'Could not save the sale. Please try again.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">New Sale</h1>
    <p class="text-muted mb-0">
      Just buying? Leave customer as walk-in. Use Lend only for utang.
      Pick which priced stack to sell from when a product has more than one buy price.
    </p>
  </div>
  <a href="sales.php" class="btn btn-outline-secondary">Sales History</a>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= $flash ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php if (count($products) === 0): ?>
  <div class="alert alert-warning">
    Add at least one active <a href="products.php">product</a> before creating a sale.
  </div>
<?php else: ?>
  <form method="POST" action="/rice-business/backend/sale_save.php" id="saleForm" class="bg-white rounded shadow-sm p-3 p-md-4">
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label for="customerId" class="form-label">Customer</label>
        <select class="form-select" id="customerId" name="customer_id">
          <option value="">Walk-in / Just buying</option>
          <?php foreach ($customers as $customer): ?>
            <option value="<?= (int) $customer['id'] ?>"><?= htmlspecialchars($customer['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text" id="customerHint">
          No need to pick a name if they are only buying and paying now.
        </div>
      </div>
      <div class="col-md-4 d-none" id="walkinNameWrap">
        <label for="walkinName" class="form-label">Borrower name</label>
        <input
          type="text"
          class="form-control"
          id="walkinName"
          name="walkin_name"
          maxlength="100"
          placeholder="Who is borrowing the rice?"
        >
        <div class="form-text">Only needed for utang so you know who owes you.</div>
      </div>
      <div class="col-md-4">
        <label for="paymentMethod" class="form-label">Payment</label>
        <select class="form-select" id="paymentMethod" name="payment_method" required>
          <option value="cash">Cash (paid)</option>
          <option value="gcash">GCash (paid)</option>
          <option value="bank">Bank Transfer (paid)</option>
          <option value="credit">Lend (Utang)</option>
        </select>
      </div>
      <div class="col-md-4">
        <label for="saleDate" class="form-label">Date</label>
        <input type="date" class="form-control" id="saleDate" name="sale_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="col-md-8">
        <label for="notes" class="form-label">Notes</label>
        <input type="text" class="form-control" id="notes" name="notes" placeholder="Optional">
      </div>
    </div>

    <div class="alert alert-warning d-none mb-4" id="lendNotice">
      This is a <strong>lend / utang</strong> (not a normal paid sale). Stock is deducted now; money comes later.
      If they are not in your customer list, type their name in <strong>Borrower name</strong>.
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
            <th style="min-width: 220px;">Stack</th>
            <th style="min-width: 110px;">₱/kg</th>
            <th style="min-width: 110px;">Qty</th>
            <th style="min-width: 120px;" class="text-end">Total (₱)</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
          <tr>
            <td colspan="4" class="text-end fw-semibold">Total</td>
            <td class="text-end fw-bold" id="grandTotal">₱0.00</td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-rice">Save Sale</button>
      <a href="sales.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>

  <template id="itemRowTemplate">
    <tr>
      <td>
        <select class="form-select product-select" name="product_id[]" required>
          <option value="">Select product</option>
          <?php if (count($riceProducts) > 0): ?>
            <optgroup label="Rice (kg)">
              <?php foreach ($riceProducts as $product): ?>
                <option
                  value="<?= (int) $product['id'] ?>"
                  data-selling-price="<?= htmlspecialchars($product['selling_price']) ?>"
                  data-stock="<?= htmlspecialchars($product['stock']) ?>"
                  data-unit="<?= htmlspecialchars($product['unit'] ?? 'kg') ?>"
                >
                  <?= htmlspecialchars($product['name']) ?>
                  (stock: <?= number_format((float) $product['stock'], 2) ?> kg)
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
                  data-selling-price="<?= htmlspecialchars($product['selling_price']) ?>"
                  data-stock="<?= htmlspecialchars($product['stock']) ?>"
                  data-unit="<?= htmlspecialchars($unit) ?>"
                >
                  <?= htmlspecialchars($product['name']) ?>
                  (stock: <?= number_format((float) $product['stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>)
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
        <div class="btn-group btn-group-sm mt-2 w-100 entry-mode" role="group" aria-label="How to enter this item">
          <button type="button" class="btn btn-outline-secondary btn-mode active" data-mode="qty">By kg</button>
          <button type="button" class="btn btn-outline-secondary btn-mode" data-mode="amount">By ₱ amount</button>
        </div>
      </td>
      <td>
        <select class="form-select lot-select" name="stock_lot_id[]" required>
          <option value="">Select stack</option>
        </select>
        <div class="form-text lot-hint">Buy price stack</div>
      </td>
      <td>
        <input type="number" class="form-control price-input" name="price[]" step="0.01" min="0" value="0" required>
        <div class="form-text">per kg</div>
      </td>
      <td>
        <input type="number" class="form-control qty-input" name="quantity[]" step="0.01" min="0.01" value="1" required>
        <div class="form-text qty-hint">kg</div>
        <input type="hidden" class="line-subtotal-hidden" name="line_subtotal[]" value="">
      </td>
      <td class="text-end line-total-cell">
        <span class="subtotal-display fw-semibold">₱0.00</span>
        <input
          type="number"
          class="form-control text-end subtotal-input d-none"
          step="0.01"
          min="0"
          placeholder="e.g. 100"
          aria-label="Customer pays"
        >
      </td>
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
    const paymentMethod = document.getElementById('paymentMethod');
    const customerId = document.getElementById('customerId');
    const lendNotice = document.getElementById('lendNotice');
    const walkinNameWrap = document.getElementById('walkinNameWrap');
    const walkinName = document.getElementById('walkinName');
    const customerHint = document.getElementById('customerHint');
    const lotsByProduct = <?= json_encode($lotsForJs, JSON_UNESCAPED_UNICODE) ?>;

    function formatMoney(value) {
      return '₱' + Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function updateLendUi() {
      const isLend = paymentMethod.value === 'credit';
      const isWalkin = customerId.value === '';
      const needWalkinName = isLend && isWalkin;

      lendNotice.classList.toggle('d-none', !isLend);
      walkinNameWrap.classList.toggle('d-none', !needWalkinName);
      walkinName.required = needWalkinName;
      customerHint.classList.toggle('d-none', isLend);
      if (!needWalkinName) {
        walkinName.value = '';
      }
    }

    function recalcGrandTotal() {
      let total = 0;
      tbody.querySelectorAll('tr').forEach(function (row) {
        const mode = row.dataset.entryMode || 'qty';
        if (mode === 'amount') {
          total += parseFloat(row.querySelector('.subtotal-input').value) || 0;
          return;
        }
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        total += qty * price;
      });
      grandTotalEl.textContent = formatMoney(total);
    }

    function bindRow(row) {
      const productSelect = row.querySelector('.product-select');
      const lotSelect = row.querySelector('.lot-select');
      const priceInput = row.querySelector('.price-input');
      const qtyInput = row.querySelector('.qty-input');
      const subtotalInput = row.querySelector('.subtotal-input');
      const subtotalDisplay = row.querySelector('.subtotal-display');
      const lineSubtotalHidden = row.querySelector('.line-subtotal-hidden');
      const qtyHint = row.querySelector('.qty-hint');
      let entryMode = 'qty';

      function getUnit() {
        const option = productSelect.selectedOptions[0];
        return option && option.dataset.unit ? option.dataset.unit : 'kg';
      }

      function formatQtyFromAmount(qty) {
        if (getUnit() === 'pc') {
          return String(Math.max(1, Math.round(qty)));
        }
        return (Math.round(qty * 10000) / 10000).toFixed(4);
      }

      function syncLineSubtotalHidden() {
        if (entryMode === 'amount') {
          const amount = parseFloat(subtotalInput.value);
          lineSubtotalHidden.value = amount > 0 ? amount.toFixed(2) : '';
        } else {
          lineSubtotalHidden.value = '';
        }
      }

      function applyUnitRules() {
        const unit = getUnit();
        if (entryMode === 'qty') {
          qtyHint.textContent = unit;
        }
        if (unit === 'pc') {
          qtyInput.step = '1';
          qtyInput.min = '1';
          if (entryMode === 'qty') {
            qtyInput.value = String(Math.max(1, Math.round(parseFloat(qtyInput.value) || 1)));
          }
        } else {
          qtyInput.step = entryMode === 'amount' ? '0.0001' : '0.01';
          qtyInput.min = '0.01';
        }
      }

      function applyLotMax() {
        const lotOption = lotSelect.selectedOptions[0];
        if (lotOption && lotOption.dataset.remaining) {
          qtyInput.max = lotOption.dataset.remaining;
        } else {
          qtyInput.removeAttribute('max');
        }
      }

      function populateLots(preferredLotId) {
        const productId = productSelect.value;
        const lots = lotsByProduct[productId] || [];
        const previous = preferredLotId || lotSelect.value;
        lotSelect.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = lots.length ? 'Select stack' : 'No stack available';
        lotSelect.appendChild(placeholder);

        lots.forEach(function (lot) {
          const opt = document.createElement('option');
          opt.value = String(lot.id);
          opt.textContent = lot.label;
          opt.dataset.remaining = String(lot.remaining);
          lotSelect.appendChild(opt);
        });

        if (previous && [...lotSelect.options].some(function (o) { return o.value === String(previous); })) {
          lotSelect.value = String(previous);
        } else if (lots.length === 1) {
          lotSelect.value = String(lots[0].id);
        }

        applyLotMax();
      }

      function updateTotalDisplay() {
        const qty = parseFloat(qtyInput.value) || 0;
        const price = parseFloat(priceInput.value) || 0;
        subtotalDisplay.textContent = formatMoney(qty * price);
        syncLineSubtotalHidden();
        recalcGrandTotal();
      }

      function calcQtyFromAmount() {
        const amount = parseFloat(subtotalInput.value) || 0;
        const price = parseFloat(priceInput.value) || 0;
        if (price > 0 && amount > 0) {
          qtyInput.value = formatQtyFromAmount(amount / price);
        } else if (amount <= 0) {
          qtyInput.value = '';
        }
        syncLineSubtotalHidden();
        recalcGrandTotal();
      }

      function refreshLine() {
        if (entryMode === 'amount') {
          calcQtyFromAmount();
        } else {
          updateTotalDisplay();
        }
      }

      function setEntryMode(mode) {
        if (mode === 'amount' && entryMode === 'qty') {
          const qty = parseFloat(qtyInput.value) || 0;
          const price = parseFloat(priceInput.value) || 0;
          if (qty > 0 && price > 0) {
            subtotalInput.value = (qty * price).toFixed(2);
          }
        }

        entryMode = mode;
        row.dataset.entryMode = mode;
        const isAmount = mode === 'amount';

        row.querySelectorAll('.btn-mode').forEach(function (btn) {
          btn.classList.toggle('active', btn.dataset.mode === mode);
        });

        qtyInput.readOnly = isAmount;
        qtyInput.classList.toggle('bg-light', isAmount);
        subtotalInput.classList.toggle('d-none', !isAmount);
        subtotalDisplay.classList.toggle('d-none', isAmount);
        qtyHint.textContent = isAmount ? 'auto' : getUnit();
        applyUnitRules();

        if (isAmount) {
          calcQtyFromAmount();
          subtotalInput.focus();
        } else {
          updateTotalDisplay();
        }
      }

      productSelect.addEventListener('change', function () {
        const option = productSelect.selectedOptions[0];
        if (option && option.dataset.sellingPrice) {
          priceInput.value = option.dataset.sellingPrice;
        }
        populateLots();
        applyUnitRules();
        refreshLine();
      });

      lotSelect.addEventListener('change', function () {
        applyLotMax();
      });

      qtyInput.addEventListener('input', function () {
        if (entryMode === 'qty') {
          updateTotalDisplay();
        }
      });
      priceInput.addEventListener('input', refreshLine);
      subtotalInput.addEventListener('input', function () {
        if (entryMode === 'amount') {
          calcQtyFromAmount();
        }
      });

      row.querySelectorAll('.btn-mode').forEach(function (btn) {
        btn.addEventListener('click', function () {
          setEntryMode(btn.dataset.mode);
        });
      });

      row.querySelector('.btn-remove-row').addEventListener('click', function () {
        if (tbody.querySelectorAll('tr').length === 1) {
          return;
        }
        row.remove();
        recalcGrandTotal();
      });

      row._populateLots = populateLots;
      setEntryMode('qty');
    }

    function addRow() {
      const node = template.content.cloneNode(true);
      const row = node.querySelector('tr');
      tbody.appendChild(row);
      bindRow(row);
      recalcGrandTotal();
    }

    paymentMethod.addEventListener('change', updateLendUi);
    customerId.addEventListener('change', updateLendUi);
    document.getElementById('btnAddRow').addEventListener('click', addRow);
    updateLendUi();
    addRow();
  });
  </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
