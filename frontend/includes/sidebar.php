<?php
$activePage = $activePage ?? '';

$navItems = [
    [
        'id' => 'dashboard',
        'label' => 'Dashboard',
        'icon' => 'bi-speedometer2',
        'href' => 'dashboard.php',
    ],
    [
        'id' => 'sales',
        'label' => 'Sales',
        'icon' => 'bi-cart3',
        'children' => [
            ['id' => 'sales-new', 'label' => 'New Sale', 'href' => 'sale_new.php'],
            ['id' => 'sales-history', 'label' => 'Sales History', 'href' => 'sales.php'],
            ['id' => 'utang', 'label' => 'Outstanding Utang', 'href' => 'utang.php'],
        ],
    ],
    ['id' => 'products', 'label' => 'Products', 'icon' => 'bi-box-seam', 'href' => 'products.php'],
    [
        'id' => 'inventory',
        'label' => 'Inventory',
        'icon' => 'bi-clipboard-data',
        'children' => [
            ['id' => 'inventory', 'label' => 'Stock & Batches', 'href' => 'inventory.php'],
            ['id' => 'mix', 'label' => 'Mix Rice', 'href' => 'mix.php'],
        ],
    ],
    [
        'id' => 'purchases',
        'label' => 'Purchases',
        'icon' => 'bi-bag-plus',
        'children' => [
            ['id' => 'purchases-new', 'label' => 'New Purchase', 'href' => 'purchase_new.php'],
            ['id' => 'purchases-history', 'label' => 'Purchase History', 'href' => 'purchases.php'],
        ],
    ],
    ['id' => 'customers', 'label' => 'Customers', 'icon' => 'bi-people', 'href' => 'customers.php'],
    ['id' => 'suppliers', 'label' => 'Suppliers', 'icon' => 'bi-truck', 'href' => 'suppliers.php'],
    [
        'id' => 'gcash',
        'label' => 'GCash',
        'icon' => 'bi-phone',
        'children' => [
            ['id' => 'gcash-new', 'label' => 'New Transaction', 'href' => 'gcash_new.php'],
            ['id' => 'gcash-history', 'label' => 'History', 'href' => 'gcash.php'],
        ],
    ],
    ['id' => 'expenses', 'label' => 'Expenses', 'icon' => 'bi-cash-stack', 'href' => 'expenses.php'],
    [
        'id' => 'reports-group',
        'label' => 'Reports',
        'icon' => 'bi-graph-up',
        'children' => [
            ['id' => 'reports', 'label' => 'Reports', 'href' => 'reports.php'],
            ['id' => 'analytics', 'label' => 'Analytics', 'href' => 'analytics.php'],
        ],
    ],
    ['id' => 'users', 'label' => 'Users', 'icon' => 'bi-person-gear', 'href' => 'users.php'],
    ['id' => 'settings', 'label' => 'Settings', 'icon' => 'bi-gear', 'href' => 'settings.php'],
];

// Cashiers should not see Users management in the menu.
if (!isAdmin()) {
    $navItems = array_values(array_filter($navItems, static function ($item) {
        return ($item['id'] ?? '') !== 'users';
    }));
}?>
<aside class="app-drawer" id="appDrawer">
  <div class="drawer-brand">
    <a href="dashboard.php" class="text-decoration-none">
      Sjeu <span>Store</span>
    </a>
    <button class="btn btn-link text-white d-lg-none p-0 drawer-close" type="button" id="drawerClose" aria-label="Close menu">
      <i class="bi bi-x-lg fs-5"></i>
    </button>
  </div>

  <nav class="drawer-nav">
    <?php foreach ($navItems as $item): ?>
      <?php if (!empty($item['children'])): ?>
        <?php
          $childActive = false;
          foreach ($item['children'] as $child) {
              if ($activePage === $child['id']) {
                  $childActive = true;
                  break;
              }
          }
          $parentOpen = $childActive || $activePage === $item['id'];
        ?>
        <div class="nav-group <?= $parentOpen ? 'is-open' : '' ?>">
          <button class="nav-link nav-toggle <?= $parentOpen ? 'active' : '' ?>" type="button">
            <i class="bi <?= htmlspecialchars($item['icon']) ?>"></i>
            <span><?= htmlspecialchars($item['label']) ?></span>
            <i class="bi bi-chevron-down ms-auto chevron"></i>
          </button>
          <div class="nav-submenu">
            <?php foreach ($item['children'] as $child): ?>
              <a
                href="<?= htmlspecialchars($child['href']) ?>"
                class="nav-link sub <?= $activePage === $child['id'] ? 'active' : '' ?>"
              >
                <?= htmlspecialchars($child['label']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <a
          href="<?= htmlspecialchars($item['href']) ?>"
          class="nav-link <?= $activePage === $item['id'] ? 'active' : '' ?>"
        >
          <i class="bi <?= htmlspecialchars($item['icon']) ?>"></i>
          <span><?= htmlspecialchars($item['label']) ?></span>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
</aside>

<div class="drawer-backdrop" id="drawerBackdrop"></div>
