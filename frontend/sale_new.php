<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'New Sale';
$activePage = 'sales-new';

$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name ASC')->fetchAll();
$products = $pdo->query(
    "SELECT id, name, selling_price, stock
     FROM products
     WHERE status = 'active'
     ORDER BY name ASC"
)->fetchAll();

$flash = '';
$flashType = 'danger';

if (isset($_GET['error'])) {
    $flash = match ($_GET['error']) {
        'required' => 'Sale date is required.',
        'customer' => 'Enter the borrower name for walk-in utang, or select a customer.',
        'items' => 'Add at least one valid product line.',
        'stock' => 'Not enough stock for '
            . htmlspecialchars($_GET['product'] ?? 'selected product')
            . '.',
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
            <th style="min-width: 220px;">Rice / Product</th>
            <th style="min-width: 110px;">Qty (kg)</th>
            <th style="min-width: 130px;">Price</th>
            <th style="min-width: 120px;" class="text-end">Subtotal</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
          <tr>
            <td colspan="3" class="text-end fw-semibold">Total</td>
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
          <?php foreach ($products as $product): ?>
            <option
              value="<?= (int) $product['id'] ?>"
              data-selling-price="<?= htmlspecialchars($product['selling_price']) ?>"
              data-stock="<?= htmlspecialchars($product['stock']) ?>"
            >
              <?= htmlspecialchars($product['name']) ?>
              (stock: <?= number_format((float) $product['stock'], 2) ?> kg)
            </option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <input type="number" class="form-control qty-input" name="quantity[]" step="0.01" min="0.01" value="1" required>
      </td>
      <td>
        <input type="number" class="form-control price-input" name="price[]" step="0.01" min="0" value="0" required>
      </td>
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
    const paymentMethod = document.getElementById('paymentMethod');
    const customerId = document.getElementById('customerId');
    const lendNotice = document.getElementById('lendNotice');
    const walkinNameWrap = document.getElementById('walkinNameWrap');
    const walkinName = document.getElementById('walkinName');
    const customerHint = document.getElementById('customerHint');

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

    function recalc() {
      let total = 0;
      tbody.querySelectorAll('tr').forEach(function (row) {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        const subtotal = qty * price;
        row.querySelector('.subtotal-cell').textContent = formatMoney(subtotal);
        total += subtotal;
      });
      grandTotalEl.textContent = formatMoney(total);
    }

    function bindRow(row) {
      const productSelect = row.querySelector('.product-select');
      const priceInput = row.querySelector('.price-input');
      const qtyInput = row.querySelector('.qty-input');

      productSelect.addEventListener('change', function () {
        const option = productSelect.selectedOptions[0];
        if (option && option.dataset.sellingPrice) {
          priceInput.value = option.dataset.sellingPrice;
        }
        if (option && option.dataset.stock) {
          qtyInput.max = option.dataset.stock;
        }
        recalc();
      });

      qtyInput.addEventListener('input', recalc);
      priceInput.addEventListener('input', recalc);

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

    paymentMethod.addEventListener('change', updateLendUi);
    customerId.addEventListener('change', updateLendUi);
    document.getElementById('btnAddRow').addEventListener('click', addRow);
    updateLendUi();
    addRow();
  });
  </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
