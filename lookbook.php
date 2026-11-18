<?php
session_start();
require_once __DIR__ . '/includes/seo.php';

if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

$currentCurrency = $_SESSION['currency'] ?? 'IDR';

require_once __DIR__ . '/config/database.php';

$brandName       = 'ALBRYCIA';
$brandLogo       = 'assets/images/brand/logo.png';

/* ── helpers ─────────────────────────────────────────── */
function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/* ── schema detection ────────────────────────────────── */
$tableReady = tableExists($pdo, 'lookbook_photos');

/* ── query photos grouped by year ────────────────────── */
$yearGroups = [];
if ($tableReady) {
    try {
        $yearStmt = $pdo->query(
            "SELECT year, COUNT(*) AS photo_count
             FROM lookbook_photos
             WHERE is_active = 1
             GROUP BY year
             ORDER BY year DESC"
        );
        $yearList = $yearStmt->fetchAll(PDO::FETCH_ASSOC);

        $photoStmt = $pdo->prepare(
            "SELECT id, year, photo_path, caption, sort_order
             FROM lookbook_photos
             WHERE year = :year AND is_active = 1
             ORDER BY sort_order ASC, id ASC"
        );

        $yearTitles = [];
        if (tableExists($pdo, 'lookbook_year_titles')) {
            $titleRows = $pdo->query('SELECT year, title FROM lookbook_year_titles')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($titleRows as $tr) {
                $yearTitles[(int) $tr['year']] = $tr['title'];
            }
        }

        foreach ($yearList as $y) {
            $yearVal = (int) $y['year'];
            $photoStmt->execute(['year' => $yearVal]);
            $photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);
            $yearGroups[] = [
                'year'  => $yearVal,
                'count' => (int) $y['photo_count'],
                'title' => $yearTitles[$yearVal] ?? null,
                'photos' => $photos,
            ];
        }
    } catch (Throwable $e) {
        $yearGroups = [];
    }
}

