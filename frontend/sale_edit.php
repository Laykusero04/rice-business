<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/stock_lots.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Edit Sale';
$activePage = 'sales-history';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    header('Location: /rice-business/frontend/sales.php');
    exit;
}

$saleStmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? LIMIT 1');
$saleStmt->execute([$id]);
$sale = $saleStmt->fetch();

if (!$sale) {
    header('Location: /rice-business/frontend/sales.php?error=notfound');
    exit;
}

$itemStmt = $pdo->prepare(
    'SELECT product_id, stock_lot_id, quantity, price FROM sale_items WHERE sale_id = ? ORDER BY id ASC'
);
$itemStmt->execute([$id]);
$saleItems = $itemStmt->fetchAll();

$reservedByProduct = [];
foreach ($saleItems as $item) {
    $pid = (int) $item['product_id'];
    $reservedByProduct[$pid] = ($reservedByProduct[$pid] ?? 0) + (float) $item['quantity'];
}

$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name ASC')->fetchAll();
$productsRaw = $pdo->query(
    "SELECT id, name, product_type, unit, selling_price, selling_price_sack, kg_per_sack, stock
     FROM products
     WHERE status = 'active'
     ORDER BY (product_type = 'RICE') DESC, name ASC"
)->fetchAll();

$products = [];
foreach ($productsRaw as $product) {
    $pid = (int) $product['id'];
    $available = (float) $product['stock'] + ($reservedByProduct[$pid] ?? 0);
    $product['available_stock'] = $available;
    $products[] = $product;
}

$lotsByProduct = fetchOpenLotsByProduct($pdo, $id);
$lotsForJs = [];
$saleLotIds = array_unique(array_filter(array_map(
    static fn ($item) => (int) ($item['stock_lot_id'] ?? 0),
    $saleItems
)));
foreach ($lotsByProduct as $pid => $lots) {
    $sellable = [];
    foreach ($lots as $lot) {
        $remaining = round((float) $lot['quantity_remaining'], 2);
        $lotId = (int) $lot['id'];
        // Keep crumbs if already on this sale; otherwise hide unsalable
        if (isLotUnsalable($remaining) && !in_array($lotId, $saleLotIds, true)) {
            continue;
        }
        $sellable[] = [
            'id' => $lotId,
            'label' => formatLotLabel($lot),
            'remaining' => $remaining,
            'low' => isLotLow($remaining),
        ];
    }
    if (count($sellable) > 0) {
        $lotsForJs[(string) $pid] = $sellable;
    }
}

$riceProducts = [];
$otherProducts = [];
$saleProductIds = array_unique(array_map(static fn ($item) => (int) $item['product_id'], $saleItems));
foreach ($products as $p) {
    $pid = (int) $p['id'];
    $hasLots = isset($lotsForJs[(string) $pid]) && count($lotsForJs[(string) $pid]) > 0;
    $onSale = in_array($pid, $saleProductIds, true);
    if (!$hasLots && !$onSale) {
        continue;
    }
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
        'items' => 'Add at least one valid product line and choose a stock batch.',
        'stock' => 'Not enough stock for '
            . htmlspecialchars($_GET['product'] ?? 'selected product')
            . '.',
        'lot' => 'Choose a valid stock batch for each item.',
        'save' => 'Could not update the sale. Please try again.',
        default => 'Something went wrong.',
    };
}

$existingNotes = (string) ($sale['notes'] ?? '');
$editableNotes = [];
foreach (preg_split("/\r\n|\n|\r/", $existingNotes) as $line) {
    $line = trim($line);
    if ($line !== '' && !str_starts_with($line, 'Collected ₱')) {
        $editableNotes[] = $line;
    }
}
$notesValue = implode("\n", $editableNotes);

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Edit Sale #<?= (int) $sale['id'] ?></h1>
    <p class="text-muted mb-0">
      Changing items restores then re-deducts stock. Collection notes for utang are kept.
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="sale_view.php?id=<?= (int) $sale['id'] ?>" class="btn btn-outline-secondary">View</a>
    <a href="sales.php" class="btn btn-outline-secondary">Sales History</a>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= $flash ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php if (count($products) === 0): ?>
  <div class="alert alert-warning">
    Add at least one active <a href="products.php">product</a> before editing a sale.
  </div>
