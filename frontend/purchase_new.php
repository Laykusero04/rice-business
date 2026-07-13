<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'New Purchase';
$activePage = 'purchases-new';

$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll();
$products = $pdo->query(
    "SELECT id, name, buying_price, kg_per_sack, stock
     FROM products
     WHERE status = 'active'
     ORDER BY name ASC"
)->fetchAll();

$flash = '';
$flashType = 'danger';

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
    <p class="text-muted mb-0">Buy by sack from supplier. Stock is added in kg automatically.</p>
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
      <div class="col-md-6">
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
      <div class="col-md-3">
        <label for="notes" class="form-label">Notes</label>
        <input type="text" class="form-control" id="notes" name="notes" placeholder="Optional">
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h6 mb-0">Items (by sack)</h2>
      <button type="button" class="btn btn-sm btn-outline-success" id="btnAddRow">
        <i class="bi bi-plus-lg"></i> Add Row
      </button>
    </div>

    <div class="table-responsive mb-3">
      <table class="table align-middle" id="itemsTable">
        <thead class="table-light">
          <tr>
            <th style="min-width: 200px;">Rice / Product</th>
            <th style="min-width: 100px;">Sacks</th>
            <th style="min-width: 130px;">Price / sack</th>
            <th style="min-width: 140px;">Stock in</th>
            <th style="min-width: 120px;" class="text-end">Subtotal</th>
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
      <button type="submit" class="btn btn-rice">Save Purchase</button>
      <a href="purchases.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>

  <template id="itemRowTemplate">
    <tr>
      <td>
        <select class="form-select product-select" name="product_id[]" required>
          <option value="">Select product</option>
          <?php foreach ($products as $product): ?>
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
              data-kg-per-sack="<?= htmlspecialchars(number_format($kgPerSack, 2, '.', '')) ?>"
              data-sack-price="<?= htmlspecialchars(number_format($sackPrice, 2, '.', '')) ?>"
            >
              <?= htmlspecialchars($product['name']) ?>
              (<?= number_format($sacksOnHand, 2) ?> sack · <?= number_format($kgPerSack, 0) ?>kg)
            </option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <input type="number" class="form-control sacks-input" name="sacks[]" step="0.01" min="0.01" value="1" required>
      </td>
      <td>
        <input type="number" class="form-control sack-price-input" name="sack_price[]" step="0.01" min="0" value="0" required>
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

    function formatMoney(value) {
      return '₱' + Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function recalc() {
      let total = 0;
      tbody.querySelectorAll('tr').forEach(function (row) {
        const sacks = parseFloat(row.querySelector('.sacks-input').value) || 0;
        const sackPrice = parseFloat(row.querySelector('.sack-price-input').value) || 0;
        const option = row.querySelector('.product-select').selectedOptions[0];
        const kgPerSack = option && option.dataset.kgPerSack
          ? parseFloat(option.dataset.kgPerSack)
          : 25;
        const kgIn = sacks * kgPerSack;
        const subtotal = sacks * sackPrice;

        row.querySelector('.kg-cell').textContent = kgIn.toFixed(2) + ' kg';
        row.querySelector('.subtotal-cell').textContent = formatMoney(subtotal);
        total += subtotal;
      });
      grandTotalEl.textContent = formatMoney(total);
    }

    function bindRow(row) {
      const productSelect = row.querySelector('.product-select');
      const sackPriceInput = row.querySelector('.sack-price-input');

      productSelect.addEventListener('change', function () {
        const option = productSelect.selectedOptions[0];
        if (option && option.dataset.sackPrice) {
          sackPriceInput.value = option.dataset.sackPrice;
        }
        recalc();
      });

      row.querySelector('.sacks-input').addEventListener('input', recalc);
      sackPriceInput.addEventListener('input', recalc);

      row.querySelector('.btn-remove-row').addEventListener('click', function () {
        if (tbody.querySelectorAll('tr').length === 1) {
          return;
        }
        row.remove();
        recalc();
      });
    }

    function addRow() {
      const node = template.content.cloneNode(true);
      const row = node.querySelector('tr');
      tbody.appendChild(row);
      bindRow(row);
      recalc();
    }

    document.getElementById('btnAddRow').addEventListener('click', addRow);
    addRow();
  });
  </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
