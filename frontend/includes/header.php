<?php
if (!isset($user)) {
    require_once __DIR__ . '/../../backend/auth.php';
    requireLogin();
    $user = currentUser();
}

$pageTitle = $pageTitle ?? 'Sjeu Store';
$activePage = $activePage ?? '';
$styleCssPath = __DIR__ . '/../assets/css/style.css';
$styleCssVer = is_file($styleCssPath) ? (string) filemtime($styleCssPath) : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> | Sjeu Store</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/rice-business/frontend/assets/css/style.css?v=<?= htmlspecialchars($styleCssVer) ?>">
  <style>
    /* Flat theme critical overrides (beats cached CSS) */
    :root {
      --brand-blue: #2563eb;
      --brand-blue-dark: #1e3a8a;
      --brand-grey: #6b7280;
      --brand-grey-dark: #374151;
      --brand-purple: #7c3aed;
      --brand-surface: #f1f5f9;
      --rice-green: #2563eb;
      --rice-green-dark: #1e3a8a;
      --rice-gold: #7c3aed;
    }
    .app-drawer {
      background: #1e3a8a !important;
    }
    .app-topbar {
      background: #ffffff !important;
      color: #374151 !important;
      border-bottom: 1px solid #e5e7eb !important;
    }
    .drawer-brand a,
    .drawer-brand span {
      color: #fff !important;
    }
    .login-brand,
    .login-brand span {
      color: #1e3a8a !important;
    }
    .btn-rice {
      background: #2563eb !important;
      border-color: #2563eb !important;
      color: #fff !important;
    }
    .btn-rice:hover,
    .btn-rice:focus {
      background: #1e3a8a !important;
      border-color: #1e3a8a !important;
    }
    .drawer-nav .nav-link.active {
      background: rgba(37, 99, 235, 0.45) !important;
      border-left-color: #7c3aed !important;
    }
  </style>
</head>
<body class="app-body">
  <?php require __DIR__ . '/sidebar.php'; ?>

  <div class="app-main">
    <header class="app-topbar">
      <button
        class="btn btn-link text-dark p-0 drawer-toggle"
        type="button"
        id="drawerToggle"
        aria-label="Toggle menu"
      >
        <i class="bi bi-list fs-3"></i>
      </button>

      <div class="ms-auto d-flex align-items-center gap-3 text-dark">
        <span class="small d-none d-sm-inline text-muted">
          <?= htmlspecialchars($user['name']) ?>
          <span>(<?= htmlspecialchars($user['role']) ?>)</span>
        </span>
        <a class="btn btn-sm btn-outline-secondary" href="/rice-business/backend/logout.php">Logout</a>
      </div>
    </header>

    <main class="app-content">
