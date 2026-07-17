<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Expenses';
$activePage = 'expenses';

$categories = ['Delivery', 'Electricity', 'Salary', 'Maintenance', 'Fuel', 'Owner Investment', 'Other'];

$search = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$sql = 'SELECT * FROM expenses WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND (category LIKE ? OR notes LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($categoryFilter !== '' && in_array($categoryFilter, $categories, true)) {
    $sql .= ' AND category = ?';
    $params[] = $categoryFilter;
}

if ($from !== '') {
    $sql .= ' AND expense_date >= ?';
    $params[] = $from;
}

if ($to !== '') {
    $sql .= ' AND expense_date <= ?';
    $params[] = $to;
}

$sql .= ' ORDER BY expense_date DESC, id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$totalAmount = 0.0;
foreach ($expenses as $expense) {
    $totalAmount += (float) $expense['amount'];
}

$flash = '';
$flashType = 'success';

if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'created' => 'Expense added successfully.',
        'updated' => 'Expense updated successfully.',
        'deleted' => 'Expense deleted successfully.',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'required' => 'Category, amount, and date are required.',
        'invalid' => 'Please check the values you entered.',
        'save' => 'Could not save the expense.',
        'delete' => 'Could not delete the expense.',
        'linked' => 'This expense was created from a purchase and cannot be changed here.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Expenses</h1>
    <p class="text-muted mb-0">Track delivery, utilities, salary, and other costs.</p>
  </div>
  <button type="button" class="btn btn-rice" data-bs-toggle="modal" data-bs-target="#expenseModal" id="btnAddExpense">
    <i class="bi bi-plus-lg"></i> Add Expense
  </button>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<form class="row g-2 mb-3" method="GET" action="expenses.php">
  <div class="col-md-3">
    <input
      type="search"
      name="q"
      class="form-control"
      placeholder="Search notes or category..."
      value="<?= htmlspecialchars($search) ?>"
    >
  </div>
  <div class="col-md-2">
    <select name="category" class="form-select">
      <option value="">All categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= htmlspecialchars($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>>
          <?= htmlspecialchars($cat) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
  </div>
  <div class="col-md-2">
    <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
  </div>
  <div class="col-md-3 d-flex gap-2">
    <button type="submit" class="btn btn-outline-secondary">Filter</button>
    <a href="expenses.php" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="d-flex justify-content-end mb-2">
  <div class="bg-white border rounded px-3 py-2 small">
    Filtered total: <strong>₱<?= number_format($totalAmount, 2) ?></strong>
  </div>
</div>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Date</th>
        <th>Category</th>
        <th class="text-end">Amount</th>
        <th>Notes</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($expenses) === 0): ?>
        <tr>
          <td colspan="5" class="text-center text-muted py-4">No expenses found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($expenses as $expense): ?>
          <tr>
            <td><?= htmlspecialchars($expense['expense_date']) ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($expense['category']) ?></td>
            <td class="text-end">₱<?= number_format((float) $expense['amount'], 2) ?></td>
            <td class="text-muted small" style="max-width: 260px;">
              <?= htmlspecialchars($expense['notes'] ?? '—') ?>
              <?php if (!empty($expense['purchase_id'])): ?>
                <div>
                  <a href="purchase_view.php?id=<?= (int) $expense['purchase_id'] ?>" class="small">View purchase #<?= (int) $expense['purchase_id'] ?></a>
                </div>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <?php if (!empty($expense['purchase_id'])): ?>
                <a href="purchase_view.php?id=<?= (int) $expense['purchase_id'] ?>" class="btn btn-sm btn-outline-secondary">View purchase</a>
              <?php else: ?>
              <button
                type="button"
                class="btn btn-sm btn-outline-primary btn-edit-expense"
                data-bs-toggle="modal"
                data-bs-target="#expenseModal"
                data-id="<?= (int) $expense['id'] ?>"
                data-category="<?= htmlspecialchars($expense['category'], ENT_QUOTES) ?>"
                data-amount="<?= htmlspecialchars($expense['amount']) ?>"
                data-expense-date="<?= htmlspecialchars($expense['expense_date']) ?>"
                data-notes="<?= htmlspecialchars($expense['notes'] ?? '', ENT_QUOTES) ?>"
              >
                Edit
              </button>
              <form
                method="POST"
                action="/rice-business/backend/expense_delete.php"
                class="d-inline"
                onsubmit="return confirm('Delete this expense?');"
              >
                <input type="hidden" name="id" value="<?= (int) $expense['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="expenseModal" tabindex="-1" aria-labelledby="expenseModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/expense_save.php">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="expenseModalLabel">Add Expense</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="expenseId" value="">

          <div class="mb-3">
            <label for="expenseCategory" class="form-label">Category</label>
            <select class="form-select" id="expenseCategory" name="category" required>
              <option value="">Select category</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="expenseAmount" class="form-label">Amount (₱)</label>
              <input type="number" class="form-control" id="expenseAmount" name="amount" step="0.01" min="0.01" required>
            </div>
            <div class="col-md-6">
              <label for="expenseDate" class="form-label">Date</label>
              <input type="date" class="form-control" id="expenseDate" name="expense_date" value="<?= date('Y-m-d') ?>" required>
            </div>
          </div>

          <div class="mb-0">
            <label for="expenseNotes" class="form-label">Notes</label>
            <textarea class="form-control" id="expenseNotes" name="notes" rows="2" placeholder="Optional"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice" id="expenseSubmitBtn">Save Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('expenseModal');
  const title = document.getElementById('expenseModalLabel');
  const submitBtn = document.getElementById('expenseSubmitBtn');

  function resetForm() {
    document.getElementById('expenseId').value = '';
    document.getElementById('expenseCategory').value = '';
    document.getElementById('expenseAmount').value = '';
    document.getElementById('expenseDate').value = '<?= date('Y-m-d') ?>';
    document.getElementById('expenseNotes').value = '';
    title.textContent = 'Add Expense';
    submitBtn.textContent = 'Save Expense';
  }

  document.getElementById('btnAddExpense').addEventListener('click', resetForm);

  document.querySelectorAll('.btn-edit-expense').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('expenseId').value = btn.dataset.id;
      document.getElementById('expenseCategory').value = btn.dataset.category;
      document.getElementById('expenseAmount').value = btn.dataset.amount;
      document.getElementById('expenseDate').value = btn.dataset.expenseDate;
      document.getElementById('expenseNotes').value = btn.dataset.notes;
      title.textContent = 'Edit Expense';
      submitBtn.textContent = 'Update Expense';
    });
  });

  modal.addEventListener('hidden.bs.modal', resetForm);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
