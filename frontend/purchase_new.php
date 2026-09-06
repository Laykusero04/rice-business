<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/stock_lots.php';
requireLogin();

$user = currentUser();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;

$purchase = null;
$purchaseItems = [];
$formItems = [];
$paymentSource = 'business';
$purchaseBatch = '';
$forProductId = 0;

ensurePurchaseForProductColumn($pdo);
$nextBatchPreview = peekNextBatchLabel($pdo);

if ($isEdit) {
    $purchaseStmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ? LIMIT 1');
    $purchaseStmt->execute([$id]);
    $purchase = $purchaseStmt->fetch();

    if (!$purchase) {
        header('Location: /rice-business/frontend/purchases.php?error=notfound');
        exit;
    }

    $itemStmt = $pdo->prepare(
        'SELECT pi.*,
                COALESCE(pi.item_name, pr.name, \'\') AS product_name
         FROM purchase_items pi
         LEFT JOIN products pr ON pr.id = pi.product_id
         WHERE pi.purchase_id = ?
         ORDER BY pi.id ASC'
    );
    $itemStmt->execute([$id]);
    $purchaseItems = $itemStmt->fetchAll();
    $paymentSource = $purchase['payment_source'] ?? 'business';
    $purchaseBatch = trim((string) ($purchase['batch_label'] ?? ''));
    $forProductId = (int) ($purchase['for_product_id'] ?? 0);

    foreach ($purchaseItems as $item) {
        $kgPerSack = (float) ($item['kg_per_sack'] ?? 25);
        if ($kgPerSack <= 0) {
            $kgPerSack = 25;
        }

        $qtyInput = (float) $item['quantity'] / $kgPerSack;
        $unitPriceInput = (float) $item['buying_price'] * $kgPerSack;

        $formItems[] = [
            'product_name' => (string) ($item['product_name'] ?? ''),
            'quantity' => round($qtyInput, 2),
            'unit_price' => round($unitPriceInput, 2),
            'kg_per_sack' => round($kgPerSack, 2),
        ];
    }
} else {
    $purchaseBatch = $nextBatchPreview;
}

$pageTitle = $isEdit ? 'Edit Purchase' : 'New Purchase';
$activePage = $isEdit ? 'purchases-history' : 'purchases-new';

$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll();

$sellProducts = $pdo->query(
    "SELECT id, name FROM products
     WHERE status = 'active' AND product_type = 'RICE'
     ORDER BY name ASC"
)->fetchAll();

$productNameSuggestions = [];
try {
    $productNameSuggestions = $pdo->query(
        'SELECT name FROM rice_names ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $productNameSuggestions = $pdo->query(
        "SELECT name FROM products
         WHERE status = 'active' AND product_type = 'RICE'
         ORDER BY name ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
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
        'items' => 'Add at least one valid product line (name, sacks, and price).',
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
        Lines named like an active product (e.g. RC 160) update that product’s sell stock.
        Other names stay cost-only until you mix / link.
      </p>
    <?php else: ?>
      <h1 class="h3 mb-1">New Purchase</h1>
      <p class="text-muted mb-0">
        Give this buy a <strong>batch name</strong>. If an item name matches a product, sell stock is added automatically.
        Use <em>For sell product (mix)</em> when buying raw rice that will be mixed later.
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

<?php if (count($suppliers) === 0): ?>
  <div class="alert alert-warning">
    Add at least one <a href="suppliers.php">supplier</a> before <?= $isEdit ? 'editing' : 'creating' ?> a purchase.
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
        <label for="purchaseBatch" class="form-label">Batch name</label>
        <input
          type="text"
          class="form-control"
          id="purchaseBatch"
          name="purchase_batch"
          required
          maxlength="255"
          value="<?= htmlspecialchars($purchaseBatch) ?>"
          placeholder="e.g. Batch-1"
        >
        <div class="form-text">
          This purchase belongs to this batch. Matching products get the same batch on sell stock.
        </div>
      </div>
      <div class="col-md-4">
        <label for="forProductId" class="form-label">For sell product (mix)</label>
        <select class="form-select" id="forProductId" name="for_product_id">
          <option value="">Optional — pick later</option>
          <?php foreach ($sellProducts as $sp): ?>
            <option value="<?= (int) $sp['id'] ?>" <?= $forProductId === (int) $sp['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($sp['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">
          Which mix this buy will become after mixing (for profit tracking).
          <?php if (count($sellProducts) === 0): ?>
            <a href="products.php">Add a rice product</a> first if needed.
          <?php endif; ?>
        </div>
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

    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <h2 class="h6 mb-0">Items</h2>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addRiceNameModal">
          <i class="bi bi-plus-circle"></i> Add rice name
        </button>
        <button type="button" class="btn btn-sm btn-outline-success" id="btnAddRow">
          <i class="bi bi-plus-lg"></i> Add Row
        </button>
      </div>
    </div>

    <div class="table-responsive mb-3">
      <table class="table align-middle" id="itemsTable">
        <thead class="table-light">
          <tr>
            <th style="min-width: 180px;">Product name</th>
            <th style="min-width: 90px;">Sacks</th>
            <th style="min-width: 100px;">Kg / sack</th>
            <th style="min-width: 120px;">Price / sack</th>
            <th style="min-width: 100px;">Stock in</th>
            <th style="min-width: 110px;" class="text-end">Subtotal</th>
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
      <button type="submit" class="btn btn-rice"><?= $isEdit ? 'Update Purchase' : 'Save Purchase' ?></button>
      <?php if ($isEdit): ?>
        <a href="purchase_view.php?id=<?= (int) $purchase['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
      <?php else: ?>
        <a href="purchases.php" class="btn btn-outline-secondary">Cancel</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="modal fade" id="addRiceNameModal" tabindex="-1" aria-labelledby="addRiceNameModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title h5" id="addRiceNameModalLabel">Add rice name</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-0">Save a rice name for the purchase dropdown. This does not create a sellable product or stock.</p>
          <div class="mb-3">
            <label for="newRiceName" class="form-label">Rice name</label>
            <input type="text" class="form-control" id="newRiceName" maxlength="100" placeholder="e.g. M1, RC160" autocomplete="off">
          </div>
          <div class="mb-0">
            <label for="newRiceKg" class="form-label">Kg per sack</label>
            <input type="number" class="form-control" id="newRiceKg" step="0.01" min="0.01" value="25">
          </div>
          <div class="alert alert-danger d-none mt-3 mb-0" id="addRiceNameError" role="alert"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-rice" id="btnSaveRiceName">Save name</button>
        </div>
      </div>
    </div>
  </div>

  <template id="itemRowTemplate">
    <tr>
      <td>
        <div class="rice-combo">
          <div class="input-group">
            <input
              type="text"
              class="form-control product-name-input"
              name="product_name[]"
              maxlength="100"
              placeholder="Type or pick rice name"
              required
              autocomplete="off"
              role="combobox"
              aria-autocomplete="list"
              aria-expanded="false"
            >
            <button type="button" class="btn btn-outline-secondary rice-combo-toggle" tabindex="-1" title="Show rice names" aria-label="Show rice names">
              <i class="bi bi-chevron-down"></i>
            </button>
          </div>
          <div class="rice-combo-menu" role="listbox"></div>
        </div>
      </td>
      <td>
        <input type="number" class="form-control qty-input" name="quantity[]" step="0.01" min="0.01" value="1" required>
      </td>
      <td>
        <input type="number" class="form-control kg-per-sack-input" name="kg_per_sack[]" step="0.01" min="0.01" value="25" required>
      </td>
      <td>
        <input type="number" class="form-control unit-price-input" name="unit_price[]" step="0.01" min="0" value="0" required>
        <div class="form-text">₱ / sack</div>
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
    const existingItems = <?= json_encode($formItems, JSON_UNESCAPED_UNICODE) ?>;
    let riceNames = <?= json_encode(array_values($productNameSuggestions), JSON_UNESCAPED_UNICODE) ?>;

    function formatMoney(value) {
      return '₱' + Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function normalizeName(value) {
      return String(value || '').trim().toLowerCase();
    }

    function scoreRiceName(name, query) {
      const n = normalizeName(name);
      const q = normalizeName(query);
      if (q === '') {
        return 1;
      }
      if (n === q) {
        return 1000;
      }
      if (n.startsWith(q)) {
        return 800 - Math.min(n.length, 100);
      }
      const idx = n.indexOf(q);
      if (idx >= 0) {
        return 600 - idx;
      }
      // Close match: query characters appear in order (handles M1 / RC typos loosely)
      let ni = 0;
      for (let qi = 0; qi < q.length; qi++) {
        ni = n.indexOf(q[qi], ni);
        if (ni === -1) {
          return 0;
        }
        ni += 1;
      }
      return 200 - Math.min(n.length, 100);
    }

    function filterRiceNames(query, limit) {
      const scored = riceNames
        .map(function (name) {
          return { name: name, score: scoreRiceName(name, query) };
        })
        .filter(function (row) {
          return row.score > 0;
        })
        .sort(function (a, b) {
          if (b.score !== a.score) {
            return b.score - a.score;
          }
          return a.name.localeCompare(b.name);
        });
      return scored.slice(0, limit || 12).map(function (row) {
        return row.name;
      });
    }

    function addRiceNameToList(name) {
      const trimmed = String(name || '').trim();
      if (trimmed === '') {
        return;
      }
      const exists = riceNames.some(function (n) {
        return normalizeName(n) === normalizeName(trimmed);
      });
      if (!exists) {
        riceNames.push(trimmed);
        riceNames.sort(function (a, b) {
          return a.localeCompare(b);
        });
      }
    }

    function closeAllCombos(exceptCombo) {
      tbody.querySelectorAll('.rice-combo').forEach(function (combo) {
        if (exceptCombo && combo === exceptCombo) {
          return;
        }
        const menu = combo.querySelector('.rice-combo-menu');
        const input = combo.querySelector('.product-name-input');
        menu.classList.remove('is-open');
        menu.innerHTML = '';
        if (input) {
          input.setAttribute('aria-expanded', 'false');
        }
      });
    }

    function openComboMenu(combo, query, forceAll) {
      const input = combo.querySelector('.product-name-input');
      const menu = combo.querySelector('.rice-combo-menu');
      const q = forceAll ? '' : (query != null ? query : input.value);
      const matches = filterRiceNames(q, forceAll ? 50 : 12);

      menu.innerHTML = '';
      if (matches.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'rice-combo-empty';
        empty.textContent = q
          ? 'No close match. Keep typing a new name, or use Add rice name.'
          : 'No rice names yet. Use Add rice name.';
        menu.appendChild(empty);
      } else {
        matches.forEach(function (name, index) {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'rice-combo-item' + (index === 0 ? ' is-active' : '');
          btn.setAttribute('role', 'option');
          btn.textContent = name;
          btn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            input.value = name;
            closeAllCombos();
            input.dispatchEvent(new Event('change', { bubbles: true }));
          });
          menu.appendChild(btn);
        });
      }

      closeAllCombos(combo);
      menu.classList.add('is-open');
      input.setAttribute('aria-expanded', 'true');
    }

    function moveComboActive(combo, delta) {
      const items = Array.from(combo.querySelectorAll('.rice-combo-item'));
      if (items.length === 0) {
        return;
      }
      let idx = items.findIndex(function (el) {
        return el.classList.contains('is-active');
      });
      if (idx < 0) {
        idx = 0;
      } else {
        items[idx].classList.remove('is-active');
        idx = (idx + delta + items.length) % items.length;
      }
      items[idx].classList.add('is-active');
      items[idx].scrollIntoView({ block: 'nearest' });
    }

    function selectActiveComboItem(combo) {
      const active = combo.querySelector('.rice-combo-item.is-active');
      const input = combo.querySelector('.product-name-input');
      if (active) {
        input.value = active.textContent;
        closeAllCombos();
        input.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      }
      return false;
    }

    function bindRiceCombo(row) {
      const combo = row.querySelector('.rice-combo');
      const input = combo.querySelector('.product-name-input');
      const toggle = combo.querySelector('.rice-combo-toggle');

      input.addEventListener('input', function () {
        openComboMenu(combo, input.value, false);
      });

      input.addEventListener('focus', function () {
        openComboMenu(combo, input.value, input.value.trim() === '');
      });

      input.addEventListener('keydown', function (e) {
        const open = combo.querySelector('.rice-combo-menu').classList.contains('is-open');
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          if (!open) {
            openComboMenu(combo, input.value, input.value.trim() === '');
          } else {
            moveComboActive(combo, 1);
          }
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (open) {
            moveComboActive(combo, -1);
          }
        } else if (e.key === 'Enter') {
          if (open && selectActiveComboItem(combo)) {
            e.preventDefault();
          }
        } else if (e.key === 'Escape') {
          closeAllCombos();
        }
      });

      toggle.addEventListener('click', function () {
        const menu = combo.querySelector('.rice-combo-menu');
        if (menu.classList.contains('is-open')) {
          closeAllCombos();
        } else {
          input.focus();
          openComboMenu(combo, '', true);
        }
      });
    }

    document.addEventListener('click', function (e) {
      if (!e.target.closest('.rice-combo')) {
        closeAllCombos();
      }
    });

    function recalc() {
      let total = 0;
      tbody.querySelectorAll('tr').forEach(function (row) {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const unitPrice = parseFloat(row.querySelector('.unit-price-input').value) || 0;
        const kgPerSack = parseFloat(row.querySelector('.kg-per-sack-input').value) || 25;
        const estKg = qty * kgPerSack;
        const subtotal = qty * unitPrice;

        row.querySelector('.kg-cell').textContent = estKg.toFixed(2) + ' kg';
        row.querySelector('.subtotal-cell').textContent = formatMoney(subtotal);
        total += subtotal;
      });
      grandTotalEl.textContent = formatMoney(total);
    }

    function bindRow(row) {
      bindRiceCombo(row);
      row.querySelector('.qty-input').addEventListener('input', recalc);
      row.querySelector('.unit-price-input').addEventListener('input', recalc);
      row.querySelector('.kg-per-sack-input').addEventListener('input', recalc);

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
        row.querySelector('.product-name-input').value = item.product_name || '';
        if (item.quantity != null) {
          row.querySelector('.qty-input').value = item.quantity;
        }
        if (item.unit_price != null) {
          row.querySelector('.unit-price-input').value = item.unit_price;
        }
        if (item.kg_per_sack != null) {
          row.querySelector('.kg-per-sack-input').value = item.kg_per_sack;
        }
      }

      recalc();
    }

    document.getElementById('btnAddRow').addEventListener('click', function () {
      addRow(null);
    });

    const addRiceModalEl = document.getElementById('addRiceNameModal');
    const addRiceError = document.getElementById('addRiceNameError');
    const newRiceNameInput = document.getElementById('newRiceName');
    const newRiceKgInput = document.getElementById('newRiceKg');

    document.getElementById('btnSaveRiceName').addEventListener('click', function () {
      const name = newRiceNameInput.value.trim();
      const kg = parseFloat(newRiceKgInput.value) || 25;
      addRiceError.classList.add('d-none');

      if (name === '') {
        addRiceError.textContent = 'Enter a rice name.';
        addRiceError.classList.remove('d-none');
        return;
      }

      const body = new URLSearchParams();
      body.set('name', name);
      body.set('kg_per_sack', String(kg));

      fetch('/rice-business/backend/rice_name_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function (res) {
          return res.json().then(function (data) {
            return { ok: res.ok, data: data };
          });
        })
        .then(function (result) {
          if (!result.ok || !result.data.ok) {
            addRiceError.textContent = 'Could not save the rice name. Try again.';
            addRiceError.classList.remove('d-none');
            return;
          }
          const savedName = result.data.name || name;
          addRiceNameToList(savedName);

          const firstEmpty = Array.from(tbody.querySelectorAll('.product-name-input')).find(function (el) {
            return el.value.trim() === '';
          });
          if (firstEmpty) {
            firstEmpty.value = savedName;
          }

          const modal = bootstrap.Modal.getOrCreateInstance(addRiceModalEl);
          modal.hide();
          newRiceNameInput.value = '';
          newRiceKgInput.value = '25';
        })
        .catch(function () {
          addRiceError.textContent = 'Could not save the rice name. Try again.';
          addRiceError.classList.remove('d-none');
        });
    });

    addRiceModalEl.addEventListener('shown.bs.modal', function () {
      newRiceNameInput.focus();
    });

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
