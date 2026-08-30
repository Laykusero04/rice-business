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
    "SELECT id, name, product_type, unit, selling_price, selling_price_sack, kg_per_sack, stock
     FROM products
     WHERE status = 'active'
     ORDER BY (product_type = 'RICE') DESC, name ASC"
)->fetchAll();

$lotsByProduct = fetchOpenLotsByProduct($pdo);

$lotsForJs = [];
foreach ($lotsByProduct as $pid => $lots) {
    $sellable = [];
    foreach ($lots as $lot) {
        $remaining = round((float) $lot['quantity_remaining'], 2);
        // Hide unsalable crumbs from New Sale
        if (isLotUnsalable($remaining)) {
            continue;
        }
        $sellable[] = [
            'id' => (int) $lot['id'],
            'label' => formatLotLabel($lot),
            'remaining' => $remaining,
            'low' => isLotLow($remaining),
        ];
    }
    if (count($sellable) > 0) {
        $lotsForJs[(string) $pid] = $sellable;
        $lotsByProduct[$pid] = array_values(array_filter(
            $lots,
            static fn ($lot) => !isLotUnsalable((float) $lot['quantity_remaining'])
        ));
    } else {
        unset($lotsByProduct[$pid]);
    }
}

$riceProducts = [];
$otherProducts = [];
foreach ($products as $p) {
    $pid = (int) $p['id'];
    // Only products with an open batch can be sold
    if (!isset($lotsByProduct[$pid]) || count($lotsByProduct[$pid]) === 0) {
        continue;
    }
    if (($p['product_type'] ?? 'RICE') === 'RICE') {
        $riceProducts[] = $p;
    } else {
        $otherProducts[] = $p;
    }
}

$hasSellable = count($riceProducts) + count($otherProducts) > 0;

$flash = '';
$flashType = 'danger';

