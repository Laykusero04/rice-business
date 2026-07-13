<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Suppliers';
$activePage = 'suppliers';

$search = trim($_GET['q'] ?? '');

$sql = 'SELECT * FROM suppliers WHERE 1=1';
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
$suppliers = $stmt->fetchAll();

$flash = '';
$flashType = 'success';

if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'created' => 'Supplier added successfully.',
        'updated' => 'Supplier updated successfully.',
        'deleted' => 'Supplier deleted successfully.',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'required' => 'Supplier name is required.',
        'invalid' => 'Please check the values you entered.',
        'save' => 'Could not save the supplier.',
        'delete' => 'Could not delete the supplier.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Suppliers</h1>
    <p class="text-muted mb-0">Manage rice suppliers and their contact details.</p>
  </div>
  <button type="button" class="btn btn-rice" data-bs-toggle="modal" data-bs-target="#supplierModal" id="btnAddSupplier">
    <i class="bi bi-plus-lg"></i> Add Supplier
  </button>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<form class="row g-2 mb-3" method="GET" action="suppliers.php">
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
    <a href="suppliers.php" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Name</th>
        <th>Contact</th>
        <th>Address</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($suppliers) === 0): ?>
        <tr>
          <td colspan="4" class="text-center text-muted py-4">No suppliers found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($suppliers as $supplier): ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($supplier['name']) ?></td>
            <td><?= htmlspecialchars($supplier['contact'] ?? '—') ?></td>
            <td><?= htmlspecialchars($supplier['address'] ?? '—') ?></td>
            <td class="text-end text-nowrap">
              <button
                type="button"
                class="btn btn-sm btn-outline-primary btn-edit-supplier"
                data-bs-toggle="modal"
                data-bs-target="#supplierModal"
                data-id="<?= (int) $supplier['id'] ?>"
                data-name="<?= htmlspecialchars($supplier['name'], ENT_QUOTES) ?>"
                data-contact="<?= htmlspecialchars($supplier['contact'] ?? '', ENT_QUOTES) ?>"
                data-address="<?= htmlspecialchars($supplier['address'] ?? '', ENT_QUOTES) ?>"
              >
                Edit
              </button>
              <form
                method="POST"
                action="/rice-business/backend/supplier_delete.php"
                class="d-inline"
                onsubmit="return confirm('Delete this supplier?');"
              >
                <input type="hidden" name="id" value="<?= (int) $supplier['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="supplierModal" tabindex="-1" aria-labelledby="supplierModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/supplier_save.php">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="supplierModalLabel">Add Supplier</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="supplierId" value="">

          <div class="mb-3">
            <label for="supplierName" class="form-label">Name</label>
            <input type="text" class="form-control" id="supplierName" name="name" required maxlength="100">
          </div>

          <div class="mb-3">
            <label for="supplierContact" class="form-label">Contact</label>
            <input type="text" class="form-control" id="supplierContact" name="contact" maxlength="50" placeholder="Phone or email">
          </div>

          <div class="mb-0">
            <label for="supplierAddress" class="form-label">Address</label>
            <textarea class="form-control" id="supplierAddress" name="address" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice" id="supplierSubmitBtn">Save Supplier</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('supplierModal');
  const title = document.getElementById('supplierModalLabel');
  const submitBtn = document.getElementById('supplierSubmitBtn');

  function resetForm() {
    document.getElementById('supplierId').value = '';
    document.getElementById('supplierName').value = '';
    document.getElementById('supplierContact').value = '';
    document.getElementById('supplierAddress').value = '';
    title.textContent = 'Add Supplier';
    submitBtn.textContent = 'Save Supplier';
  }

  document.getElementById('btnAddSupplier').addEventListener('click', resetForm);

  document.querySelectorAll('.btn-edit-supplier').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('supplierId').value = btn.dataset.id;
      document.getElementById('supplierName').value = btn.dataset.name;
      document.getElementById('supplierContact').value = btn.dataset.contact;
      document.getElementById('supplierAddress').value = btn.dataset.address;
      title.textContent = 'Edit Supplier';
      submitBtn.textContent = 'Update Supplier';
    });
  });

  modal.addEventListener('hidden.bs.modal', resetForm);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