/* ── categories (for Shop All dropdown) ──────────────── */
$categories = [];
$hasCategoriesTable = tableExists($pdo, 'categories');
$productColumns     = [];
try { $productColumns = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$hasCategoryId   = false;
$hasCategoryText = false;
foreach ($productColumns as $col) {
    if ($col['Field'] === 'category_id') $hasCategoryId = true;
    if ($col['Field'] === 'category')    $hasCategoryText = true;
}
$joinCategory = $hasCategoriesTable && $hasCategoryId;
if ($joinCategory) {
    try {
        $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id ASC");
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
} elseif ($hasCategoryText) {
    try {
        $catStmt = $pdo->query("SELECT DISTINCT category AS name, LOWER(REPLACE(category, ' ', '-')) AS slug FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC");
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <?php seoHead([
      'title'       => 'Lookbook — ' . BRAND_NAME . ' Visual Journal',
      'description' => BRAND_NAME . ' lookbook: a visual journal of collections, releases and campaigns from Bandung, Indonesia.',
      'path'        => 'lookbook',
      'schema'      => [seoBreadcrumbSchema([['Home', ''], ['Lookbook', 'lookbook']])],
  ]); ?>
  <style>
/* ── reset ───────────────────────────────────────────── */
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:#f3f3f0;color:#111;font-family:Helvetica,Arial,sans-serif;text-transform:uppercase;animation:pageFadeIn .8s ease forwards;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column}
body.lock-scroll{overflow:hidden}
a{color:inherit;text-decoration:none}
img{display:block;width:100%}
button,input{font:inherit}
button{cursor:pointer}

/* ── NAVBAR ──────────────────────────────────────────── */
.navbar{width:100%;min-height:33px;background:#fff;color:#000;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:24px;padding:0 28px;font-size:14px;letter-spacing:.5px;position:sticky;top:0;z-index:60;border-bottom:1px solid rgba(0,0,0,.08)}
.nav-left,.nav-right,.nav-center{display:flex;align-items:center}
.nav-left{gap:12px;justify-content:flex-start}
.nav-center{justify-content:center;gap:34px;white-space:nowrap}
.nav-right{justify-content:flex-end;gap:24px}
.brand{display:inline-flex;align-items:center;gap:10px;font-size:15px;letter-spacing:1.8px}
.brand-logo{width:28px;height:28px;object-fit:contain;flex:0 0 auto;border-radius:50%}
.menu-icon,.icon-btn{background:transparent;border:0;color:#000;font-size:13px;line-height:1}
.navbar a:hover,.nav-center a.active{opacity:.65}

/* ── DROPDOWN ────────────────────────────────────────── */
.dropdown{position:relative}
.dropdown-toggle{background:none;border:none;font-size:14px;letter-spacing:.5px;color:#111;text-transform:uppercase;cursor:pointer;display:flex;align-items:center;justify-content:space-between;font-family:inherit;padding:0;transition:opacity .15s}
.dropdown-toggle:hover{opacity:.65}
.dropdown-menu{display:none;position:absolute;top:100%;left:50%;transform:translateX(-50%);background:#fff;border:1px solid rgba(0,0,0,.08);padding:10px 0;min-width:100px;flex-direction:column;text-align:center;gap:10px;box-shadow:0 4px 12px rgba(0,0,0,.05);z-index:90}
.dropdown.open .dropdown-menu{display:flex;animation:pageFadeIn .3s ease forwards}
.dropdown-menu a{padding:5px 15px;display:block;font-size:13px;transition:background .15s}
.dropdown-menu a:hover{background:#f0f0f0}
.menu-nav .dropdown-menu{position:relative;left:0;transform:none;box-shadow:none;border:none;border-left:1px solid #eee;margin-left:20px;padding-left:10px;align-items:flex-start;top:auto}
.dropdown.open>.dropdown-menu{display:flex}

/* ── SEARCH ──────────────────────────────────────────── */
.search-form{display:flex;align-items:center;border-bottom:1px solid #111;padding-bottom:0}
.search-form input{background:transparent;border:none;outline:none;color:#111;width:160px;font-size:9px;text-transform:uppercase}
.search-form input::placeholder{color:#111}
.search-form button{background:transparent;border:none;color:#111;font-size:20px;line-height:1}

/* ── OVERLAY + MENU ──────────────────────────────────── */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.22);opacity:0;visibility:hidden;transition:opacity .25s ease,visibility .25s ease;z-index:70}
.overlay.active{opacity:1;visibility:visible}
.menu-panel{position:fixed;top:0;left:0;width:340px;max-width:calc(100% - 42px);height:100vh;background:#fff;transform:translateX(-100%);transition:transform .32s ease;z-index:80;border-right:1px solid rgba(0,0,0,.14)}
.menu-panel.active{transform:translateX(0)}
.menu-head{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 22px;border-bottom:1px solid rgba(0,0,0,.1)}
.menu-title{font-size:13px;letter-spacing:1px}
.menu-nav{display:flex;flex-direction:column;padding-top:10px}
.menu-nav a,.menu-nav .dropdown-toggle,.menu-nav .menu-account-btn{padding:18px 22px;border-bottom:1px solid rgba(0,0,0,.08);font-size:9px;letter-spacing:.6px;transition:background .15s;width:100%;text-align:left;box-sizing:border-box;display:flex;align-items:center;justify-content:space-between;background:transparent;border-top:0;border-left:0;border-right:0;color:#111;font-family:inherit;text-transform:uppercase}
.menu-nav a:hover,.menu-nav .dropdown-toggle:hover,.menu-nav .menu-account-btn:hover{background:rgba(0,0,0,.04)}
.menu-nav .dropdown-toggle::after{content:'▾';font-size:10px}
.menu-nav .dropdown.open .dropdown-toggle::after{content:'▴'}
.menu-search-wrap{padding:18px 22px;border-bottom:1px solid rgba(0,0,0,.08)}

/* ── CART SIDEBAR ────────────────────────────────────── */
.cart-panel{position:fixed;top:0;right:0;width:380px;max-width:calc(100% - 42px);height:100vh;background:#fff;transform:translateX(100%);transition:transform .32s ease;z-index:80;border-left:1px solid rgba(0,0,0,.14);display:flex;flex-direction:column}
.cart-panel.active{transform:translateX(0)}
.cart-head{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 22px;border-bottom:1px solid rgba(0,0,0,.1);flex-shrink:0}
.cart-title{font-size:13px;letter-spacing:1px}
.cart-body{flex:1;overflow-y:auto;padding:22px}
.cart-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:14px;color:#888;font-size:13px;letter-spacing:.5px}
.cart-empty-icon{font-size:36px;opacity:.3}
.cart-item{display:grid;grid-template-columns:72px 1fr auto;gap:14px;align-items:center;padding:14px 0;border-bottom:1px solid rgba(0,0,0,.07)}
.cart-item:last-child{border-bottom:none}
.cart-item-img{width:72px;height:72px;object-fit:contain;background:#f3f3f3;flex-shrink:0}
.cart-item-name{font-size:12px;letter-spacing:.4px;line-height:1.4;margin-bottom:4px}
.cart-item-price{font-size:12px;color:#444}
.cart-item-qty{display:flex;align-items:center;gap:8px;margin-top:6px}
.cart-item-qty button{width:22px;height:22px;border:1px solid #ccc;background:transparent;font-size:13px;display:flex;align-items:center;justify-content:center;transition:background .15s}
.cart-item-qty button:hover{background:#f0f0f0}
.cart-item-qty span{font-size:12px;min-width:16px;text-align:center}
.cart-item-remove{background:transparent;border:none;color:#aaa;font-size:18px;cursor:pointer;padding:4px;transition:color .15s;align-self:flex-start}
.cart-item-remove:hover{color:#111}
.cart-foot{padding:18px 22px;border-top:1px solid rgba(0,0,0,.1);flex-shrink:0}
.cart-total{display:flex;justify-content:space-between;align-items:center;font-size:13px;margin-bottom:14px}
.cart-total strong{font-size:15px}
.cart-checkout-btn{display:block;width:100%;min-height:48px;background:#111;color:#fff;border:none;font-size:12px;letter-spacing:1px;cursor:pointer;transition:background .2s}
.cart-checkout-btn:hover{background:#333}
.cart-checkout-btn:disabled{background:#aaa;cursor:not-allowed}

/* ── ACCOUNT MODAL ───────────────────────────────────── */
.account-popup{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;padding:20px;pointer-events:none}
.account-popup.active{pointer-events:auto}
.account-popup__backdrop{position:absolute;inset:0;background:rgba(17,17,17,.42);backdrop-filter:blur(5px);opacity:0;transition:opacity .34s ease}
.account-popup.active .account-popup__backdrop{opacity:1}
.account-popup__dialog{position:relative;width:100%;max-width:420px;background:#f8f8f8;padding:34px 28px 28px;box-shadow:0 28px 80px rgba(0,0,0,.18);opacity:0;transform:translateY(24px) scale(.96);transition:opacity .34s ease,transform .34s ease}
.account-popup.active .account-popup__dialog{opacity:1;transform:translateY(0) scale(1)}
.account-popup__eyebrow{margin-bottom:12px;font-size:11px;letter-spacing:1.2px;color:#666;text-align:center}
.account-popup__title{margin:0 0 22px;font-size:26px;font-weight:400;letter-spacing:.8px;text-align:center}
.account-tabs{display:flex;border-bottom:1px solid rgba(0,0,0,.1);margin-bottom:22px}
.account-tab{flex:1;padding:12px;font-size:12px;letter-spacing:.8px;background:transparent;border:none;cursor:pointer;border-bottom:2px solid transparent;transition:border-color .2s,color .2s;color:#888}
.account-tab.active{border-bottom-color:#111;color:#111}
.account-form{display:flex;flex-direction:column;gap:14px}
.account-form input{width:100%;min-height:44px;padding:0 14px;border:1px solid #d0d0d0;background:#fff;font-size:13px;letter-spacing:.3px;outline:none;transition:border-color .2s;text-transform:none}
.account-form input:focus{border-color:#111}
.account-form input::placeholder{text-transform:uppercase;letter-spacing:.5px;font-size:12px;color:#aaa}
.account-submit{min-height:48px;background:#111;color:#fff;border:none;font-size:12px;letter-spacing:1px;cursor:pointer;transition:background .2s;margin-top:4px}
.account-submit:hover{background:#333}
.account-msg{font-size:12px;text-align:center;padding:8px;text-transform:none;letter-spacing:.3px}
.account-msg.success{background:#e8f5e9;color:#2e7d32}
.account-msg.error{background:#ffebee;color:#c62828}
.popup-close{position:absolute;top:12px;right:12px;width:38px;height:38px;border:0;background:transparent;color:#111;font-size:24px;line-height:1}

/* ── LOOKBOOK HEADER ─────────────────────────────────── */
.lookbook-head{padding:48px 28px 24px;background:#fff;border-bottom:1px solid rgba(0,0,0,.08)}
.lookbook-head-inner{max-width:1400px;margin:0 auto}
.lookbook-eyebrow{font-size:10px;letter-spacing:2.4px;color:#888;margin-bottom:10px}
.lookbook-title{margin:0 0 10px;font-size:clamp(32px,5vw,64px);line-height:.95;font-weight:400;letter-spacing:.04em}
.lookbook-subtitle{font-size:11px;letter-spacing:.8px;color:#666;max-width:560px;line-height:1.7;text-transform:none}

/* ── YEAR JUMP ANCHORS ───────────────────────────────── */
.year-jump{display:flex;gap:18px;flex-wrap:wrap;padding:18px 28px;background:#fff;border-bottom:1px solid rgba(0,0,0,.08);align-items:center}
.year-jump-label{font-size:10px;letter-spacing:1.6px;color:#888;margin-right:6px}
.year-jump a{font-size:11px;letter-spacing:1.4px;color:#111;border-bottom:1px solid transparent;padding-bottom:2px;transition:border-color .2s,color .2s}
.year-jump a:hover{border-bottom-color:#111}

/* ── LOOKBOOK PER-YEAR ───────────────────────────────── */
.lookbook-section{background:#f3f3f0;padding:8px 0 32px;flex:1 0 auto;width:100%}
.lookbook-year{position:relative;padding:40px 28px 48px;border-bottom:1px solid rgba(0,0,0,.07)}
.lookbook-year:last-child{border-bottom:none}
.year-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:22px;letter-spacing:.14em}
.year-head h2{margin:0;font-size:clamp(28px,4vw,48px);font-weight:400;letter-spacing:.04em;color:#111;line-height:1}
.year-head .year-title{margin:6px 0 0;font-size:11px;letter-spacing:.8px;color:#888;text-transform:none;font-weight:400}
.year-head .count{font-size:10px;letter-spacing:1.2px;color:#888}

.photo-strip{display:flex;align-items:flex-start;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;-webkit-overflow-scrolling:touch;padding:4px 0 24px;scrollbar-width:none}
.photo-strip::-webkit-scrollbar{display:none}
.photo-tile{flex:0 0 auto;height:480px;max-width:min(80vw,480px);overflow:hidden;background:#fff;scroll-snap-align:start;position:relative;display:flex;align-items:center;justify-content:center;border-radius:6px;box-shadow:0 1px 8px rgba(0,0,0,.05)}
.photo-tile img{width:auto;height:100%;max-width:100%;object-fit:contain;object-position:center;transition:transform .4s ease,opacity .3s ease}
.photo-tile:hover img{transform:scale(1.03)}

.scroll-arrow{position:absolute;top:50%;transform:translateY(calc(-50% + 12px));width:44px;height:44px;border:1px solid #111;background:rgba(255,255,255,.95);color:#111;font-size:14px;cursor:pointer;z-index:5;display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s}
.scroll-arrow:hover{background:#111;color:#fff}
.scroll-arrow--left{left:18px}
.scroll-arrow--right{right:18px}
.scroll-arrow[disabled]{opacity:.25;cursor:not-allowed;background:rgba(255,255,255,.95);color:#111}

.empty-state{padding:120px 28px;text-align:center;color:#bbb;font-size:13px;letter-spacing:.6px;background:#fff}
.empty-state .icon{font-size:36px;margin-bottom:14px;opacity:.5;display:block}

/* ── TOAST ───────────────────────────────────────────── */
.toast-container{position:fixed;bottom:24px;right:24px;z-index:200;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{background:#111;color:#fff;padding:12px 18px;font-size:12px;letter-spacing:.5px;opacity:0;transform:translateY(12px);transition:opacity .25s ease,transform .25s ease;pointer-events:none;max-width:280px}
.toast.show{opacity:1;transform:translateY(0)}

/* ── FOOTER ──────────────────────────────────────────── */
.footer{background:#111;color:#fff;padding:64px 28px 28px;margin-top:auto;flex-shrink:0}
.footer-inner{max-width:1400px;margin:0 auto}
.footer-grid{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr 1.2fr;gap:48px;margin-bottom:48px;align-items:flex-start}
.footer-brand-col .footer-brand{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.footer-brand-col .footer-brand img{width:28px;height:28px;object-fit:contain;filter:invert(1);border-radius:50%}
.footer-brand-name{font-size:14px;letter-spacing:2px;font-weight:400}
.footer-tagline{font-size:12px;color:rgba(255,255,255,.55);letter-spacing:.4px;line-height:1.7;text-transform:none;max-width:260px}
.footer-heading{font-size:11px;letter-spacing:1.8px;color:#fff;margin-bottom:18px;font-weight:700}
.footer-links{display:flex;flex-direction:column;gap:12px}
.footer-links a{font-size:12px;letter-spacing:.5px;color:rgba(255,255,255,.65);transition:color .2s}
.footer-links a:hover{color:#fff}
.footer-newsletter{display:flex;flex-direction:column;gap:14px}
.footer-newsletter-text{font-size:12px;color:rgba(255,255,255,.6);letter-spacing:.3px;line-height:1.6;text-transform:none;max-width:280px}
.footer-newsletter-form{display:flex;border-bottom:1px solid rgba(255,255,255,.3);transition:border-color .2s}
.footer-newsletter-form:focus-within{border-bottom-color:#fff}
.footer-newsletter-form input{flex:1;background:transparent;border:none;outline:none;color:#fff;font-size:12px;letter-spacing:.4px;padding:10px 0;text-transform:uppercase}
.footer-newsletter-form input::placeholder{color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.6px;font-size:11px}
.footer-newsletter-form button{background:transparent;border:none;color:#fff;font-size:18px;cursor:pointer;padding:0 0 0 12px;line-height:1;transition:transform .2s}
.footer-newsletter-form button:hover{transform:translateX(3px)}
.footer-bottom{max-width:1400px;margin:0 auto;padding-top:24px;border-top:1px solid rgba(255,255,255,.12);display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.footer-copy{font-size:11px;color:rgba(255,255,255,.45);letter-spacing:.4px;text-transform:none}
.footer-socials{display:flex;gap:20px;align-items:center}
.footer-socials a{font-size:11px;letter-spacing:1px;color:rgba(255,255,255,.55);transition:color .2s;text-transform:uppercase}
.footer-socials a:hover{color:#fff}

/* ── RESPONSIVE ──────────────────────────────────────── */
@media(max-width:1000px){
  .navbar{grid-template-columns:1fr auto 1fr;padding:18px 20px}
  .nav-left,.nav-center,.nav-right{width:100%;justify-content:flex-start}
  .nav-center{justify-content:center;overflow-x:visible}
  .nav-right{justify-content:flex-end}
  .search-form input{width:120px}
  .footer-grid{grid-template-columns:1fr 1fr;gap:36px 24px}
  .footer-brand-col{grid-column:1/-1}
  .photo-tile{height:380px;max-width:88vw}
  .scroll-arrow{width:40px;height:40px}
}
@media(max-width:600px){
  .navbar{padding:8px 14px;gap:8px;min-height:33px}
  .nav-right{gap:10px;font-size:9px}
  .search-form{display:none}
  #openAccountBtn{display:none}
  .brand span{display:none}
  .lookbook-head{padding:32px 18px 18px}
  .lookbook-year,.year-jump{padding-left:18px;padding-right:18px}
  .photo-tile{height:60vh;max-width:92vw}
  .scroll-arrow{display:none}
  .cart-panel{width:100%;max-width:100%}
  .account-popup__dialog{padding:28px 18px 20px}
  .toast-container{right:14px;bottom:14px;left:14px}
  .toast{max-width:100%}
  .footer{padding:48px 18px 24px}
  .footer-grid{grid-template-columns:1fr 1fr;gap:36px 24px;margin-bottom:36px}
  .footer-brand-col{grid-column:1/-1}
  .footer-bottom{flex-direction:column;align-items:center;text-align:center;gap:14px}
  .year-head h2{font-size:24px}
}

/* ── ANIMATIONS ──────────────────────────────────────── */
@keyframes pageFadeIn{from{opacity:0}to{opacity:1}}
@keyframes productFadeIn{from{opacity:0}to{opacity:1}}
  </style>
  <link rel="stylesheet" href="assets/css/apple.css?v=<?= @filemtime(__DIR__ . '/assets/css/apple.css') ?>">
  <link rel="stylesheet" href="assets/css/fx.css?v=<?= @filemtime(__DIR__ . '/assets/css/fx.css') ?>">
  <script src="assets/js/fx.js?v=<?= @filemtime(__DIR__ . '/assets/js/fx.js') ?>" defer></script>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/loader.php')) include_once 'loader.php'; ?>
  <div class="overlay" id="overlay"></div>

  <div class="toast-container" id="toastContainer" aria-live="polite"></div>

  <aside class="menu-panel" id="menuPanel" aria-label="Main menu">
    <div class="menu-head">
      <div class="menu-title">Menu</div>
      <button class="icon-btn" id="closeMenu" aria-label="Close menu">×</button>
    </div>
    <nav class="menu-nav">
      <div class="menu-search-wrap">
        <form class="search-form" action="index.php" method="GET" role="search" style="width:100%">
          <input type="text" name="search" placeholder="SEARCH" aria-label="Search products" style="width:100%">
          <button type="submit" aria-label="Search">&#8594;</button>
        </form>
      </div>

      <button type="button" class="menu-account-btn" id="menuAccountBtn" aria-label="My account">Account</button>

      <div class="dropdown" id="shopDropdown">
        <button class="dropdown-toggle" id="shopAllDropdownBtn" aria-expanded="false">Shop All</button>
        <div class="dropdown-menu">
          <a href="index.php">All Products</a>
          <?php foreach ($categories as $cat): ?>
            <a href="index.php?category=<?= urlencode((string)$cat['slug']) ?>"><?= htmlspecialchars((string)$cat['name']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <a href="lookbook.php" class="active">LookBook</a>
      <a href="#" id="openTermsLink">Terms &amp; Conditions</a>
      <a href="#" id="openFaqLink">Frequently Asked Questions</a>

      <div class="dropdown" id="currencyDropdown">
        <button class="dropdown-toggle" id="currencyDropdownBtn" aria-expanded="false">Currency (<?= $currentCurrency ?>)</button>
        <div class="dropdown-menu">
          <a href="?currency=IDR">IDR</a>
          <a href="?currency=USD">USD</a>
        </div>
      </div>
    </nav>
  </aside>

  <aside class="cart-panel" id="cartPanel" aria-label="Shopping cart">
    <div class="cart-head">
      <div class="cart-title">Cart (<span id="cartCount">0</span>)</div>
      <button class="icon-btn" id="closeCart" aria-label="Close cart">×</button>
    </div>
    <div class="cart-body" id="cartBody">
      <div class="cart-empty">
        <span class="cart-empty-icon">&#128722;</span>
        <span>Your cart is empty</span>
      </div>
    </div>
    <div class="cart-foot" id="cartFoot" style="display:none">
      <div class="cart-total">
        <span>Total</span>
        <strong id="cartTotal">RP 0</strong>
      </div>
      <button class="cart-checkout-btn" id="checkoutBtn">Proceed to Checkout</button>
    </div>
  </aside>

  <div id="accountPopup" class="account-popup" role="dialog" aria-modal="true" aria-labelledby="accountPopupTitle">
    <div class="account-popup__backdrop" id="accountBackdrop"></div>
    <div class="account-popup__dialog">
      <button type="button" class="popup-close" id="closeAccountBtn" aria-label="Close">×</button>
      <div class="account-popup__eyebrow"><?= htmlspecialchars($brandName) ?></div>
      <h2 class="account-popup__title" id="accountPopupTitle">My Account</h2>
      <div class="account-tabs">
        <button class="account-tab active" data-tab="login" aria-selected="true">Sign In</button>
        <button class="account-tab" data-tab="register" aria-selected="false">Register</button>
      </div>
      <div id="tabLogin">
        <div id="loginMsg" class="account-msg" style="display:none"></div>
        <div class="account-form">
          <input type="email" id="loginEmail" placeholder="Email" autocomplete="email">
          <input type="password" id="loginPassword" placeholder="Password" autocomplete="current-password">
          <button class="account-submit" id="loginBtn">Sign In</button>
        </div>
      </div>
      <div id="tabRegister" style="display:none">
        <div id="registerMsg" class="account-msg" style="display:none"></div>
        <div class="account-form">
          <input type="text" id="regName" placeholder="Full Name" autocomplete="name">
          <input type="email" id="regEmail" placeholder="Email" autocomplete="email">
          <input type="password" id="regPassword" placeholder="Password (min. 6 characters)" autocomplete="new-password">
          <input type="password" id="regPasswordConfirm" placeholder="Confirm Password" autocomplete="new-password">
          <button class="account-submit" id="registerBtn">Create Account</button>
        </div>
      </div>
    </div>
  </div>

  <header class="navbar">
    <div class="nav-left">
      <button class="icon-btn menu-icon" id="menuToggle" aria-label="Open menu">&#9776;</button>
    </div>

    <nav class="nav-center" aria-label="Primary navigation">
      <a href="index.php" class="brand">
        <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?> logo" class="brand-logo">
        <span></span>
      </a>
    </nav>

    <div class="nav-right">
      <form class="search-form" action="index.php" method="GET" role="search">
        <label for="searchInput" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">Search products</label>
        <input type="text" id="searchInput" name="search" placeholder="SEARCH" aria-label="Search products">
        <button type="submit" aria-label="Submit search">&#8981;</button>
      </form>
      <button class="icon-btn" id="openAccountBtn" aria-label="My account" style="background:transparent;border:none;font-size:9px;letter-spacing:.5px;cursor:pointer;font-family:inherit;text-transform:uppercase">Account</button>
      <button class="icon-btn" id="openCartBtn" aria-label="Open cart" style="background:transparent;border:none;font-size:9px;letter-spacing:.5px;cursor:pointer;font-family:inherit;text-transform:uppercase">Cart <span id="navCartCount">0</span></button>
    </div>
  </header>

  <section class="lookbook-head" aria-label="Lookbook intro">
    <div class="lookbook-head-inner">
      <p class="lookbook-eyebrow">Lookbook / Archive</p>
      <h1 class="lookbook-title" data-fx="reveal">Visual Journal</h1>
      <p class="lookbook-subtitle">A curated archive of collections, releases, and works — captured season by season.</p>
    </div>
  </section>

  <?php if (count($yearGroups) >= 2): ?>
  <nav class="year-jump" aria-label="Jump to year">
    <span class="year-jump-label">Jump to:</span>
    <?php foreach ($yearGroups as $group): ?>
      <a href="#year-<?= (int) $group['year'] ?>"><?= (int) $group['year'] ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if (!$tableReady): ?>
    <div class="empty-state">
      <span class="icon">○</span>
      Lookbook coming soon.
    </div>
  <?php elseif (!$yearGroups): ?>
    <div class="empty-state">
      <span class="icon">○</span>
      No lookbook photos yet.
    </div>
  <?php else: ?>
    <section class="lookbook-section">
      <?php foreach ($yearGroups as $group): ?>
        <section class="lookbook-year" id="year-<?= (int) $group['year'] ?>" aria-label="Lookbook <?= (int) $group['year'] ?>">
          <div class="year-head">
            <div>
              <h2><?= (int) $group['year'] ?></h2>
              <?php if (!empty($group['title'])): ?>
                <p class="year-title"><?= htmlspecialchars($group['title']) ?></p>
              <?php endif; ?>
            </div>
            <span class="count"><?= (int) $group['count'] ?> PHOTOS</span>
          </div>

          <button type="button" class="scroll-arrow scroll-arrow--left" aria-label="Previous photos">←</button>
          <div class="photo-strip">
            <?php foreach ($group['photos'] as $photo): ?>
              <a class="photo-tile" href="<?= htmlspecialchars((string)$photo['photo_path']) ?>" target="_blank" rel="noopener">
                <img src="<?= htmlspecialchars((string)$photo['photo_path']) ?>" alt="Lookbook <?= (int) $group['year'] ?>" loading="lazy">
              </a>
            <?php endforeach; ?>
          </div>
          <button type="button" class="scroll-arrow scroll-arrow--right" aria-label="Next photos">→</button>
        </section>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <footer class="footer" aria-label="Site footer">
    <div class="footer-inner">
      <div class="footer-grid">
        <div class="footer-brand-col">
          <div class="footer-brand">
            <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?> logo">
            <span class="footer-brand-name"><?= htmlspecialchars($brandName) ?></span>
          </div>
          <p class="footer-tagline">Chrome State Of Mind</p>
        </div>
        <div>
          <p class="footer-heading">Shop</p>
          <div class="footer-links">
            <a href="index.php">All Products</a>
            <?php foreach ($categories as $cat): ?>
              <a href="index.php?category=<?= urlencode((string)$cat['slug']) ?>"><?= htmlspecialchars((string)$cat['name']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <p class="footer-heading">Help</p>
          <div class="footer-links">
            <a href="#" id="footerTermsLink">Terms &amp; Conditions</a>
            <a href="#" id="footerFaqLink">FAQ</a>
          </div>
        </div>
        <div>
          <p class="footer-heading">Contact</p>
          <div class="footer-links" style="text-transform:none;letter-spacing:.2px">
            <a href="mailto:info@albrycia.com">info@albrycia.com</a>
          </div>
        </div>
        <div>
          <p class="footer-heading">Newsletter</p>
          <div class="footer-newsletter">
            <p class="footer-newsletter-text">Sign up for early access to new collections, drops, and limited releases.</p>
            <form class="footer-newsletter-form" onsubmit="event.preventDefault(); this.querySelector('input').value=''; alert('Thanks for subscribing!');">
              <input type="email" placeholder="YOUR EMAIL" aria-label="Email address" required>
              <button type="submit" aria-label="Subscribe">&#8594;</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="footer-bottom">
      <span class="footer-copy">&copy; <?= date('Y') ?> <?= htmlspecialchars($brandName) ?>. All rights reserved. - Development by Cantum Studio</span>
      <div class="footer-socials">
        <a href="https://www.instagram.com/albryciaa/" target="_blank" rel="noopener">Instagram</a>
      </div>
    </div>
  </footer>

  <div id="termsPopup" class="account-popup" role="dialog" aria-modal="true" aria-label="Terms and Conditions">
    <div class="account-popup__backdrop" id="termsBackdrop"></div>
    <div class="account-popup__dialog" style="max-width:640px;max-height:85vh;overflow:hidden">
      <button class="popup-close" id="closeTermsBtn" aria-label="Close">×</button>
      <h2 class="account-popup__title" style="text-align:center">Terms &amp; Conditions</h2>
      <div style="overflow-y:auto;max-height:calc(85vh - 120px);padding-right:10px;color:#444;text-transform:none">
        <div style="margin-bottom:24px"><h3 style="font-size:13px;letter-spacing:.8px;text-transform:uppercase;color:#111;margin-bottom:8px">General</h3><p style="font-size:14px;line-height:1.7">This website is operated by <?= htmlspecialchars($brandName) ?>. By visiting our site and/or purchasing from us, you agree to be bound by the following Terms and Conditions.</p></div>
        <div style="margin-bottom:24px"><h3 style="font-size:13px;letter-spacing:.8px;text-transform:uppercase;color:#111;margin-bottom:8px">Payments and Charges</h3><p style="font-size:14px;line-height:1.7">We currently accept payments via Bank Transfer and other payment methods displayed at checkout. Shipping costs are calculated based on your order total, delivery location, and selected shipping method.</p></div>
        <div style="margin-bottom:24px"><h3 style="font-size:13px;letter-spacing:.8px;text-transform:uppercase;color:#111;margin-bottom:8px">Shipping Information</h3><p style="font-size:14px;line-height:1.7">Orders will be prepared for shipment within 1–2 working days after payment confirmation, unless otherwise stated.</p></div>
        <div style="margin-bottom:24px"><h3 style="font-size:13px;letter-spacing:.8px;text-transform:uppercase;color:#111;margin-bottom:8px">Exchange &amp; Return Policy</h3><p style="font-size:14px;line-height:1.7">All sales are final, unless the item received is defective or incorrect. Requests must be submitted within 3 days of receiving the order.</p></div>
      </div>
    </div>
  </div>

  <div id="faqPopup" class="account-popup" role="dialog" aria-modal="true" aria-label="Frequently Asked Questions">
    <div class="account-popup__backdrop" id="faqBackdrop"></div>
    <div class="account-popup__dialog" style="max-width:640px;max-height:85vh;overflow:hidden">
      <button class="popup-close" id="closeFaqBtn" aria-label="Close">×</button>
      <h2 class="account-popup__title" style="text-align:center">FAQ</h2>
      <div style="overflow-y:auto;max-height:calc(85vh - 120px);padding-right:10px;color:#444;text-transform:none">
        <div style="margin-bottom:24px"><h3 style="font-size:13px;letter-spacing:.8px;text-transform:uppercase;color:#111;margin-bottom:8px">Do I need an account to place an order?</h3><p style="font-size:14px;line-height:1.7">Creating an account is not mandatory. Registering allows you to track orders, view order history, and receive release updates.</p></div>
        <div style="margin-bottom:24px"><h3 style="font-size:13px;letter-spacing:.8px;text-transform:uppercase;color:#111;margin-bottom:8px">How do I place an order?</h3><p style="font-size:14px;line-height:1.7">Select your desired product, choose the available option, and proceed to checkout.</p></div>
        <div style="margin-bottom:24px"><h3 style="font-size:13px;letter-spacing:.8px;text-transform:uppercase;color:#111;margin-bottom:8px">How can I check my order status?</h3><p style="font-size:14px;line-height:1.7">After payment verification, you will receive order and shipping updates through email.</p></div>
        <div style="margin-bottom:24px"><h3 style="font-size:13px;letter-spacing:.8px;text-transform:uppercase;color:#111;margin-bottom:8px">Do you accept returns or exchanges?</h3><p style="font-size:14px;line-height:1.7">All sales are final unless the received item is defective or incorrect.</p></div>
      </div>
    </div>
  </div>

  <script>
  (function () {
    'use strict';

    const CURRENT_CURRENCY = '<?= $currentCurrency ?>';

    const body         = document.body;
    const overlay      = document.getElementById('overlay');
    const menuPanel    = document.getElementById('menuPanel');
    const cartPanel    = document.getElementById('cartPanel');
    const accountPopup = document.getElementById('accountPopup');
    const termsPopup   = document.getElementById('termsPopup');
    const faqPopup     = document.getElementById('faqPopup');

    let cartItems = JSON.parse(localStorage.getItem('ppf_cart') || '[]');

    function showToast(msg) {
      const container = document.getElementById('toastContainer');
      const t = document.createElement('div');
      t.className = 'toast';
      t.textContent = msg;
      container.appendChild(t);
      requestAnimationFrame(() => { requestAnimationFrame(() => { t.classList.add('show'); }); });
      setTimeout(() => {
        t.classList.remove('show');
        setTimeout(() => t.remove(), 300);
      }, 2800);
    }

    function setOverlay(active) {
      if (overlay) overlay.classList.toggle('active', active);
      body.classList.toggle('lock-scroll', active);
    }

    function anyPanelOpen() {
      return (menuPanel && menuPanel.classList.contains('active')) ||
             (cartPanel && cartPanel.classList.contains('active')) ||
             (accountPopup && accountPopup.classList.contains('active')) ||
             (termsPopup && termsPopup.classList.contains('active')) ||
             (faqPopup && faqPopup.classList.contains('active'));
    }

    function closeAll() {
      if (menuPanel)    menuPanel.classList.remove('active');
      if (cartPanel)    cartPanel.classList.remove('active');
      if (accountPopup) accountPopup.classList.remove('active');
      if (termsPopup)   termsPopup.classList.remove('active');
      if (faqPopup)     faqPopup.classList.remove('active');
    }

    function openMenu()  { closeAll(); if (menuPanel)    menuPanel.classList.add('active');    setOverlay(true); }
    function openCart()  { closeAll(); if (cartPanel)    cartPanel.classList.add('active');    setOverlay(true); }
    function openAccount() { closeAll(); if (accountPopup) accountPopup.classList.add('active'); setOverlay(true); }
    function openModal(m) { closeAll(); if (m) m.classList.add('active'); setOverlay(true); }
    function closeModal(m) { if (m) m.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); }

    if (document.getElementById('menuToggle')) document.getElementById('menuToggle').addEventListener('click', openMenu);
    if (document.getElementById('closeMenu'))  document.getElementById('closeMenu').addEventListener('click',  () => { closeModal(menuPanel); });
    if (document.getElementById('openCartBtn')) document.getElementById('openCartBtn').addEventListener('click', openCart);
    if (document.getElementById('closeCart'))  document.getElementById('closeCart').addEventListener('click',  () => { closeModal(cartPanel); });
    if (document.getElementById('openAccountBtn')) document.getElementById('openAccountBtn').addEventListener('click', openAccount);
    if (document.getElementById('menuAccountBtn')) document.getElementById('menuAccountBtn').addEventListener('click', openAccount);
    if (document.getElementById('closeAccountBtn')) document.getElementById('closeAccountBtn').addEventListener('click', () => { closeModal(accountPopup); });
    if (document.getElementById('accountBackdrop')) document.getElementById('accountBackdrop').addEventListener('click', () => { closeModal(accountPopup); });

    /* Dropdowns */
    document.querySelectorAll('.dropdown-toggle').forEach(btn => {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        this.parentElement.classList.toggle('open');
        const expanded = this.getAttribute('aria-expanded') === 'true';
        this.setAttribute('aria-expanded', !expanded);
      });
    });

    /* Info modals */
    ['openTermsLink','footerTermsLink'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('click', e => { e.preventDefault(); openModal(termsPopup); });
    });
    ['openFaqLink','footerFaqLink'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('click', e => { e.preventDefault(); openModal(faqPopup); });
    });
    if (document.getElementById('closeTermsBtn')) document.getElementById('closeTermsBtn').addEventListener('click', () => closeModal(termsPopup));
    if (document.getElementById('termsBackdrop')) document.getElementById('termsBackdrop').addEventListener('click', () => closeModal(termsPopup));
    if (document.getElementById('closeFaqBtn'))   document.getElementById('closeFaqBtn').addEventListener('click',   () => closeModal(faqPopup));
    if (document.getElementById('faqBackdrop'))   document.getElementById('faqBackdrop').addEventListener('click',   () => closeModal(faqPopup));

    if (overlay) overlay.addEventListener('click', () => { closeAll(); setOverlay(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeAll(); setOverlay(false); } });

    /* Account tabs */
    document.querySelectorAll('.account-tab').forEach(tab => {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.account-tab').forEach(t => { t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
        this.classList.add('active'); this.setAttribute('aria-selected','true');
        const target = this.dataset.tab;
        if (document.getElementById('tabLogin'))    document.getElementById('tabLogin').style.display    = target === 'login'    ? '' : 'none';
        if (document.getElementById('tabRegister')) document.getElementById('tabRegister').style.display = target === 'register' ? '' : 'none';
      });
    });

    function setAccountMsg(target, kind, text) {
      const el = document.getElementById(target);
      if (!el) return;
      el.className = 'account-msg ' + kind;
      el.textContent = text;
      el.style.display = 'block';
    }

    function setAccountSubmitDisabled(disabled) {
      const loginBtn    = document.getElementById('loginBtn');
      const registerBtn = document.getElementById('registerBtn');
      if (loginBtn)    { loginBtn.disabled    = disabled; loginBtn.textContent    = disabled ? 'Signing In...'    : 'Sign In'; }
      if (registerBtn) { registerBtn.disabled = disabled; registerBtn.textContent = disabled ? 'Creating Account...' : 'Create Account'; }
    }

    function validateEmail(value) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value); }

    function submitAccount(action) {
      const isLogin = action === 'login';
      const msgTarget = isLogin ? 'loginMsg' : 'registerMsg';

      const name     = (document.getElementById('regName')     || {}).value || '';
      const emailEl  = document.getElementById(isLogin ? 'loginEmail' : 'regEmail');
      const passEl   = document.getElementById(isLogin ? 'loginPassword' : 'regPassword');
      const confirmEl = document.getElementById('regPasswordConfirm');
      const email    = emailEl ? emailEl.value.trim() : '';
      const pass     = passEl  ? passEl.value : '';
      const confirm  = confirmEl ? confirmEl.value : '';

      if (isLogin) {
        if (!email || !pass) return setAccountMsg(msgTarget, 'error', 'Please fill in all fields.');
        if (!validateEmail(email)) return setAccountMsg(msgTarget, 'error', 'Please enter a valid email address.');
      } else {
        if (!name || !email || !pass || !confirm) return setAccountMsg(msgTarget, 'error', 'Please complete all required fields.');
        if (!validateEmail(email)) return setAccountMsg(msgTarget, 'error', 'Please enter a valid email address.');
        if (pass.length < 6) return setAccountMsg(msgTarget, 'error', 'Password must be at least 6 characters.');
        if (pass !== confirm) return setAccountMsg(msgTarget, 'error', 'Password confirmation does not match.');
      }

      setAccountMsg(msgTarget, 'success', isLogin ? 'Signing in...' : 'Creating account...');
      setAccountSubmitDisabled(true);

      const bodyParams = new URLSearchParams();
      bodyParams.set('action', action);
      bodyParams.set('email', email);
      bodyParams.set('password', pass);
      if (!isLogin) {
        bodyParams.set('full_name', name.trim());
        bodyParams.set('confirm_password', confirm);
      }

      fetch('api/auth_api.php', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: bodyParams.toString(),
      })
      .then(r => r.json().catch(() => ({ ok: false, message: 'Unexpected server response.' })).then(data => ({ status: r.status, data })))
      .then(({ data }) => {
        if (!data || data.ok !== true) {
          setAccountMsg(msgTarget, 'error', (data && data.message) ? data.message : 'Something went wrong.');
          setAccountSubmitDisabled(false);
          return;
        }
        const redirect = (data.redirect && typeof data.redirect === 'string') ? data.redirect : 'index.php';
        setAccountMsg(msgTarget, 'success', isLogin ? 'Signed in. Refreshing...' : 'Account created. Refreshing...');
        setTimeout(() => { window.location.href = redirect; }, 450);
      })
      .catch(err => {
        console.error('[auth]', err);
        setAccountMsg(msgTarget, 'error', 'Network error. Please try again.');
        setAccountSubmitDisabled(false);
      });
    }

    if (document.getElementById('loginBtn'))    document.getElementById('loginBtn').addEventListener('click',    () => submitAccount('login'));
    if (document.getElementById('registerBtn')) document.getElementById('registerBtn').addEventListener('click', () => submitAccount('register'));

    /* Cart */
    function saveCart() { localStorage.setItem('ppf_cart', JSON.stringify(cartItems)); }

    function formatCurrencyJS(amount) {
      if (CURRENT_CURRENCY === 'USD') {
        return '$' + parseFloat(amount).toFixed(2);
      }
      return 'RP ' + Math.round(amount).toLocaleString('id-ID');
    }

    function renderCart() {
      const bodyEl = document.getElementById('cartBody');
      const foot   = document.getElementById('cartFoot');
      const count  = cartItems.reduce((s, i) => s + i.qty, 0);
      const total  = cartItems.reduce((s, i) => s + i.price * i.qty, 0);

      if (document.getElementById('cartCount'))    document.getElementById('cartCount').textContent    = count;
      if (document.getElementById('navCartCount')) document.getElementById('navCartCount').textContent = count;

      if (!bodyEl || !foot) return;

      if (cartItems.length === 0) {
        bodyEl.innerHTML = '<div class="cart-empty"><span class="cart-empty-icon">&#128722;</span><span>Your cart is empty</span></div>';
        foot.style.display = 'none';
        return;
      }

      foot.style.display = '';
      if (document.getElementById('cartTotal')) document.getElementById('cartTotal').textContent = formatCurrencyJS(total);

      bodyEl.innerHTML = cartItems.map((item, idx) => `
        <div class="cart-item">
          <img class="cart-item-img" src="${item.thumb}" alt="${item.name}">
          <div>
            <div class="cart-item-name">${item.name}</div>
            <div class="cart-item-price">${formatCurrencyJS(item.price)}</div>
            <div class="cart-item-qty">
              <button aria-label="Decrease" data-action="dec" data-idx="${idx}">−</button>
              <span>${item.qty}</span>
              <button aria-label="Increase" data-action="inc" data-idx="${idx}">+</button>
            </div>
          </div>
          <button class="cart-item-remove" aria-label="Remove" data-action="remove" data-idx="${idx}">×</button>
        </div>
      `).join('');

      bodyEl.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', () => {
          const idx    = parseInt(btn.dataset.idx, 10);
          const action = btn.dataset.action;
          if (action === 'inc') {
            const stockLimit = parseInt(cartItems[idx].stock || '0', 10) || 0;
            if (stockLimit > 0 && cartItems[idx].qty >= stockLimit) {
              showToast('Out Of Stock');
              return;
            }
            cartItems[idx].qty++;
          }
          else if (action === 'dec') { cartItems[idx].qty--; if (cartItems[idx].qty <= 0) cartItems.splice(idx, 1); }
          else if (action === 'remove') { cartItems.splice(idx, 1); }
          saveCart(); renderCart();
        });
      });
    }

    if (document.getElementById('checkoutBtn')) {
      document.getElementById('checkoutBtn').addEventListener('click', () => {
        window.location.href = 'checkout.php';
      });
    }

    renderCart();

    /* Photo strip scroll arrows */
    document.querySelectorAll('.lookbook-year').forEach(section => {
      const strip = section.querySelector('.photo-strip');
      const left  = section.querySelector('.scroll-arrow--left');
      const right = section.querySelector('.scroll-arrow--right');
      if (!strip || !left || !right) return;

      function tileWidth() {
        const tile = strip.querySelector('.photo-tile');
        if (!tile) return 320;
        const styles = window.getComputedStyle(strip);
        const gap = parseFloat(styles.columnGap || styles.gap || '0') || 0;
        return tile.getBoundingClientRect().width + gap;
      }

      function updateArrows() {
        left.disabled  = strip.scrollLeft <= 1;
        right.disabled = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 1;
      }

      left.addEventListener('click',  () => { strip.scrollBy({ left: -tileWidth(), behavior: 'smooth' }); });
      right.addEventListener('click', () => { strip.scrollBy({ left:  tileWidth(), behavior: 'smooth' }); });
      strip.addEventListener('scroll', updateArrows);
      window.addEventListener('resize', updateArrows);
      requestAnimationFrame(updateArrows);
    });

  })();
  </script>
</body>
</html>
