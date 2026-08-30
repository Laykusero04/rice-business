<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;

$purchase = null;
$purchaseItems = [];
$itemProductIds = [];
$formItems = [];
$paymentSource = 'business';

if ($isEdit) {
    $purchaseStmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ? LIMIT 1');
    $purchaseStmt->execute([$id]);
    $purchase = $purchaseStmt->fetch();

    if (!$purchase) {
        header('Location: /rice-business/frontend/purchases.php?error=notfound');
        exit;
    }

    $itemStmt = $pdo->prepare(
        'SELECT pi.*, pr.product_type, pr.unit, pr.kg_per_sack,
                sl.notes AS batch_label, sl.mill_name, sl.weighed_kg
         FROM purchase_items pi
         INNER JOIN products pr ON pr.id = pi.product_id
         LEFT JOIN stock_lots sl ON sl.purchase_item_id = pi.id
         WHERE pi.purchase_id = ?
         ORDER BY pi.id ASC'
    );
    $itemStmt->execute([$id]);
    $purchaseItems = $itemStmt->fetchAll();
    $itemProductIds = array_unique(array_map(static fn ($item) => (int) $item['product_id'], $purchaseItems));
    $paymentSource = $purchase['payment_source'] ?? 'business';

    foreach ($purchaseItems as $item) {
        $productType = $item['product_type'] ?? 'RICE';
        if ($productType === 'RICE') {
            $kgPerSack = (float) ($item['kg_per_sack'] ?? 25);
            if ($kgPerSack <= 0) {
                $kgPerSack = 25;
            }
            $weighed = $item['weighed_kg'] !== null ? (float) $item['weighed_kg'] : null;
            if ($weighed !== null && $weighed > 0) {
                $qtyInput = round($weighed / $kgPerSack, 2);
                $unitPriceInput = $qtyInput > 0
                    ? round((float) $item['subtotal'] / $qtyInput, 2)
                    : 0.0;
            } else {
                $qtyInput = (float) $item['quantity'] / $kgPerSack;
                $unitPriceInput = (float) $item['buying_price'] * $kgPerSack;
            }
        } else {
            $qtyInput = (float) $item['quantity'];
            $unitPriceInput = (float) $item['buying_price'];
            $weighed = null;
        }

        $formItems[] = [
            'product_id' => (int) $item['product_id'],
            'quantity' => round($qtyInput, 2),
            'unit_price' => round($unitPriceInput, 2),
            'batch_label' => (string) ($item['batch_label'] ?? ''),
            'mill_name' => (string) ($item['mill_name'] ?? ''),
            'weighed_kg' => $weighed,
        ];
    }
}

$pageTitle = $isEdit ? 'Edit Purchase' : 'New Purchase';
$activePage = $isEdit ? 'purchases-history' : 'purchases-new';

$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll();

