<?php
/**
 * Sidebar admin. Pakai: set $activePage ke nama file halaman (mis. 'dashboard.php')
 * lalu include file ini di dalam <body>. Gaya ada di partials/sidebar.css.
 */
const ADMIN_NAV = [
    'Overview' => [
        ['dashboard.php', 'Dashboard', 'M3 12l9-8 9 8v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
    ],
    'Sales' => [
        ['dashboard-orders.php', 'Orders', 'M6 2l-2 4v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6l-2-4zM4 6h16M16 10a4 4 0 0 1-8 0'],
        ['send-resi.php', 'Send Resi', 'M3 7l9 6 9-6M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z'],
    ],
    'Catalog' => [
        ['dashboard-product.php', 'Products', 'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8'],
        ['dashboard-size.php', 'Sizes', 'M3 17l4-4 4 4 4-4 6 6M3 7h18'],
        ['dashboard-categories.php', 'Categories', 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z'],
        ['set-diskon.php', 'Discounts', 'M19 5L5 19M7.5 8a1.5 1.5 0 1 0 0-.01M16.5 17a1.5 1.5 0 1 0 0-.01'],
        ['admin_products.php', 'Product Layout', 'M4 6h16M4 12h10M4 18h16'],
    ],
    'Content' => [
        ['dashboard-hero.php', 'Hero Slides', 'M3 5h18v14H3zM3 15l5-4 4 3 3-2 6 4'],
        ['lookbook.php', 'Lookbook', 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z'],
        ['dashboard-marquee.php', 'Moving Text', 'M3 12h14M13 8l4 4-4 4M21 5v14'],
    ],
];

const ADMIN_NAV_ALIASES = ['order-detail.php' => 'dashboard-orders.php'];

$activePage = $activePage ?? basename($_SERVER['SCRIPT_NAME']);
$activePage = ADMIN_NAV_ALIASES[$activePage] ?? $activePage;
?>
<button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="adminSidebar">
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
</button>
<div class="nav-scrim" id="navScrim"></div>

<aside class="sidebar" id="adminSidebar" aria-label="Admin navigation">
  <a href="dashboard.php" class="sidebar__brand" aria-label="albrycia admin">
    <img src="../assets/images/brand/logo.png" alt="albrycia">
  </a>

  <nav class="sidebar__nav">
    <?php foreach (ADMIN_NAV as $group => $links): ?>
      <div class="nav-group">
        <div class="nav-group__label"><?= htmlspecialchars($group) ?></div>
        <?php foreach ($links as [$file, $label, $iconPath]): ?>
          <a href="<?= $file ?>" class="nav-item<?= $file === $activePage ? ' is-active' : '' ?>"<?= $file === $activePage ? ' aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $iconPath ?>"/></svg>
            <span><?= htmlspecialchars($label) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <a href="../index.php" class="nav-item sidebar__storefront">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17L17 7M8 7h9v9"/></svg>
    <span>View Storefront</span>
  </a>
  <a href="logout.php" class="nav-item">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
    <span>Sign out</span>
  </a>
</aside>

<script>
(function () {
  const toggle = document.getElementById('navToggle');
  const scrim  = document.getElementById('navScrim');
  const body   = document.body;
  const setOpen = open => {
    body.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', String(open));
  };
  toggle.addEventListener('click', () => setOpen(!body.classList.contains('nav-open')));
  scrim.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });
})();
</script>
