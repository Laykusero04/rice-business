<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Customers';
$activePage = 'customers';

$search = trim($_GET['q'] ?? '');

$sql = 'SELECT * FROM customers WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND (name LIKE ? OR contact LIKE ? OR address LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$flash = '';
$flashType = 'success';

if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'created' => 'Customer added successfully.',
        'updated' => 'Customer updated successfully.',
        'deleted' => 'Customer deleted successfully.',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'required' => 'Customer name is required.',
        'invalid' => 'Please check the values you entered.',
        'save' => 'Could not save the customer.',
        'delete' => 'Could not delete the customer.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Customers</h1>
    <p class="text-muted mb-0">Store regular buyers and their contact details.</p>
  </div>
  <button type="button" class="btn btn-rice" data-bs-toggle="modal" data-bs-target="#customerModal" id="btnAddCustomer">
    <i class="bi bi-plus-lg"></i> Add Customer
  </button>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<form class="row g-2 mb-3" method="GET" action="customers.php">
  <div class="col-md-6">
    <input
      type="search"
      name="q"
      class="form-control"
      placeholder="Search by name, contact, or address..."
      value="<?= htmlspecialchars($search) ?>"
    >
  </div>
  <div class="col-md-6 d-flex gap-2">
    <button type="submit" class="btn btn-outline-secondary">Search</button>
    <a href="customers.php" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Name</th>
        <th>Contact</th>
        <th>Address</th>
        <th>Notes</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($customers) === 0): ?>
        <tr>
          <td colspan="5" class="text-center text-muted py-4">No customers found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($customers as $customer): ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($customer['name']) ?></td>
            <td><?= htmlspecialchars($customer['contact'] ?? '—') ?></td>
            <td><?= htmlspecialchars($customer['address'] ?? '—') ?></td>
            <td class="text-muted small" style="max-width: 220px;">
              <?= htmlspecialchars($customer['notes'] ?? '—') ?>
            </td>
            <td class="text-end text-nowrap">
              <button
                type="button"
                class="btn btn-sm btn-outline-primary btn-edit-customer"
                data-bs-toggle="modal"
                data-bs-target="#customerModal"
                data-id="<?= (int) $customer['id'] ?>"
                data-name="<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>"
                data-contact="<?= htmlspecialchars($customer['contact'] ?? '', ENT_QUOTES) ?>"
                data-address="<?= htmlspecialchars($customer['address'] ?? '', ENT_QUOTES) ?>"
                data-notes="<?= htmlspecialchars($customer['notes'] ?? '', ENT_QUOTES) ?>"
              >
                Edit
              </button>
              <form
                method="POST"
                action="/rice-business/backend/customer_delete.php"
                class="d-inline"
                onsubmit="return confirm('Delete this customer?');"
              >
                <input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/customer_save.php">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="customerModalLabel">Add Customer</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="customerId" value="">

          <div class="mb-3">
            <label for="customerName" class="form-label">Name</label>
            <input type="text" class="form-control" id="customerName" name="name" required maxlength="100">
          </div>

          <div class="mb-3">
            <label for="customerContact" class="form-label">Contact</label>
            <input type="text" class="form-control" id="customerContact" name="contact" maxlength="50" placeholder="Phone or email">
          </div>

          <div class="mb-3">
            <label for="customerAddress" class="form-label">Address</label>
            <textarea class="form-control" id="customerAddress" name="address" rows="2"></textarea>
          </div>

          <div class="mb-0">
            <label for="customerNotes" class="form-label">Notes</label>
            <textarea class="form-control" id="customerNotes" name="notes" rows="2" placeholder="Optional"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice" id="customerSubmitBtn">Save Customer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('customerModal');
  const title = document.getElementById('customerModalLabel');
  const submitBtn = document.getElementById('customerSubmitBtn');

  function resetForm() {
    document.getElementById('customerId').value = '';
    document.getElementById('customerName').value = '';
    document.getElementById('customerContact').value = '';
    document.getElementById('customerAddress').value = '';
    document.getElementById('customerNotes').value = '';
    title.textContent = 'Add Customer';
    submitBtn.textContent = 'Save Customer';
  }

  document.getElementById('btnAddCustomer').addEventListener('click', resetForm);

  document.querySelectorAll('.btn-edit-customer').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('customerId').value = btn.dataset.id;
      document.getElementById('customerName').value = btn.dataset.name;
      document.getElementById('customerContact').value = btn.dataset.contact;
      document.getElementById('customerAddress').value = btn.dataset.address;
      document.getElementById('customerNotes').value = btn.dataset.notes;
      title.textContent = 'Edit Customer';
      submitBtn.textContent = 'Update Customer';
    });
  });

  modal.addEventListener('hidden.bs.modal', resetForm);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
