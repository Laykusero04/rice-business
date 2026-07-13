<?php
require_once __DIR__ . '/../backend/auth.php';
redirectIfLoggedIn();

$error = $_GET['error'] ?? '';
$errorMessage = match ($error) {
    'empty' => 'Please enter both username and password.',
    'invalid' => 'Invalid username or password.',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | Rice Business</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
  <div class="card login-card p-4 p-md-5 mx-3">
    <div class="text-center mb-4">
      <h1 class="h3 login-brand mb-1">Rice <span>Business</span></h1>
      <p class="text-muted mb-0">Sign in to continue</p>
    </div>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger py-2" role="alert">
        <?= htmlspecialchars($errorMessage) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="/rice-business/backend/login.php" autocomplete="off">
      <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <input
          type="text"
          class="form-control form-control-lg"
          id="username"
          name="username"
          required
          autofocus
        >
      </div>

      <div class="mb-4">
        <label for="password" class="form-label">Password</label>
        <input
          type="password"
          class="form-control form-control-lg"
          id="password"
          name="password"
          required
        >
      </div>

      <button type="submit" class="btn btn-rice btn-lg w-100">Login</button>
    </form>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
