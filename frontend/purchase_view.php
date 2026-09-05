<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
require_once __DIR__ . '/../backend/stock_lots.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Purchase Details';
$activePage = 'purchases-history';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    header('Location: /rice-business/frontend/purchases.php');
    exit;
}

ensurePurchaseForProductColumn($pdo);
ensureSourcePurchaseColumn($pdo);

$stmt = $pdo->prepare(
    'SELECT p.*, s.name AS supplier_name, s.contact AS supplier_contact,
            pr.name AS for_product_name
     FROM purchases p
     INNER JOIN suppliers s ON s.id = p.supplier_id
     LEFT JOIN products pr ON pr.id = p.for_product_id
     WHERE p.id = ?
     LIMIT 1'
);
$stmt->execute([$id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    header('Location: /rice-business/frontend/purchases.php?error=notfound');
    exit;
}

$itemStmt = $pdo->prepare(
    'SELECT pi.*,
            COALESCE(pi.item_name, pr.name, \'—\') AS product_name
     FROM purchase_items pi
     LEFT JOIN products pr ON pr.id = pi.product_id
     WHERE pi.purchase_id = ?
     ORDER BY pi.id ASC'
);
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$batchLabel = trim((string) ($purchase['batch_label'] ?? ''));
$purchaseTotal = round((float) $purchase['total'], 2);

$linkedLots = [];
try {
    $lotStmt = $pdo->prepare(
        'SELECT sl.*, p.name AS product_name, p.kg_per_sack, p.unit, p.product_type
         FROM stock_lots sl
         INNER JOIN products p ON p.id = sl.product_id
         WHERE sl.source_purchase_id = ?
         ORDER BY sl.id DESC'
    );
    $lotStmt->execute([$id]);
    $linkedLots = $lotStmt->fetchAll();
} catch (PDOException $e) {
    $linkedLots = [];
}

$allocatedCost = 0.0;
$soldRevenue = 0.0;
$remainingValue = 0.0;
$totalLeakageKg = 0.0;
$totalLeakageCost = 0.0;
foreach ($linkedLots as &$lot) {
    $invested = $lot['total_cost'] !== null
        ? round((float) $lot['total_cost'], 2)
        : round((float) $lot['quantity_original'] * (float) $lot['buying_price'], 2);
    $started = round((float) $lot['quantity_original'], 2);
    $remaining = round((float) $lot['quantity_remaining'], 2);
    $remValue = round($remaining * (float) $lot['buying_price'], 2);
    $buyPer = (float) $lot['buying_price'];

    $saleStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(si.subtotal), 0) AS revenue,
                COALESCE(SUM(si.quantity), 0) AS qty_sold
         FROM sale_items si
         WHERE si.stock_lot_id = ?'
    );
    $saleStmt->execute([(int) $lot['id']]);
    $saleRow = $saleStmt->fetch() ?: ['revenue' => 0, 'qty_sold' => 0];
    $revenue = round((float) $saleRow['revenue'], 2);
    $soldQty = round((float) $saleRow['qty_sold'], 2);
    $goneQty = round(max(0, $started - $remaining), 2);
    $leakKg = round(max(0, $goneQty - $soldQty), 2);
    $leakCost = $leakKg > 0 && $buyPer > 0
        ? round($leakKg * $buyPer, 2)
        : ($started > 0 && $leakKg > 0 ? round($invested * ($leakKg / $started), 2) : 0.0);

    $lot['_invested'] = $invested;
    $lot['_started'] = $started;
    $lot['_remaining'] = $remaining;
    $lot['_remaining_value'] = $remValue;
    $lot['_revenue'] = $revenue;
    $lot['_sold_qty'] = $soldQty;
    $lot['_leakage_kg'] = $leakKg;
    $lot['_leakage_cost'] = $leakCost;
    $lot['_money_gp'] = round($revenue - max(0, $invested - $remValue), 2);
    $lot['_label'] = formatLotLabel($lot);

    $allocatedCost += $invested;
    $soldRevenue += $revenue;
    $remainingValue += $remValue;
    $totalLeakageKg += $leakKg;
    $totalLeakageCost += $leakCost;
}
unset($lot);

