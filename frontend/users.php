<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireAdmin();

$user = currentUser();
$pageTitle = 'Users';
$activePage = 'users';

$search = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');

$sql = 'SELECT id, name, username, role, created_at FROM users WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND (name LIKE ? OR username LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($roleFilter === 'admin' || $roleFilter === 'cashier') {
    $sql .= ' AND role = ?';
    $params[] = $roleFilter;
}

$sql .= ' ORDER BY name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$flash = '';
$flashType = 'success';

if (isset($_GET['success'])) {
    $flash = match ($_GET['success']) {
        'created' => 'User added successfully.',
        'updated' => 'User updated successfully.',
        'deleted' => 'User deleted successfully.',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $flashType = 'danger';
    $flash = match ($_GET['error']) {
        'required' => 'Name and username are required.',
        'password' => 'Password is required for new users.',
        'exists' => 'Username already exists.',
        'self' => 'You cannot delete your own account.',
        'lastadmin' => 'Cannot delete the last admin account.',
        'invalid' => 'Invalid user selected.',
        'save' => 'Could not save the user.',
        'delete' => 'Could not delete the user.',
        default => 'Something went wrong.',
    };
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-1">Users</h1>
    <p class="text-muted mb-0">Manage admin and cashier accounts.</p>
  </div>
  <button type="button" class="btn btn-rice" data-bs-toggle="modal" data-bs-target="#userModal" id="btnAddUser">
    <i class="bi bi-plus-lg"></i> Add User
  </button>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<form class="row g-2 mb-3" method="GET" action="users.php">
  <div class="col-md-5">
    <input
      type="search"
      name="q"
      class="form-control"
      placeholder="Search name or username..."
      value="<?= htmlspecialchars($search) ?>"
    >
  </div>
  <div class="col-md-3">
    <select name="role" class="form-select">
      <option value="">All roles</option>
      <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
      <option value="cashier" <?= $roleFilter === 'cashier' ? 'selected' : '' ?>>Cashier</option>
    </select>
  </div>
  <div class="col-md-4 d-flex gap-2">
    <button type="submit" class="btn btn-outline-secondary">Filter</button>
    <a href="users.php" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="table-responsive bg-white rounded shadow-sm">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Name</th>
        <th>Username</th>
        <th>Role</th>
        <th>Created</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($users) === 0): ?>
        <tr>
          <td colspan="5" class="text-center text-muted py-4">No users found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($users as $row): ?>
          <tr>
            <td class="fw-semibold">
              <?= htmlspecialchars($row['name']) ?>
              <?php if ((int) $row['id'] === (int) $user['id']): ?>
                <span class="badge text-bg-info ms-1">You</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($row['username']) ?></td>
            <td>
              <?php if ($row['role'] === 'admin'): ?>
                <span class="badge text-bg-primary">Admin</span>
              <?php else: ?>
                <span class="badge text-bg-secondary">Cashier</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(date('Y-m-d', strtotime($row['created_at']))) ?></td>
            <td class="text-end text-nowrap">
              <button
                type="button"
                class="btn btn-sm btn-outline-primary btn-edit-user"
                data-bs-toggle="modal"
                data-bs-target="#userModal"
                data-id="<?= (int) $row['id'] ?>"
                data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
                data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>"
                data-role="<?= htmlspecialchars($row['role'], ENT_QUOTES) ?>"
              >
                Edit
              </button>
              <?php if ((int) $row['id'] !== (int) $user['id']): ?>
                <form
                  method="POST"
                  action="/rice-business/backend/user_delete.php"
                  class="d-inline"
                  onsubmit="return confirm('Delete this user?');"
                >
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
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

<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="/rice-business/backend/user_save.php">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="userModalLabel">Add User</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="userId" value="">

          <div class="mb-3">
            <label for="userName" class="form-label">Name</label>
            <input type="text" class="form-control" id="userName" name="name" required maxlength="100">
          </div>

          <div class="mb-3">
            <label for="userUsername" class="form-label">Username</label>
            <input type="text" class="form-control" id="userUsername" name="username" required maxlength="50">
          </div>

          <div class="mb-3">
            <label for="userPassword" class="form-label">Password</label>
            <input type="password" class="form-control" id="userPassword" name="password" minlength="6">
            <div class="form-text" id="passwordHelp">Required for new users. Leave blank to keep current password when editing.</div>
          </div>

          <div class="mb-0">
            <label for="userRole" class="form-label">Role</label>
            <select class="form-select" id="userRole" name="role" required>
              <option value="cashier">Cashier</option>
              <option value="admin">Admin</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rice" id="userSubmitBtn">Save User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('userModal');
  const title = document.getElementById('userModalLabel');
  const submitBtn = document.getElementById('userSubmitBtn');
  const passwordInput = document.getElementById('userPassword');

  function resetForm() {
    document.getElementById('userId').value = '';
    document.getElementById('userName').value = '';
    document.getElementById('userUsername').value = '';
    passwordInput.value = '';
    passwordInput.required = true;
    document.getElementById('userRole').value = 'cashier';
    title.textContent = 'Add User';
    submitBtn.textContent = 'Save User';
  }

  document.getElementById('btnAddUser').addEventListener('click', resetForm);

  document.querySelectorAll('.btn-edit-user').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('userId').value = btn.dataset.id;
      document.getElementById('userName').value = btn.dataset.name;
      document.getElementById('userUsername').value = btn.dataset.username;
      document.getElementById('userRole').value = btn.dataset.role;
      passwordInput.value = '';
      passwordInput.required = false;
      title.textContent = 'Edit User';
      submitBtn.textContent = 'Update User';
    });
  });

  modal.addEventListener('hidden.bs.modal', resetForm);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