if (isset($_GET['error'])) {
    $flash = match ($_GET['error']) {
        'required' => 'Sale date is required.',
        'customer' => 'Enter the borrower name for walk-in utang, or select a customer.',
        'items' => 'Add at least one valid product line and choose a stock batch.',
        'stock' => 'Not enough stock for '
            . htmlspecialchars($_GET['product'] ?? 'selected product')
            . '.',
        'lot' => 'Choose a valid stock batch for each item.',
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
      Qty is in kg (0.01) or whole sacks. <strong>By kg</strong> uses the small-kg sell price;
      <strong>By sack</strong> uses the whole-sack sell price (no scoop waste). Batches under 0.05 kg are hidden.
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

<?php if (!$hasSellable): ?>
  <div class="alert alert-warning">
    No products with stock batches to sell.
    Add stock via <a href="purchase_new.php">New Purchase</a>, or create a
    <a href="products.php">product</a> first.
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
            <th style="min-width: 220px;">Rice / Product</th>
            <th style="min-width: 240px;">Batch</th>
            <th style="min-width: 110px;">Price</th>
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
        <input
          type="search"
          class="form-control form-control-sm mb-1 product-filter"
          placeholder="Search product…"
          autocomplete="off"
          aria-label="Filter products"
        >
        <select class="form-select product-select" name="product_id[]" required>
          <option value="">Select product</option>
          <?php if (count($riceProducts) > 0): ?>
            <optgroup label="Rice (kg)">
              <?php foreach ($riceProducts as $product): ?>
                <?php
                  $kgPerSack = (float) ($product['kg_per_sack'] ?? 25);
                  if ($kgPerSack <= 0) {
                      $kgPerSack = 25;
                  }
                  $sackSell = isset($product['selling_price_sack']) && $product['selling_price_sack'] !== null
                    ? (float) $product['selling_price_sack']
                    : round((float) $product['selling_price'] * $kgPerSack, 2);
                ?>
                <option
                  value="<?= (int) $product['id'] ?>"
                  data-product-type="RICE"
                  data-selling-price="<?= htmlspecialchars($product['selling_price']) ?>"
                  data-selling-price-sack="<?= htmlspecialchars(number_format($sackSell, 2, '.', '')) ?>"
                  data-kg-per-sack="<?= htmlspecialchars(number_format($kgPerSack, 2, '.', '')) ?>"
                  data-stock="<?= htmlspecialchars($product['stock']) ?>"
                  data-unit="kg"
                  data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                >
                  <?= htmlspecialchars($product['name']) ?>
                  (<?= number_format((float) $product['stock'], 2) ?> kg)
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
                  data-selling-price="<?= htmlspecialchars($product['selling_price']) ?>"
                  data-stock="<?= htmlspecialchars($product['stock']) ?>"
                  data-unit="<?= htmlspecialchars($unit) ?>"
                  data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                >
                  <?= htmlspecialchars($product['name']) ?>
                  (<?= number_format((float) $product['stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>)
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
        <div class="btn-group btn-group-sm mt-2 w-100 entry-mode" role="group" aria-label="How to enter this item">
          <button type="button" class="btn btn-outline-secondary btn-mode active" data-mode="qty">By kg</button>
          <button type="button" class="btn btn-outline-secondary btn-mode btn-mode-sack d-none" data-mode="sack">By sack</button>
          <button type="button" class="btn btn-outline-secondary btn-mode" data-mode="amount">By ₱ amount</button>
        </div>
      </td>
      <td>
        <select class="form-select lot-select" name="stock_lot_id[]" required>
          <option value="">Select batch</option>
        </select>
        <div class="form-text lot-hint">Priced batch (buy cost)</div>
      </td>
      <td>
        <input type="number" class="form-control price-input" name="price[]" step="0.01" min="0" value="0" required>
        <div class="form-text price-hint">per kg</div>
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
      const productFilter = row.querySelector('.product-filter');
      const lotSelect = row.querySelector('.lot-select');
      const priceInput = row.querySelector('.price-input');
      const qtyInput = row.querySelector('.qty-input');
      const subtotalInput = row.querySelector('.subtotal-input');
      const subtotalDisplay = row.querySelector('.subtotal-display');
      const lineSubtotalHidden = row.querySelector('.line-subtotal-hidden');
      const qtyHint = row.querySelector('.qty-hint');
      const priceHint = row.querySelector('.price-hint');
      const lotHint = row.querySelector('.lot-hint');
      const sackModeBtn = row.querySelector('.btn-mode-sack');
      let entryMode = 'qty';

      function getSelectedOption() {
        return productSelect.selectedOptions[0] || null;
      }

      function getUnit() {
        const option = getSelectedOption();
        return option && option.dataset.unit ? option.dataset.unit : 'kg';
      }

      function isRice() {
        const option = getSelectedOption();
        return option && option.dataset.productType === 'RICE';
      }

      function getKgPerSack() {
        const option = getSelectedOption();
        const kg = option ? parseFloat(option.dataset.kgPerSack) : 25;
        return kg > 0 ? kg : 25;
      }

      function filterProducts() {
        const q = (productFilter.value || '').trim().toLowerCase();
        productSelect.querySelectorAll('option').forEach(function (opt) {
          if (!opt.value) {
            opt.hidden = false;
            return;
          }
          const name = (opt.dataset.name || opt.textContent || '').toLowerCase();
          opt.hidden = q !== '' && name.indexOf(q) === -1;
        });
        productSelect.querySelectorAll('optgroup').forEach(function (group) {
          const anyVisible = [...group.querySelectorAll('option')].some(function (opt) {
            return !opt.hidden;
          });
          group.hidden = !anyVisible;
        });
      }

      function formatQtyFromAmount(qty) {
        if (getUnit() === 'pc') {
          return String(Math.max(1, Math.round(qty)));
        }
        return (Math.round(qty * 100) / 100).toFixed(2);
      }

      function syncLineSubtotalHidden() {
        if (entryMode === 'amount') {
          const amount = parseFloat(subtotalInput.value);
          lineSubtotalHidden.value = amount > 0 ? amount.toFixed(2) : '';
        } else if (entryMode === 'sack') {
          const sacks = parseFloat(qtyInput.value) || 0;
          const sackPrice = parseFloat(priceInput.value) || 0;
          lineSubtotalHidden.value = sacks > 0 && sackPrice >= 0
            ? (sacks * sackPrice).toFixed(2)
            : '';
        } else {
          lineSubtotalHidden.value = '';
        }
      }

      function updateModeButtons() {
        if (sackModeBtn) {
          sackModeBtn.classList.toggle('d-none', !isRice());
        }
        if (!isRice() && entryMode === 'sack') {
          setEntryMode('qty', true);
        }
      }

      function applyUnitRules() {
        if (entryMode === 'sack') {
          qtyHint.textContent = 'sack';
          priceHint.textContent = 'per sack';
          qtyInput.step = '0.01';
          qtyInput.min = '0.01';
          return;
        }
        if (entryMode === 'qty') {
          qtyHint.textContent = getUnit();
        }
        priceHint.textContent = 'per ' + getUnit();
        if (getUnit() === 'pc') {
          qtyInput.step = '1';
          qtyInput.min = '1';
          if (entryMode === 'qty') {
            qtyInput.value = String(Math.max(1, Math.round(parseFloat(qtyInput.value) || 1)));
          }
        } else {
          qtyInput.step = '0.01';
          qtyInput.min = '0.01';
        }
      }

      function applyLotMax() {
        const lotOption = lotSelect.selectedOptions[0];
        if (lotOption && lotOption.dataset.remaining) {
          const remKg = Math.round(parseFloat(lotOption.dataset.remaining) * 100) / 100;
          if (entryMode === 'sack') {
            const kgPerSack = getKgPerSack();
            const remSacks = Math.round((remKg / kgPerSack) * 100) / 100;
            qtyInput.max = String(remSacks);
            lotHint.textContent = remKg < 1
              ? 'LOW — only ' + remKg.toFixed(2) + ' kg left (~' + remSacks.toFixed(2) + ' sack)'
              : 'Up to ' + remSacks.toFixed(2) + ' sack (' + remKg.toFixed(2) + ' kg) in this batch';
            const currentQty = parseFloat(qtyInput.value) || 0;
            if (currentQty > remSacks + 0.0001) {
              qtyInput.value = remSacks.toFixed(2);
              if (entryMode === 'sack') {
                updateTotalDisplay();
              }
            }
          } else {
            qtyInput.max = String(remKg);
            lotHint.textContent = remKg < 1
              ? 'LOW — only ' + remKg.toFixed(2) + ' ' + getUnit() + ' left in this batch'
              : 'Up to ' + remKg.toFixed(2) + ' ' + getUnit() + ' in this batch';
            const currentQty = parseFloat(qtyInput.value) || 0;
            if (entryMode !== 'amount' && currentQty > remKg + 0.0001) {
              qtyInput.value = remKg.toFixed(2);
              if (entryMode === 'qty') {
                updateTotalDisplay();
              }
            }
          }
        } else {
          qtyInput.removeAttribute('max');
          lotHint.textContent = 'Priced batch (buy cost)';
        }
      }

      function populateLots(preferredLotId) {
        const productId = productSelect.value;
        const lots = lotsByProduct[productId] || [];
        const previous = preferredLotId || lotSelect.value;
        lotSelect.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = lots.length ? 'Select batch' : 'No batch available';
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

      function applyDefaultPrice(forMode) {
        const option = getSelectedOption();
        if (!option) {
          return;
        }
        const mode = forMode || entryMode;
        if (mode === 'sack' && option.dataset.sellingPriceSack) {
          priceInput.value = option.dataset.sellingPriceSack;
        } else if (option.dataset.sellingPrice) {
          priceInput.value = option.dataset.sellingPrice;
        }
      }

      function setEntryMode(mode, skipConvert) {
        const prevMode = entryMode;
        const kgPerSack = getKgPerSack();

        if (!skipConvert) {
          if (mode === 'sack' && prevMode === 'qty' && isRice()) {
            const kg = parseFloat(qtyInput.value) || 0;
            if (kg > 0 && kgPerSack > 0) {
              qtyInput.value = (Math.round((kg / kgPerSack) * 100) / 100).toFixed(2);
            }
            applyDefaultPrice('sack');
          } else if (mode === 'qty' && prevMode === 'sack') {
            const sacks = parseFloat(qtyInput.value) || 0;
            if (sacks > 0 && kgPerSack > 0) {
              qtyInput.value = (Math.round(sacks * kgPerSack * 100) / 100).toFixed(2);
            }
            applyDefaultPrice('qty');
          } else if (mode === 'amount' && (prevMode === 'qty' || prevMode === 'sack')) {
            if (prevMode === 'sack') {
              const option = getSelectedOption();
              if (option && option.dataset.sellingPrice) {
                priceInput.value = option.dataset.sellingPrice;
              }
              const sacks = parseFloat(qtyInput.value) || 0;
              if (sacks > 0 && kgPerSack > 0) {
                qtyInput.value = (Math.round(sacks * kgPerSack * 100) / 100).toFixed(2);
              }
            }
            const qty = parseFloat(qtyInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            if (qty > 0 && price > 0) {
              subtotalInput.value = (qty * price).toFixed(2);
            }
          } else if ((mode === 'qty' || mode === 'sack') && prevMode === 'amount') {
            applyDefaultPrice(mode);
          }
        }

        if (mode === 'sack' && !isRice()) {
          mode = 'qty';
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
        applyUnitRules();
        applyLotMax();

        if (isAmount) {
          qtyHint.textContent = 'auto';
          priceHint.textContent = 'per ' + getUnit();
          calcQtyFromAmount();
          subtotalInput.focus();
        } else {
          updateTotalDisplay();
        }
      }

      productFilter.addEventListener('input', filterProducts);

      productSelect.addEventListener('change', function () {
        updateModeButtons();
        applyDefaultPrice();
        populateLots();
        applyUnitRules();
        refreshLine();
      });

      lotSelect.addEventListener('change', function () {
        applyLotMax();
      });

      qtyInput.addEventListener('input', function () {
        if (entryMode === 'qty' || entryMode === 'sack') {
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
      row._prepareSubmit = function () {
        if (entryMode !== 'sack') {
          return;
        }
        const sacks = parseFloat(qtyInput.value) || 0;
        const sackPrice = parseFloat(priceInput.value) || 0;
        const kgPerSack = getKgPerSack();
        const kg = Math.round(sacks * kgPerSack * 100) / 100;
        const lineTotal = Math.round(sacks * sackPrice * 100) / 100;
        const perKg = kg > 0 ? Math.round((lineTotal / kg) * 100) / 100 : 0;
        qtyInput.value = kg.toFixed(2);
        priceInput.value = perKg.toFixed(2);
        lineSubtotalHidden.value = lineTotal.toFixed(2);
      };

      updateModeButtons();
      setEntryMode('qty', true);
    }

    function addRow() {
      const node = template.content.cloneNode(true);
      const row = node.querySelector('tr');
      tbody.appendChild(row);
      bindRow(row);
      recalcGrandTotal();
    }

    const saleForm = document.querySelector('form');
    if (saleForm) {
      saleForm.addEventListener('submit', function () {
        tbody.querySelectorAll('tr').forEach(function (row) {
          if (typeof row._prepareSubmit === 'function') {
            row._prepareSubmit();
          }
        });
      });
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
