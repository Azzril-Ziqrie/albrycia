<?php
session_start();
require_once __DIR__ . '/includes/seo.php';

// Tangkap pilihan currency dari URL
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

// Default ke IDR jika belum ada session
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

require_once __DIR__ . '/config/database.php';

$search   = isset($_GET['search'])   ? trim((string) $_GET['search'])   : '';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';

// Batasi panjang search supaya tidak bikin URL/Query panjang & tidak rentan error
if (strlen($search) > 100) { $search = substr($search, 0, 100); }

$brandName       = 'ALBRYCIA';
$brandLogo       = '11newlogo.PNG';

/* ── helpers ─────────────────────────────────────────── */
function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function tableColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        return array_map(static fn($row) => $row['Field'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

/* ── schema detection ────────────────────────────────── */
$productColumns     = tableColumns($pdo, 'products');
$hasCategoriesTable = tableExists($pdo, 'categories');
$hasCategoryId      = in_array('category_id',  $productColumns, true);
$hasCategoryText    = in_array('category',      $productColumns, true);

$joinCategory   = $hasCategoriesTable && $hasCategoryId;

/* ── categories (untuk menu navbar) ──────────────────── */
$categories = [];
if ($joinCategory) {
    try { $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id ASC"); $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
} elseif ($hasCategoryText) {
    try { $catStmt = $pdo->query("SELECT DISTINCT category AS name, LOWER(REPLACE(category, ' ', '-')) AS slug FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC"); $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php seoHead([
      'title'       => 'Terms & Conditions — ' . BRAND_NAME,
      'description' => 'Terms & Conditions, payments, shipping, privacy and return policy for ' . BRAND_NAME . ' (albrycia.com).',
      'path'        => 'legal',
  ]); ?>
  <style>
/* ── reset (bawaan index.php) ────────────────────────── */
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:#fff;color:#111;font-family:Helvetica,Arial,sans-serif;text-transform:uppercase;animation:pageFadeIn .4s ease forwards;letter-spacing:.04em}
body.lock-scroll{overflow:hidden}
a{color:inherit;text-decoration:none}
img{display:block;width:100%}
button,input{font:inherit}
button{cursor:pointer}

/* ── NAVBAR ──────────────────────────────────────────── */
.navbar{width:100%;height:44px;background:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:60}
.nav-left{display:flex;align-items:center;gap:0}
.brand-mark img { height: 32px; width: auto; display: block; border-radius: 50%; }
.nav-center{position:absolute;left:50%;transform:translateX(-50%);display:flex;align-items:center}
.nav-right{display:flex;align-items:center;gap:28px;margin-left:auto}
.brand-mark{font-size:22px;font-weight:300;letter-spacing:.08em;color:#111;line-height:1}
.menu-icon-wrap{width:28px;height:12px;display:flex;flex-direction:column;justify-content:center;gap:5px;cursor:pointer;background:none;border:none}
.menu-icon-wrap span{display:block;height:1px;background:#111;width:100%}
.nav-btn{background:none;border:none;font-size:10px;letter-spacing:.12em;color:#111;cursor:pointer;text-transform:uppercase}
.nav-count{font-size:10px;letter-spacing:.08em}

/* ── SEARCH ──────────────────────────────────────────── */
.search-form{display:flex;align-items:center;gap:4px}
.search-form input{background:transparent;border:none;outline:none;color:#111;width:80px;font-size:10px;text-transform:uppercase;letter-spacing:.08em}
.search-form input::placeholder{color:#aaa}
.search-form button{background:transparent;border:none;color:#111;font-size:12px;line-height:1;padding:0}

/* ── OVERLAY + MENU ──────────────────────────────────── */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.15);opacity:0;visibility:hidden;transition:opacity .2s,visibility .2s;z-index:70}
.overlay.active{opacity:1;visibility:visible}
.menu-panel{position:fixed;top:0;left:0;width:300px;height:100vh;background:#fff;transform:translateX(-100%);transition:transform .25s ease;z-index:80;display:flex;flex-direction:column}
.menu-panel.active{transform:translateX(0)}
.menu-head{height:44px;display:flex;align-items:center;justify-content:flex-end;padding:0 28px;flex-shrink:0}
.menu-close{background:none;border:none;font-size:24px;color:#111;line-height:1;font-weight:300;cursor:pointer}
.menu-body{display:flex;flex-direction:column;flex:1;padding:0 28px 40px;overflow-y:auto}

.menu-search-form{display:flex;align-items:center;border-bottom:1px solid #111;padding-bottom:8px;margin-bottom:32px;margin-top:10px}
.menu-search-form input{width:100%;border:none;outline:none;font-size:11px;text-transform:uppercase;letter-spacing:.08em;background:transparent;color:#111}
.menu-search-form input::placeholder{color:#aaa}
.menu-search-form button{background:transparent;border:none;font-size:16px;color:#111;cursor:pointer;padding:0 0 0 10px;line-height:1}

.menu-nav-primary{display:flex;flex-direction:column;gap:20px;margin-bottom:40px}
.menu-nav-primary a{font-size:10px;letter-spacing:.1em;color:#111;transition:opacity .15s}
.menu-nav-primary a:hover{opacity:.4}

/* DROPDOWN MENU */
.dropdown-toggle {
  background: none; border: none; font-size: 10px; letter-spacing: .1em; color: #111; 
  text-transform: uppercase; cursor: pointer; display: flex; align-items: center; 
  justify-content: space-between; width: 100%; text-align: left; font-family: inherit;
  padding: 0; transition: opacity .15s;
}
.dropdown-toggle:hover { opacity: .4; }
.dropdown-toggle::after { content: '+'; font-size: 10px; font-weight: 300; transition: transform 0.25s ease; }
.dropdown.open .dropdown-toggle::after { transform: rotate(45deg); }
.dropdown-menu { display: none; flex-direction: column; gap: 16px; padding-left: 12px; margin-top: 16px; border-left: 1px solid #eee; margin-left: 4px; }
.dropdown.open .dropdown-menu { display: flex; animation: pageFadeIn .3s ease forwards; }

.menu-nav-secondary{display:flex;flex-direction:column;gap:16px;margin-top:auto;padding-top:20px}
.menu-nav-secondary a{font-size:10px;letter-spacing:.1em;color:#888;transition:color .15s}
.menu-nav-secondary a:hover{color:#111}

/* ── CART SIDEBAR ────────────────────────────────────── */
.cart-panel{position:fixed;top:0;right:0;width:360px;max-width:100%;height:100vh;background:#fff;transform:translateX(100%);transition:transform .25s ease;z-index:80;display:flex;flex-direction:column}
.cart-panel.active{transform:translateX(0)}
.cart-head{height:44px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;flex-shrink:0;border-bottom:1px solid #f0f0f0}
.cart-title{font-size:10px;letter-spacing:.12em}
.cart-body{flex:1;overflow-y:auto;padding:24px 28px}
.cart-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:10px;color:#bbb;font-size:10px;letter-spacing:.08em}
.cart-item{display:grid;grid-template-columns:60px 1fr auto;gap:16px;align-items:center;padding:16px 0;border-bottom:1px solid #f0f0f0}
.cart-item:last-child{border-bottom:none}
.cart-item-img{width:60px;height:76px;object-fit:cover;background:#f4f4f2}
.cart-item-name{font-size:10px;letter-spacing:.06em;margin-bottom:6px;line-height:1.4}
.cart-item-price{font-size:10px;color:#888;letter-spacing:.04em}
.cart-item-qty{display:flex;align-items:center;gap:10px;margin-top:8px}
.cart-item-qty button{width:18px;height:18px;border:1px solid #ddd;background:transparent;font-size:11px;display:flex;align-items:center;justify-content:center}
.cart-item-qty span{font-size:10px;min-width:14px;text-align:center}
.cart-item-remove{background:transparent;border:none;color:#ccc;font-size:14px;cursor:pointer;padding:2px;align-self:flex-start}
.cart-item-remove:hover{color:#111}
.cart-foot{padding:20px 28px;border-top:1px solid #f0f0f0;flex-shrink:0}
.cart-total{display:flex;justify-content:space-between;align-items:baseline;font-size:10px;margin-bottom:16px;letter-spacing:.08em}
.cart-total strong{font-size:12px;letter-spacing:.06em}
.cart-checkout-btn{display:block;width:100%;min-height:44px;background:#111;color:#fff;border:none;font-size:10px;letter-spacing:.14em;cursor:pointer}
.cart-checkout-btn:hover{background:#000}
.cart-checkout-btn:disabled{background:#ddd;cursor:not-allowed}

/* ── ACCOUNT MODAL ───────────────────────────────────── */
.account-popup{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;padding:20px;pointer-events:none}
.account-popup.active{pointer-events:auto}
.account-popup__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.2);opacity:0;transition:opacity .2s}
.account-popup.active .account-popup__backdrop{opacity:1}
.account-popup__dialog{position:relative;width:100%;max-width:380px;background:#fff;padding:40px 36px;opacity:0;transform:translateY(12px);transition:opacity .2s,transform .2s}
.account-popup.active .account-popup__dialog{opacity:1;transform:translateY(0)}
.account-popup__eyebrow{margin-bottom:6px;font-size:9px;letter-spacing:.18em;color:#aaa;text-align:center}
.account-popup__title{margin:0 0 28px;font-size:14px;font-weight:400;letter-spacing:.14em;text-align:center}
.account-tabs{display:flex;border-bottom:1px solid #eee;margin-bottom:24px}
.account-tab{flex:1;padding:10px;font-size:10px;letter-spacing:.1em;background:transparent;border:none;cursor:pointer;border-bottom:1px solid transparent;transition:border-color .15s,color .15s;color:#bbb}
.account-tab.active{border-bottom-color:#111;color:#111}
.account-form{display:flex;flex-direction:column;gap:10px}
.account-form input{width:100%;min-height:40px;padding:0 12px;border:1px solid #e8e8e8;background:#fff;font-size:11px;letter-spacing:.04em;outline:none;transition:border-color .15s;text-transform:none}
.account-form input:focus{border-color:#111}
.account-form input::placeholder{text-transform:uppercase;letter-spacing:.08em;font-size:10px;color:#ccc}
.account-submit{min-height:44px;background:#111;color:#fff;border:none;font-size:10px;letter-spacing:.14em;cursor:pointer;margin-top:4px}
.account-submit:hover{background:#000}
.account-msg{font-size:10px;text-align:center;padding:8px;text-transform:none;letter-spacing:.04em}
.account-msg.success{background:#f0f7f0;color:#2e7d32}
.account-msg.error{background:#fdf0f0;color:#c62828}
.popup-close{position:absolute;top:14px;right:14px;width:32px;height:32px;border:0;background:transparent;color:#111;font-size:20px;font-weight:300;line-height:1}

/* ── TOAST ───────────────────────────────────────────── */
.toast-container{position:fixed;bottom:24px;right:24px;z-index:200;display:flex;flex-direction:column;gap:6px;pointer-events:none}
.toast{background:#111;color:#fff;padding:10px 16px;font-size:10px;letter-spacing:.1em;opacity:0;transform:translateY(8px);transition:opacity .2s,transform .2s;pointer-events:none;max-width:260px}
.toast.show{opacity:1;transform:translateY(0)}

/* ── FOOTER ──────────────────────────────────────────── */
.footer{padding:28px;border-top:1px solid #f0f0f0;margin-top:0;}
.footer-bottom{display:flex;justify-content:center;align-items:center;gap:12px;flex-wrap:wrap}
.footer-copy{font-size:9px;color:#bbb;letter-spacing:.08em;text-transform:none;text-align:center;}

/* ── KHUSUS HALAMAN LEGAL ────────────────────────────── */
body { display: flex; flex-direction: column; min-height: 100vh; }
.legal-wrapper { max-width: 820px; margin: 60px auto 80px; padding: 0 28px; flex-grow: 1; }
.legal-header { text-align: center; margin-bottom: 50px; }
.legal-header h1 { font-size: 14px; font-weight: 400; letter-spacing: 0.14em; }
.legal-section { margin-bottom: 40px; }
.legal-section h2 { font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; margin-bottom: 14px; font-weight: 700; color: #111; }
.legal-section p, .legal-section li { font-size: 11px; line-height: 1.8; letter-spacing: 0.04em; color: #555; text-transform: none; margin-bottom: 12px; }
.legal-section ul { padding-left: 20px; margin-bottom: 12px; }
.legal-version { font-size: 10px; color: #888; margin-top: 60px; text-transform: none; text-align: left; font-style: italic; }

/* ── FOOTER SECONDARY NAV ───────────────────────────────────── */
.footer-secondary {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 16px;
  padding: 24px 28px;
  border-top: 1px solid #f0f0f0;
  font-size: 10px;
  letter-spacing: .1em;
}

.footer-secondary-left,
.footer-secondary-right {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 16px;
}

.footer-secondary a {
  color: #111;
  transition: opacity .15s;
}

.footer-secondary a:hover {
  opacity: 0.6;
}

/* ── RESPONSIVE Bawaan ───────────────────────────────── */
@media(max-width:560px){
  .navbar{padding:0 18px}
  .nav-right .search-form, 
  .nav-right #openAccountBtn { display: none; }
  .footer{padding-left:18px;padding-right:18px}
  .cart-body,.cart-foot,.cart-head{padding-left:18px;padding-right:18px}
  .account-popup__dialog{padding:32px 20px}
  .toast-container{right:12px;bottom:12px;left:12px}
  .toast{max-width:100%}
  .footer-bottom{flex-direction:column;align-items:center;text-align:center;gap:10px}
  .legal-wrapper { margin: 40px auto 60px; padding: 0 18px; flex-grow: 1; }
  .legal-header { margin-bottom: 40px; }
}

@keyframes pageFadeIn{from{opacity:0}to{opacity:1}}
  </style>
  <link rel="stylesheet" href="assets/css/apple.css?v=<?= @filemtime(__DIR__ . '/assets/css/apple.css') ?>">
</head>
<body>
  <?php if (file_exists(__DIR__ . '/loader.php')) include_once 'loader.php'; ?>
  <div class="overlay" id="overlay"></div>

  <div class="toast-container" id="toastContainer" aria-live="polite"></div>

  <aside class="menu-panel" id="menuPanel" aria-label="Main menu">
    <div class="menu-head">
      <button class="menu-close" id="closeMenu" aria-label="Close menu">×</button>
    </div>
    
    <div class="menu-body">
      <form class="menu-search-form" action="index.php" method="GET" role="search">
        <?php if ($category !== ''): ?>
          <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
        <?php endif; ?>
        <input type="text" name="search" placeholder="SEARCH" value="<?= htmlspecialchars($search) ?>" aria-label="Search products">
        <button type="submit" aria-label="Search">&#8594;</button>
      </form>

      <nav class="menu-nav-primary">
        <div class="dropdown" id="shopDropdown">
          <button class="dropdown-toggle" id="shopDropdownBtn" aria-expanded="false">Shop</button>
          <div class="dropdown-menu" id="shopDropdownMenu">
            <a href="shop.php">All</a>
            <?php foreach ($categories as $cat): ?>
              <a href="index.php?category=<?= urlencode((string)$cat['slug']) ?>"><?= htmlspecialchars((string)$cat['name']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <a href="lookbook.php">LookBook</a>
        
        <div class="dropdown" id="currencyDropdown">
          <button class="dropdown-toggle" id="currencyDropdownBtn" aria-expanded="false">Currency (<?= $currentCurrency ?>)</button>
          <div class="dropdown-menu">
            <a href="?currency=IDR<?= $search !== '' ? '&search='.urlencode($search) : '' ?><?= $category !== '' ? '&category='.urlencode($category) : '' ?>">IDR</a>
            <a href="?currency=USD<?= $search !== '' ? '&search='.urlencode($search) : '' ?><?= $category !== '' ? '&category='.urlencode($category) : '' ?>">USD</a>
          </div>
        </div>
      </nav>

      <nav class="menu-nav-secondary">
        <a href="contact.php">CONTACT</a>
        <a href="stockist.php">STOCKIST</a>
        <a href="about.php">ABOUT</a>
        <a href="shipping.php">SHIPPING</a>
        <a href="legal.php">LEGAL</a>
      </nav>
    </div>
  </aside>

  <aside class="cart-panel" id="cartPanel" aria-label="Shopping cart">
    <div class="cart-head">
      <div class="cart-title">Cart (<span id="cartCount">0</span>)</div>
      <button class="nav-btn" id="closeCart" aria-label="Close cart">×</button>
    </div>
    <div class="cart-body" id="cartBody">
      <div class="cart-empty">
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
      <button class="menu-icon-wrap" id="menuToggle" aria-label="Open menu">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div>

    <div class="nav-center">
      <a href="index.php" class="brand-mark">
        <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?>">
      </a>
    </div>

    <div class="nav-right">
      <form class="search-form" action="index.php" method="GET" role="search">
        <input type="text" name="search" placeholder="Search" value="<?= htmlspecialchars($search) ?>" aria-label="Search products">
        <button type="submit" aria-label="Search">&#8981;</button>
      </form>
      <button class="nav-btn" id="openAccountBtn" aria-label="Account">Account</button>
      <button class="nav-btn" id="openCartBtn" aria-label="Cart">Cart <span id="navCartCount">0</span></button>
    </div>
  </header>

  <main class="legal-wrapper">
    <div class="legal-header">
    </div>

    <section class="legal-section">
      <h2>OVERVIEW</h2>
      <p>Welcome to ALBRYCIA. These Terms & Conditions (“Terms”) govern your use of our website, www.albrycia.com (“website”), operated by ALBRYCIA. (“we”, “us”, or “our”), a company registered in West Java, Indonesia. By accessing or using this Site, or purchasing from us, you agree to be bound by the following Terms, including any additional policies referenced herein or available by hyperlink. If you do not agree, you may not access the Site or use any services.</p>
    </section>

    <section class="legal-section">
      <h2>TERMS</h2>
      <p>The following terms of use govern the website albrycia.com. albrycia.com reserves the right to make corrections and changes to the website when necessary without giving prior notice. albrycia.com does not offer any guaranty that the information published on its own website, is in conformity with the laws of the User’s competent jurisdiction. albrycia.com the website of ALBRYCIA, is a protected website according to international Internet standards which, if used correctly, Users can be reassured not to encounter viruses. However, ALBRYCIA declines any responsibility from eventual problems, damages, viruses or risks that the User may incur during the misuse of the website and declines any responsibility from eventual faulty operations of the website due to the deactivation of “cookies” in the User’s browser. Users must accept the terms of use of this legal notice, and must be sure to visit these pages periodically for updates, changes and corrections. By using albrycia.com the user agrees to respect and act upon all the terms of use outlined and all other policies on albrycia.com Users who do not accept these terms are asked to refrain from using the site. We urge users to check back often and stay up to date on the latest updates and legal issues. The use and access of albrycia.com is for personal use only.</p>
    </section>

    <section class="legal-section">
      <h2>PRIVACY</h2>
      <p>This notice contains the terms and legal conditions that govern the web site albrycia.com. Users of albrycia.com accept and agree to respect all the conditions listed in this declaration. If users do not accept these terms, they are asked to refrain from using the website. The access and the use of the website albrycia.com are for personal use only. All details gathered in the process of accessing and registering to albrycia.com services are kept private and secure on our servers. Personal details may only be used for user authentication with personnel agencies and for internal research unless otherwise stated. Credit card and payment details may only be shared with the owners bank for authentication. This information is kept in order to provide users with fast browsing options and assistance. NEWSLETTERS – COLLECTION AND USE PERSONAL DATA In subscribing for a newsletter you agree that ALBRYCIA may provide you information by email about albrycia.com website’s news and contents related to its products and promotions. You can unsubscribe from our mailing list by clicking on the unsubscribe link at the bottom of every marketing email and you will be opted out of all email communications. We only collect the following information: Country – Email address – Gender. We transfer your data to our service providers only for data administration and hosting purposes.</p>
    </section>

    <section class="legal-section">
      <h2>SERVICES OFFERED</h2>
      <p>We provide the following services via our Site:</p>
      <ul>
        <li>Product information and online ordering;</li>
        <li>Order processing and delivery;</li>
        <li>Customer service, returns, and refunds;</li>
        <li>Promotions and marketing communication.</li>
      </ul>
      <p>We reserve the right to modify or discontinue any service or product at any time without notice.</p>
    </section>

    <section class="legal-section">
      <h2>PRODUCT INFORMATION & ACCURACY</h2>
      <p>We strive to ensure product descriptions, images, prices, and availability are accurate. However, we do not guarantee that all information is current, complete, or error-free. We may correct inaccuracies or update information at any time without prior notice.</p>
      <p>We reserve the right to refuse or cancel any order placed for a product with inaccurate information, even after the order has been submitted.</p>
    </section>

    <section class="legal-section">
      <h2>PRICING & PAYMENT</h2>
      <p>Prices are listed in your local currency at checkout (where available) and may include applicable duties and taxes for international orders via Global-e.</p>
      <p>We accept a range of secure payment methods including credit/debit cards, PayPal, and other local options depending on your region. ALBRYCIA does not charge additional processing fees beyond what is shown at checkout.</p>
    </section>

    <section class="legal-section">
      <h2>COOKIES</h2>
      <p>In particular, ALBRYCIA uses Google Analytics, a web analytics service provided by Google inc. (“Google”). Google Analytics uses “cookies”, which are text files that are stored on your computer, to help the website analyse how users use the website. The information generated by the cookie about how you use the website (including your ip address) will be sent to and stored by Google servers in the United States. Google will use this information for the purpose of evaluating your use of the website, compiling reports on website activity for website operators, and providing other services relating to website activity and internet usage. Google may also transfer this information to third parties where required to do so by law, or where said third parties process the information on Google’s behalf. Google will not associate your ip address with any other data held by Google. You may refuse the use of cookies by selecting the appropriate settings on your browser; however, please note that if you do this, you may not be able to use the website’s full features. By using this website, you consent to the processing of your data by Google in the manner and for the purposes set out above.</p>
    </section>

    <section class="legal-section">
      <h2>TEMPORARY SERVICE SUSPENSION</h2>
      <p>We may temporarily suspend access to the website in case of system maintenance, technical updates, server failure, or other reasons beyond our control. Where feasible, we will inform users in advance.</p>
      <p>We are not liable for any damages resulting from such interruptions unless caused by our gross negligence or willful misconduct.</p>
    </section>

    <section class="legal-section">
      <h2>GOOGLE</h2>
      <p>Cookies are small text strings that the sites visited by the user to send to his/her terminal (usually the browser), where they are stored before being sent back to the same websites at the next visit by the same user. While browsing a website, the user can also receive on his/her terminal cookies that are sent by different websites or web server (see “third party”), where some elements can reside (such as, for example, images, maps, sounds, links to specific pages on other domains) on the website that said user is visiting. This cookie policy has the specific purpose of illustrating the types and procedures of use, as well as to to provide guidance on the actions to refuse or delete cookies present on the website, where desired. Technical cookies are those used for the sole purpose of “carrying out the transmission of a communication over an electronic communications network, or as strictly necessary in order to provide an information society service explicitly requested by the subscriber or user” (see article 122(1) of the privacy code). These are not used for further purposes and can be used for divided into browsing or session cookies, which guarantee the normal browsing and use of the website (making it possible, for example, to make a purchase or authenticate oneself to access restricted areas); analytics cookies, similar to technical cookies when used directly by the website operator to collect information, in aggregate form, on the number of users and how they visit the website; functionality cookies, which allow the user to browse according to a set of selected criteria (for example, language or products selected for purchase) in order to improve the service rendered to them. Profiling cookies are designed to create user profiles and are used in order to send advertising messages in line with the preferences he/she shows when browsing the internet. No first party profiling cookies are in use. In particular, while browsing our website, these cookies are used to show you products you might like or similar ones to those you have already looked at, based on your browsing history. The use of these cookies does not normally entail the processing of personal data, but can enable connections to your computer or other devices and tracking of saved data: the cookies connect to the browser installed on your computer or on other devices used while browsing our website. ALBRYCIA uses the following third-party cookies:</p>
    </section>

    <section class="legal-section">
      <h2>HOW TO DISABLE COOKIES</h2>
      <p>browsers are configured to accept cookies. However, most browsers allow you to control and also disable cookies through your browser settings. Please note, however, that disabling browsing or functionality cookies may cause the website to malfunction and/or limit the service we offer.</p>
    </section>

    <section class="legal-section">
      <h2>PERSONAL INFORMATION</h2>
      <p>Personal information provided by the user can be changed and editing by the user in the account section of the site after logging in. We would be happy to provide users with information we have about them via email at contact.</p>
    </section>

    <p class="legal-version">Version of the Terms and Conditions<br>The latest update of the Terms & Conditions was made in January, 2026.</p>
  </main>

  <div class="footer-secondary">
    <div class="footer-secondary-left">
      <a href="contact.php">CONTACT</a>
      <a href="stockist.php">STOCKIST</a>
    </div>
    <div class="footer-secondary-right">
      <a href="shipping.php">SHIPPING &amp; RETURNS</a>
      <a href="legal.php">LEGAL</a>
    </div>
  </div>

  <footer class="footer" aria-label="Site footer">
    <div class="footer-bottom">
      <span class="footer-copy">© <?= date('Y') ?> ALBRYCIA. All rights reserved. • Development by Cantum Studio</span>
    </div>
  </footer>

  <script>
  (function () {
    'use strict';

    const CURRENT_CURRENCY = '<?= $currentCurrency ?>';
    const body         = document.body;
    const overlay      = document.getElementById('overlay');
    const menuPanel    = document.getElementById('menuPanel');
    const cartPanel    = document.getElementById('cartPanel');
    const accountPopup = document.getElementById('accountPopup');

    let cartItems = JSON.parse(localStorage.getItem('ppf_cart') || '[]');

    function setOverlay(active) {
      overlay.classList.toggle('active', active);
      body.classList.toggle('lock-scroll', active);
    }

    function anyPanelOpen() {
      return menuPanel.classList.contains('active') ||
             cartPanel.classList.contains('active') ||
             accountPopup.classList.contains('active');
    }

    function closeAll() {
      menuPanel.classList.remove('active');
      cartPanel.classList.remove('active');
      accountPopup.classList.remove('active');
    }

    function openMenu()  { closeAll(); menuPanel.classList.add('active');  setOverlay(true); }
    function openCart()  { closeAll(); cartPanel.classList.add('active');  setOverlay(true); }
    function openAccount() { closeAll(); accountPopup.classList.add('active'); setOverlay(true); }

    document.getElementById('menuToggle').addEventListener('click', openMenu);
    document.getElementById('closeMenu').addEventListener('click', () => { menuPanel.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); });
    document.getElementById('openCartBtn').addEventListener('click', openCart);
    document.getElementById('closeCart').addEventListener('click', () => { cartPanel.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); });
    document.getElementById('openAccountBtn').addEventListener('click', openAccount);
    
    document.querySelectorAll('.dropdown-toggle').forEach(btn => {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        this.parentElement.classList.toggle('open');
        const expanded = this.getAttribute('aria-expanded') === 'true';
        this.setAttribute('aria-expanded', !expanded);
      });
    });

    document.getElementById('closeAccountBtn').addEventListener('click', () => { accountPopup.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); });
    document.getElementById('accountBackdrop').addEventListener('click', () => { accountPopup.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); });

    overlay.addEventListener('click', () => { closeAll(); setOverlay(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeAll(); setOverlay(false); } });

    document.querySelectorAll('.account-tab').forEach(tab => {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.account-tab').forEach(t => { t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
        this.classList.add('active'); this.setAttribute('aria-selected','true');
        const target = this.dataset.tab;
        document.getElementById('tabLogin').style.display    = target === 'login'    ? '' : 'none';
        document.getElementById('tabRegister').style.display = target === 'register' ? '' : 'none';
      });
    });

    /* ── CART FUNCTIONALITY ────────────────────────────── */
    function saveCart() { localStorage.setItem('ppf_cart', JSON.stringify(cartItems)); }

    function formatCurrencyJS(amount) {
      if (CURRENT_CURRENCY === 'USD') {
        return '$' + parseFloat(amount).toFixed(2);
      }
      return 'RP ' + Math.round(amount).toLocaleString('id-ID');
    }

    function renderCart() {
      const body  = document.getElementById('cartBody');
      const foot  = document.getElementById('cartFoot');
      const count = cartItems.reduce((s, i) => s + i.qty, 0);
      const total = cartItems.reduce((s, i) => s + i.price * i.qty, 0);

      document.getElementById('cartCount').textContent    = count;
      document.getElementById('navCartCount').textContent = count;

      if (cartItems.length === 0) {
        body.innerHTML = '<div class="cart-empty"><span>Your cart is empty</span></div>';
        foot.style.display = 'none';
        return;
      }

      foot.style.display = '';
      document.getElementById('cartTotal').textContent = formatCurrencyJS(total);

      body.innerHTML = cartItems.map((item, idx) => `
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

      body.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', () => {
          const idx    = parseInt(btn.dataset.idx, 10);
          const action = btn.dataset.action;
          if (action === 'inc') { cartItems[idx].qty++; }
          else if (action === 'dec') { cartItems[idx].qty--; if (cartItems[idx].qty <= 0) cartItems.splice(idx, 1); }
          else if (action === 'remove') { cartItems.splice(idx, 1); }
          saveCart(); renderCart();
        });
      });
    }

    document.getElementById('checkoutBtn').addEventListener('click', () => {
      window.location.href = 'checkout.php';
    });

    renderCart();

  })();
  </script>
</body>
</html>