if ($isEdit && count($itemProductIds) > 0) {
    $idsList = implode(',', array_map('intval', $itemProductIds));
    $products = $pdo->query(
        "SELECT id, name, product_type, unit, buying_price, kg_per_sack, stock
         FROM products
         WHERE status = 'active' OR id IN ($idsList)
         ORDER BY (product_type = 'RICE') DESC, name ASC"
    )->fetchAll();
} else {
    $products = $pdo->query(
        "SELECT id, name, product_type, unit, buying_price, kg_per_sack, stock
         FROM products
         WHERE status = 'active'
         ORDER BY (product_type = 'RICE') DESC, name ASC"
    )->fetchAll();
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

$prefillProductId = 0;
if (!$isEdit) {
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
        'stock' => 'Cannot update — not enough stock left for '
            . htmlspecialchars($_GET['product'] ?? 'a product')
            . '. Some of this purchase may already be sold.',
        'lot_used' => 'Cannot edit this purchase — some of its stock batch has already been sold. Delete or adjust sales first.',
        'save' => $isEdit ? 'Could not update the purchase. Please try again.' : 'Could not save the purchase. Please try again.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <?php if ($isEdit): ?>
      <h1 class="h3 mb-1">Edit Purchase #<?= (int) $purchase['id'] ?></h1>
      <p class="text-muted mb-0">
        Changing items reverses the old stock-in, then applies the new quantities.
      </p>
    <?php else: ?>
      <h1 class="h3 mb-1">New Purchase</h1>
      <p class="text-muted mb-0">
        Each line creates a <strong>source batch</strong> (money spent is the truth).
        Rice: buy by sack; optional weigh-in kg if you have a scale.
        Batch label + mill name help when you rename or mix later.
      </p>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <?php if ($isEdit): ?>
      <a href="purchase_view.php?id=<?= (int) $purchase['id'] ?>" class="btn btn-outline-secondary">View</a>
    <?php endif; ?>
    <a href="purchases.php" class="btn btn-outline-secondary">Purchase History</a>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= $flash ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php if (count($suppliers) === 0 || count($products) === 0): ?>
  <div class="alert alert-warning">
    <?php if (count($suppliers) === 0): ?>
      Add at least one <a href="suppliers.php">supplier</a> before <?= $isEdit ? 'editing' : 'creating' ?> a purchase.
    <?php endif; ?>
    <?php if (count($products) === 0): ?>
      Add at least one active <a href="products.php">product</a> before <?= $isEdit ? 'editing' : 'creating' ?> a purchase.
    <?php endif; ?>
  </div>
<?php else: ?>
  <form method="POST" action="/rice-business/backend/purchase_save.php" id="purchaseForm" class="bg-white rounded shadow-sm p-3 p-md-4">
    <?php if ($isEdit): ?>
      <input type="hidden" name="id" value="<?= (int) $purchase['id'] ?>">
    <?php endif; ?>

    <div class="row g-3 mb-4">
      <div class="col-md-5">
        <label for="supplierId" class="form-label">Supplier</label>
        <select class="form-select" id="supplierId" name="supplier_id" required>
          <option value="">Select supplier</option>
          <?php foreach ($suppliers as $supplier): ?>
            <option
              value="<?= (int) $supplier['id'] ?>"
              <?= $isEdit && (int) $purchase['supplier_id'] === (int) $supplier['id'] ? 'selected' : '' ?>
            >
              <?= htmlspecialchars($supplier['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label for="purchaseDate" class="form-label">Date</label>
        <input
          type="date"
          class="form-control"
          id="purchaseDate"
          name="purchase_date"
          value="<?= htmlspecialchars($isEdit ? $purchase['purchase_date'] : date('Y-m-d')) ?>"
          required
        >
      </div>
      <div class="col-md-4">
        <label for="paymentSource" class="form-label">Paid with</label>
        <select class="form-select" id="paymentSource" name="payment_source" required>
          <?php foreach ($paymentSourceLabels as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= $paymentSource === $value ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Personal money is recorded as Owner Investment in Expenses.</div>
      </div>
      <div class="col-12">
        <label for="notes" class="form-label">Notes</label>
        <input
          type="text"
          class="form-control"
          id="notes"
          name="notes"
          value="<?= htmlspecialchars($isEdit ? (string) ($purchase['notes'] ?? '') : '') ?>"
          placeholder="Optional"
        >
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
            <th style="min-width: 200px;">Sell as (product)</th>
            <th style="min-width: 130px;">Batch label</th>
            <th style="min-width: 120px;">Mill name</th>
            <th style="min-width: 110px;">Qty</th>
            <th style="min-width: 130px;">Unit price</th>
            <th style="min-width: 110px;">Weigh-in kg</th>
            <th style="min-width: 120px;">Stock in</th>
            <th style="min-width: 110px;" class="text-end">Subtotal</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
          <tr>
            <td colspan="7" class="text-end fw-semibold">Total</td>
            <td class="text-end fw-bold" id="grandTotal">₱0.00</td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-rice"><?= $isEdit ? 'Update Purchase' : 'Save Purchase' ?></button>
      <?php if ($isEdit): ?>
        <a href="purchase_view.php?id=<?= (int) $purchase['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
      <?php else: ?>
        <a href="purchases.php" class="btn btn-outline-secondary">Cancel</a>
      <?php endif; ?>
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
        <input
          type="text"
          class="form-control mill-name-input"
          name="mill_name[]"
          maxlength="255"
          placeholder="Supplier name"
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
      <td>
        <input
          type="number"
          class="form-control weighed-kg-input"
          name="weighed_kg[]"
          step="0.01"
          min="0.01"
          placeholder="Optional"
        >
        <div class="form-text weighed-hint">scale kg</div>
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

    const existingItems = <?= json_encode($formItems, JSON_UNESCAPED_UNICODE) ?>;

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
        const weighedInput = row.querySelector('.weighed-kg-input');
        const weighed = weighedInput && weighedInput.value !== '' ? parseFloat(weighedInput.value) : NaN;
        const option = row.querySelector('.product-select').selectedOptions[0];
        const productType = option && option.dataset.productType ? option.dataset.productType : 'RICE';
        const unit = option && option.dataset.unit ? option.dataset.unit : 'kg';
        const kgPerSack = option && option.dataset.kgPerSack ? parseFloat(option.dataset.kgPerSack) : 25;
        let stockInText;
        if (productType === 'RICE') {
          const estKg = qty * kgPerSack;
          stockInText = (!isNaN(weighed) && weighed > 0)
            ? weighed.toFixed(2) + ' kg (weighed)'
            : estKg.toFixed(2) + ' kg (est.)';
          if (weighedInput) {
            weighedInput.disabled = false;
          }
        } else {
          stockInText = (unit === 'pc' ? String(Math.round(qty)) : qty.toFixed(2)) + ' ' + unit;
          if (weighedInput) {
            weighedInput.value = '';
            weighedInput.disabled = true;
          }
        }
        const subtotal = qty * unitPrice;

        row.querySelector('.kg-cell').textContent = stockInText;
        row.querySelector('.subtotal-cell').textContent = formatMoney(subtotal);
        total += subtotal;
      });
      grandTotalEl.textContent = formatMoney(total);
    }

    function applyProductUi(row, preserveValues) {
      const productSelect = row.querySelector('.product-select');
      const unitPriceInput = row.querySelector('.unit-price-input');
      const qtyInput = row.querySelector('.qty-input');
      const qtyHint = row.querySelector('.qty-hint');
      const priceHint = row.querySelector('.price-hint');
      const option = productSelect.selectedOptions[0];
      const productType = option && option.dataset.productType ? option.dataset.productType : 'RICE';
      const unit = option && option.dataset.unit ? option.dataset.unit : 'kg';

      if (!preserveValues && option && option.dataset.unitPrice) {
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
          if (!preserveValues) {
            qtyInput.value = String(Math.max(1, Math.round(parseFloat(qtyInput.value) || 1)));
          }
        } else {
          qtyInput.step = '0.01';
          qtyInput.min = '0.01';
        }
      }
    }

    function bindRow(row) {
      const productSelect = row.querySelector('.product-select');

      productSelect.addEventListener('change', function () {
        applyProductUi(row, false);
        recalc();
      });

      row.querySelector('.qty-input').addEventListener('input', recalc);
      row.querySelector('.unit-price-input').addEventListener('input', recalc);
      const weighedInput = row.querySelector('.weighed-kg-input');
      if (weighedInput) {
        weighedInput.addEventListener('input', recalc);
      }

      row.querySelector('.btn-remove-row').addEventListener('click', function () {
        if (tbody.querySelectorAll('tr').length === 1) {
          return;
        }
        row.remove();
        recalc();
      });
    }

    function addRow(item) {
      const node = template.content.cloneNode(true);
      const row = node.querySelector('tr');
      tbody.appendChild(row);
      bindRow(row);

      if (item) {
        row.querySelector('.product-select').value = String(item.product_id);
        applyProductUi(row, item.quantity != null);
        if (item.quantity != null) {
          row.querySelector('.qty-input').value = item.quantity;
          row.querySelector('.unit-price-input').value = item.unit_price;
          row.querySelector('.batch-label-input').value = item.batch_label || '';
          row.querySelector('.mill-name-input').value = item.mill_name || '';
          if (item.weighed_kg) {
            row.querySelector('.weighed-kg-input').value = item.weighed_kg;
          }
        }
      } else {
        applyProductUi(row, false);
      }

      recalc();
    }

    document.getElementById('btnAddRow').addEventListener('click', function () {
      addRow(null);
    });

    if (existingItems.length > 0) {
      existingItems.forEach(function (item) {
        addRow(item);
      });
    } else if (prefillProductId > 0) {
      addRow({ product_id: prefillProductId });
    } else {
      addRow(null);
    }
  });
  </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
