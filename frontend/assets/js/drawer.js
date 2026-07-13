(function () {
  const body = document.body;
  const toggle = document.getElementById('drawerToggle');
  const closeBtn = document.getElementById('drawerClose');
  const backdrop = document.getElementById('drawerBackdrop');

  function openDrawer() {
    body.classList.add('drawer-open');
  }

  function closeDrawer() {
    body.classList.remove('drawer-open');
  }

  function toggleDrawer() {
    if (window.innerWidth < 992) {
      body.classList.toggle('drawer-open');
      return;
    }
    body.classList.toggle('drawer-collapsed');
  }

  if (toggle) toggle.addEventListener('click', toggleDrawer);
  if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
  if (backdrop) backdrop.addEventListener('click', closeDrawer);

  document.querySelectorAll('.nav-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.closest('.nav-group').classList.toggle('is-open');
    });
  });
})();