<?php else: ?>
  <form method="POST" action="/rice-business/backend/sale_save.php" id="saleForm" class="bg-white rounded shadow-sm p-3 p-md-4">
    <input type="hidden" name="id" value="<?= (int) $sale['id'] ?>">

    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label for="customerId" class="form-label">Customer</label>
        <select class="form-select" id="customerId" name="customer_id">
          <option value="">Walk-in / Just buying</option>
          <?php foreach ($customers as $customer): ?>
            <option
              value="<?= (int) $customer['id'] ?>"
              <?= (int) ($sale['customer_id'] ?? 0) === (int) $customer['id'] ? 'selected' : '' ?>
            >
              <?= htmlspecialchars($customer['name']) ?>
            </option>
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
          <option value="cash" <?= $sale['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash (paid)</option>
          <option value="gcash" <?= $sale['payment_method'] === 'gcash' ? 'selected' : '' ?>>GCash (paid)</option>
          <option value="bank" <?= $sale['payment_method'] === 'bank' ? 'selected' : '' ?>>Bank Transfer (paid)</option>
          <option value="credit" <?= $sale['payment_method'] === 'credit' ? 'selected' : '' ?>>Lend (Utang)</option>
        </select>
      </div>
      <div class="col-md-4">
        <label for="saleDate" class="form-label">Date</label>
        <input
          type="date"
          class="form-control"
          id="saleDate"
          name="sale_date"
          value="<?= htmlspecialchars($sale['sale_date']) ?>"
          required
        >
      </div>
      <div class="col-md-8">
        <label for="notes" class="form-label">Notes</label>
        <input
          type="text"
          class="form-control"
          id="notes"
          name="notes"
          value="<?= htmlspecialchars($notesValue) ?>"
          placeholder="Optional"
        >
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
      <button type="submit" class="btn btn-rice">Update Sale</button>
      <a href="sale_view.php?id=<?= (int) $sale['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
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
                  data-stock="<?= htmlspecialchars(number_format($product['available_stock'], 2, '.', '')) ?>"
                  data-unit="kg"
                  data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                >
                  <?= htmlspecialchars($product['name']) ?>
                  (<?= number_format((float) $product['available_stock'], 2) ?> kg)
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
                  data-stock="<?= htmlspecialchars(number_format($product['available_stock'], 2, '.', '')) ?>"
                  data-unit="<?= htmlspecialchars($unit) ?>"
                  data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                >
                  <?= htmlspecialchars($product['name']) ?>
                  (<?= number_format((float) $product['available_stock'], $unit === 'pc' ? 0 : 2) ?> <?= htmlspecialchars($unit) ?>)
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

    const existingItems = <?= json_encode(array_map(static function ($item) {
        return [
            'product_id' => (int) $item['product_id'],
            'stock_lot_id' => (int) ($item['stock_lot_id'] ?? 0),
            'quantity' => (float) $item['quantity'],
            'price' => (float) $item['price'],
        ];
    }, $saleItems), JSON_UNESCAPED_UNICODE) ?>;

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
              updateTotalDisplay();
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

      lotSelect.addEventListener('change', applyLotMax);

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

      row._refreshLine = refreshLine;
      row._populateLots = populateLots;
      row._updateModeButtons = updateModeButtons;
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

    function addRow(preset) {
      const node = template.content.cloneNode(true);
      const row = node.querySelector('tr');
      tbody.appendChild(row);
      bindRow(row);

      if (preset) {
        const productSelect = row.querySelector('.product-select');
        const qtyInput = row.querySelector('.qty-input');
        const priceInput = row.querySelector('.price-input');
        const qtyHint = row.querySelector('.qty-hint');
        const priceHint = row.querySelector('.price-hint');

        productSelect.value = String(preset.product_id);
        qtyInput.value = preset.quantity;
        priceInput.value = preset.price;

        if (typeof row._updateModeButtons === 'function') {
          row._updateModeButtons();
        }

        if (typeof row._populateLots === 'function') {
          row._populateLots(preset.stock_lot_id || null);
        }

        const option = productSelect.selectedOptions[0];
        const unit = option && option.dataset.unit ? option.dataset.unit : 'kg';
        qtyHint.textContent = unit;
        priceHint.textContent = 'per ' + unit;
        if (unit === 'pc') {
          qtyInput.step = '1';
          qtyInput.min = '1';
        } else {
          qtyInput.step = '0.01';
          qtyInput.min = '0.01';
        }

        if (typeof row._refreshLine === 'function') {
          row._refreshLine();
        }
      }

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
    document.getElementById('btnAddRow').addEventListener('click', function () {
      addRow(null);
    });
    updateLendUi();

    if (existingItems.length > 0) {
      existingItems.forEach(function (item) {
        addRow(item);
      });
    } else {
      addRow(null);
    }
  });
  </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