$allocatedCost = round($allocatedCost, 2);
$soldRevenue = round($soldRevenue, 2);
$remainingValue = round($remainingValue, 2);
$totalLeakageKg = round($totalLeakageKg, 2);
$totalLeakageCost = round($totalLeakageCost, 2);
$costLeft = round(max(0, $purchaseTotal - $allocatedCost), 2);
$moneyProfit = round($soldRevenue - max(0, $allocatedCost - $remainingValue), 2);

$sellProducts = $pdo->query(
    "SELECT id, name, kg_per_sack FROM products
     WHERE status = 'active' AND product_type = 'RICE'
     ORDER BY name ASC"
)->fetchAll();

$defaultProductId = (int) ($purchase['for_product_id'] ?? 0);

$flash = '';
$flashType = 'success';
if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'created' => 'Purchase saved as batch '
            . htmlspecialchars($batchLabel !== '' ? $batchLabel : '#' . $id)
            . '. Link sell stock below when you mix.',
        'updated' => 'Purchase updated successfully.',
        'batch_added' => 'Sell batch linked to this purchase. Profit updates as you sell.',
        'closed' => 'Batch marked empty. Leftover estimate recorded as write-off / leakage.',
        'writeoff' => 'Leftover written off as leakage loss.',
        default => '',
    };
}
if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'lot_used' => 'Cannot delete — some stock from this purchase was already sold. Delete or edit those sales first.',
        'stock' => 'Cannot delete — not enough stock left for '
            . htmlspecialchars($_GET['product'] ?? 'a product')
            . '.',
        'delete' => 'Could not delete this purchase.',
        'sacks' => 'Enter how many sacks for the sell batch.',
        'purchase_batch' => 'Could not link sell batch to this purchase.',
        'save' => 'Could not save the sell batch.',
        'invalid' => 'Check the values you entered.',
        'lot' => 'Could not mark that batch empty.',
        default => 'Something went wrong.',
    };
}

