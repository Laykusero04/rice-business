<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/conn.php';
requireLogin();

$user = currentUser();
$pageTitle = 'Settings';
$activePage = 'settings';

$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($name === '') {
        $flashType = 'danger';
        $flash = 'Name is required.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
            $stmt->execute([(int) $user['id']]);
            $dbUser = $stmt->fetch();

            if (!$dbUser) {
                $flashType = 'danger';
                $flash = 'Account not found.';
            } else {
                $updatePassword = false;

                if ($newPassword !== '' || $confirmPassword !== '' || $currentPassword !== '') {
                    if ($currentPassword === '' || !password_verify($currentPassword, $dbUser['password'])) {
                        $flashType = 'danger';
                        $flash = 'Current password is incorrect.';
                    } elseif (strlen($newPassword) < 6) {
                        $flashType = 'danger';
                        $flash = 'New password must be at least 6 characters.';
                    } elseif ($newPassword !== $confirmPassword) {
                        $flashType = 'danger';
                        $flash = 'New password confirmation does not match.';
                    } else {
                        $updatePassword = true;
                    }
                }

                if ($flash === '') {
                    if ($updatePassword) {
                        $update = $pdo->prepare(
                            'UPDATE users SET name = ?, password = ? WHERE id = ?'
                        );
                        $update->execute([
                            $name,
                            password_hash($newPassword, PASSWORD_DEFAULT),
                            (int) $user['id'],
                        ]);
                        $flash = 'Profile and password updated.';
                    } else {
                        $update = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
                        $update->execute([$name, (int) $user['id']]);
                        $flash = 'Profile updated.';
                    }

                    $_SESSION['name'] = $name;
                    $user['name'] = $name;
                }
            }
        } catch (PDOException $e) {
            $flashType = 'danger';
            $flash = 'Could not save settings.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
  <h1 class="h3 mb-1">Settings</h1>
  <p class="text-muted mb-0">Update your profile and password.</p>
</div>

<?php if ($flash !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="bg-white rounded shadow-sm p-3 p-md-4" style="max-width: 560px;">
  <form method="POST" action="settings.php">
    <div class="mb-3">
      <label class="form-label">Username</label>
      <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
    </div>

    <div class="mb-3">
      <label class="form-label">Role</label>
      <input type="text" class="form-control" value="<?= htmlspecialchars(ucfirst($user['role'])) ?>" disabled>
    </div>

    <div class="mb-3">
      <label for="settingsName" class="form-label">Display Name</label>
      <input
        type="text"
        class="form-control"
        id="settingsName"
        name="name"
        required
        maxlength="100"
        value="<?= htmlspecialchars($user['name']) ?>"
      >
    </div>

    <hr class="my-4">
    <h2 class="h6">Change Password</h2>
    <p class="small text-muted">Leave blank if you do not want to change your password.</p>

    <div class="mb-3">
      <label for="currentPassword" class="form-label">Current Password</label>
      <input type="password" class="form-control" id="currentPassword" name="current_password">
    </div>

    <div class="mb-3">
      <label for="newPassword" class="form-label">New Password</label>
      <input type="password" class="form-control" id="newPassword" name="new_password" minlength="6">
    </div>

    <div class="mb-4">
      <label for="confirmPassword" class="form-label">Confirm New Password</label>
      <input type="password" class="form-control" id="confirmPassword" name="confirm_password" minlength="6">
    </div>

    <button type="submit" class="btn btn-rice">Save Settings</button>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
