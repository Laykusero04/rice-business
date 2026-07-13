<?php
if (!isset($user)) {
    require_once __DIR__ . '/../../backend/auth.php';
    requireLogin();
    $user = currentUser();
}

$pageTitle = $pageTitle ?? 'Rice Business';
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> | Rice Business</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="app-body">
  <?php require __DIR__ . '/sidebar.php'; ?>

  <div class="app-main">
    <header class="app-topbar">
      <button
        class="btn btn-link text-white p-0 drawer-toggle"
        type="button"
        id="drawerToggle"
        aria-label="Toggle menu"
      >
        <i class="bi bi-list fs-3"></i>
      </button>

      <div class="ms-auto d-flex align-items-center gap-3 text-white">
        <span class="small d-none d-sm-inline">
          <?= htmlspecialchars($user['name']) ?>
          <span class="opacity-75">(<?= htmlspecialchars($user['role']) ?>)</span>
        </span>
        <a class="btn btn-sm btn-outline-light" href="/rice-business/backend/logout.php">Logout</a>
      </div>
    </header>

    <main class="app-content">