$paymentSourceLabels = [
    'business' => 'Business funds',
    'personal' => 'Personal money (mine)',
];
$paymentSource = $purchase['payment_source'] ?? 'business';

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Purchase #<?= (int) $purchase['id'] ?></h1>
    <p class="text-muted mb-0">
      Batch <strong><?= htmlspecialchars($batchLabel !== '' ? $batchLabel : '—') ?></strong>
      — cost ₱<?= number_format($purchaseTotal, 2) ?>.
      Profit = sales from linked sell batches − this purchase cost.
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="purchase_new.php?id=<?= (int) $purchase['id'] ?>" class="btn btn-outline-primary">Edit</a>
    <form
      method="POST"
      action="/rice-business/backend/purchase_delete.php"
      class="d-inline"
      onsubmit="return confirm('Delete this purchase? Linked sell batches stay; only the buy record is removed if allowed.');"
    >
      <input type="hidden" name="id" value="<?= (int) $purchase['id'] ?>">
      <button type="submit" class="btn btn-outline-danger">Delete</button>
    </form>
    <a href="purchases.php" class="btn btn-outline-secondary">Back to History</a>
    <a href="purchase_new.php" class="btn btn-rice">New Purchase</a>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= $flashType === 'danger' ? $flash : $flash ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Supplier</div>
      <div class="fw-semibold"><?= htmlspecialchars($purchase['supplier_name']) ?></div>
      <div class="small text-muted"><?= htmlspecialchars($purchase['supplier_contact'] ?? '') ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Date</div>
      <div class="fw-semibold"><?= htmlspecialchars($purchase['purchase_date']) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Batch</div>
      <div class="fw-semibold"><?= htmlspecialchars($batchLabel !== '' ? $batchLabel : '—') ?></div>
      <?php if (!empty($purchase['for_product_name'])): ?>
        <div class="small text-muted mt-1">For: <?= htmlspecialchars($purchase['for_product_name']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Paid with</div>
      <div class="fw-semibold">
        <?php if ($paymentSource === 'personal'): ?>
          <span class="badge text-bg-warning"><?= htmlspecialchars($paymentSourceLabels[$paymentSource]) ?></span>
        <?php else: ?>
          <?= htmlspecialchars($paymentSourceLabels[$paymentSource] ?? 'Business funds') ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Purchase cost</div>
      <div class="fw-semibold fs-5">₱<?= number_format($purchaseTotal, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Cost linked to sell</div>
      <div class="fw-semibold">₱<?= number_format($allocatedCost, 2) ?></div>
      <div class="small text-muted">Left to link: ₱<?= number_format($costLeft, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Sales from linked batches</div>
      <div class="fw-semibold text-success">₱<?= number_format($soldRevenue, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Money profit (so far)</div>
      <div class="fw-semibold fs-5 <?= $moneyProfit >= 0 ? 'text-success' : 'text-danger' ?>">
        ₱<?= number_format($moneyProfit, 2) ?>
      </div>
      <div class="small text-muted">Sales − cost used</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="bg-white rounded shadow-sm p-3 h-100">
      <div class="text-muted small">Leakage (over billed kg)</div>
      <div class="fw-semibold fs-5 <?= $totalLeakageCost > 0 ? 'text-danger' : 'text-muted' ?>">
        ₱<?= number_format($totalLeakageCost, 2) ?>
      </div>
      <div class="small text-muted"><?= number_format($totalLeakageKg, 2) ?> kg</div>
    </div>
  </div>
</div>

<?php if (!empty($purchase['notes'])): ?>
  <div class="alert alert-light border mb-4">
    <strong>Notes:</strong> <?= htmlspecialchars($purchase['notes']) ?>
  </div>
<?php endif; ?>

<div class="table-responsive bg-white rounded shadow-sm mb-4">
  <table class="table align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Bought (rice name)</th>
        <th class="text-end">Sacks</th>
        <th class="text-end">Qty (kg)</th>
        <th class="text-end">Buying Price / kg</th>
        <th class="text-end">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
        <?php
          $kgPerSack = (float) ($item['kg_per_sack'] ?? 25);
          if ($kgPerSack <= 0) {
              $kgPerSack = 25;
          }
          $sacks = (float) $item['quantity'] / $kgPerSack;
        ?>
        <tr>
          <td class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></td>
          <td class="text-end"><?= number_format($sacks, 2) ?></td>
          <td class="text-end"><?= number_format((float) $item['quantity'], 2) ?></td>
          <td class="text-end">₱<?= number_format((float) $item['buying_price'], 2) ?></td>
          <td class="text-end">₱<?= number_format((float) $item['subtotal'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4" class="text-end fw-semibold">Total</td>
        <td class="text-end fw-bold">₱<?= number_format($purchaseTotal, 2) ?></td>
      </tr>
    </tfoot>
  </table>
</div>

<div class="bg-white rounded shadow-sm p-3 p-md-4 mb-4">
  <h2 class="h5 mb-1">Sell batches from this purchase</h2>
  <p class="small text-muted mb-3">
    Leakage = started kg − left kg − sold (billed) kg.
    That is rice given beyond what you charged.
    When the sack is physically empty but Left is still &gt; 0, click <strong>Mark empty</strong>.
  </p>

  <?php if (count($linkedLots) > 0): ?>
    <div class="table-responsive mb-4">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Sell batch</th>
            <th>Product</th>
            <th class="text-end">Sold kg</th>
            <th class="text-end">Leakage</th>
            <th class="text-end">Sold ₱</th>
            <th class="text-end">Profit</th>
            <th class="text-end">Left</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($linkedLots as $lot): ?>
            <?php
              $kgPerSack = (float) ($lot['kg_per_sack'] ?? 25);
              if ($kgPerSack <= 0) {
                  $kgPerSack = 25;
              }
              $sacksLeft = round(((float) $lot['_remaining']) / $kgPerSack, 2);
              $leakKg = (float) $lot['_leakage_kg'];
            ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars((string) ($lot['notes'] ?: ('#' . $lot['id']))) ?></td>
              <td><?= htmlspecialchars($lot['product_name']) ?></td>
              <td class="text-end"><?= number_format((float) $lot['_sold_qty'], 2) ?></td>
              <td class="text-end <?= $leakKg > 0 ? 'text-danger' : 'text-muted' ?>">
                <?php if ($leakKg > 0): ?>
                  <?= number_format($leakKg, 2) ?> kg
                  <div class="small">₱<?= number_format((float) $lot['_leakage_cost'], 2) ?></div>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="text-end">₱<?= number_format((float) $lot['_revenue'], 2) ?></td>
              <td class="text-end fw-semibold <?= (float) $lot['_money_gp'] >= 0 ? 'text-success' : 'text-danger' ?>">
                ₱<?= number_format((float) $lot['_money_gp'], 2) ?>
              </td>
              <td class="text-end"><?= number_format($sacksLeft, 2) ?> sack<?= abs($sacksLeft - 1.0) < 0.001 ? '' : 's' ?></td>
              <td class="text-end text-nowrap">
                <?php if ((float) $lot['_remaining'] > 0.049): ?>
                  <form
                    method="POST"
                    action="/rice-business/backend/inventory_adjust.php"
                    class="d-inline"
                    onsubmit="return confirm('Mark this batch empty?\nLeftover estimate becomes write-off / leakage loss.');"
                  >
                    <input type="hidden" name="mode" value="writeoff">
                    <input type="hidden" name="reason" value="close">
                    <input type="hidden" name="stock_lot_id" value="<?= (int) $lot['id'] ?>">
                    <input type="hidden" name="product_id" value="<?= (int) $lot['product_id'] ?>">
                    <input type="hidden" name="redirect" value="/rice-business/frontend/purchase_view.php?id=<?= (int) $id ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Mark empty</button>
                  </form>
                <?php else: ?>
                  <span class="small text-muted">Empty</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="alert alert-warning py-2">No sell batch linked yet — profit cannot show until you link one.</div>
  <?php endif; ?>

  <?php if (count($sellProducts) === 0): ?>
    <div class="alert alert-light border mb-0">
      Add a rice product on <a href="products.php">Products</a> first, then come back to link sacks.
    </div>
  <?php else: ?>
    <form method="POST" action="/rice-business/backend/batch_create.php" class="row g-3 align-items-end">
      <input type="hidden" name="source_purchase_id" value="<?= (int) $id ?>">
      <input type="hidden" name="redirect" value="/rice-business/frontend/purchase_view.php?id=<?= (int) $id ?>">
      <input type="hidden" name="batch_label" value="<?= htmlspecialchars($batchLabel !== '' ? $batchLabel : ('Purchase-' . $id)) ?>">

      <div class="col-md-4">
        <label for="linkProductId" class="form-label">Sell product (mix)</label>
        <select class="form-select" id="linkProductId" name="product_id" required>
          <option value="">Select product</option>
          <?php foreach ($sellProducts as $sp): ?>
            <option value="<?= (int) $sp['id'] ?>" <?= $defaultProductId === (int) $sp['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($sp['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="linkSacks" class="form-label">Sacks</label>
        <input type="number" class="form-control" id="linkSacks" name="sacks" step="0.01" min="0.01" required placeholder="e.g. 10">
      </div>
      <div class="col-md-3">
        <label for="linkCost" class="form-label">Cost from this purchase (₱)</label>
        <input
          type="number"
          class="form-control"
          id="linkCost"
          name="cost_amount"
          step="0.01"
          min="0.01"
          required
          value="<?= htmlspecialchars(number_format($costLeft > 0 ? $costLeft : $purchaseTotal, 2, '.', '')) ?>"
        >
        <div class="form-text">Left to link: ₱<?= number_format($costLeft, 2) ?></div>
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-rice w-100">Link sell batch</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<p class="small text-muted mb-0">
  Also see <a href="reports.php?tab=batch">Reports → By lot / batch</a> for all batch profits.
</p>

<?php require __DIR__ . '/includes/footer.php'; ?>
