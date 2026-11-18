<?php
session_start();
require_once __DIR__ . '/includes/seo.php';

if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

$currentCurrency = $_SESSION['currency'] ?? 'IDR';

require_once __DIR__ . '/config/database.php';

$search   = isset($_GET['search'])   ? trim((string) $_GET['search'])   : '';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';

if (strlen($search) > 100) { $search = substr($search, 0, 100); }

$brandName       = 'ALBRYCIA';
$brandLogo       = 'assets/images/brand/logo.png';
$customHeroImage = '1212ppf.JPEG';

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

$productColumns     = tableColumns($pdo, 'products');
$hasCategoriesTable = tableExists($pdo, 'categories');
$hasCategoryId      = in_array('category_id',  $productColumns, true);
$hasCategoryText    = in_array('category',      $productColumns, true);
$hasSortOrder       = in_array('sort_order',    $productColumns, true); 

$joinCategory   = $hasCategoriesTable && $hasCategoryId;
$categorySelect = $joinCategory
    ? "c.name AS category_name, c.slug AS category_slug"
    : ($hasCategoryText
        ? "p.category AS category_name, LOWER(REPLACE(p.category, ' ', '-')) AS category_slug"
        : "NULL AS category_name, NULL AS category_slug");

$hasProductSizes = tableExists($pdo, 'product_sizes');
$hasMainStock    = in_array('stock', $productColumns, true);

$stockSelect = "1 AS is_in_stock";
if ($hasProductSizes && $hasMainStock) {
    $stockSelect = "(p.stock > 0 OR IFNULL((SELECT SUM(stock) FROM product_sizes WHERE product_id = p.id AND is_active = 1), 0) > 0) AS is_in_stock";
} elseif ($hasProductSizes) {
    $stockSelect = "(IFNULL((SELECT SUM(stock) FROM product_sizes WHERE product_id = p.id AND is_active = 1), 0) > 0) AS is_in_stock";
} elseif ($hasMainStock) {
    $stockSelect = "(p.stock > 0) AS is_in_stock";
}

$sql = "
    SELECT p.id, p.name, p.slug, p.price_idr, p.price_usd,
           p.discount_percent, p.discount_start_at, p.discount_end_at, p.is_discount_active,
           p.thumbnail, {$categorySelect}, {$stockSelect}
    FROM products p
";
if ($joinCategory) $sql .= " LEFT JOIN categories c ON p.category_id = c.id";
$sql .= " WHERE p.is_active = 1";

$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    if ($joinCategory) {
        $sql .= " AND (p.name LIKE :search1 OR p.slug LIKE :search2 OR c.name LIKE :search3 OR c.slug LIKE :search4)";
        $params[':search1'] = $like;
        $params[':search2'] = $like;
        $params[':search3'] = $like;
        $params[':search4'] = $like;
    } elseif ($hasCategoryText) {
        $sql .= " AND (p.name LIKE :search1 OR p.slug LIKE :search2 OR p.category LIKE :search3)";
        $params[':search1'] = $like;
        $params[':search2'] = $like;
        $params[':search3'] = $like;
    } else {
        $sql .= " AND (p.name LIKE :search1 OR p.slug LIKE :search2)";
        $params[':search1'] = $like;
        $params[':search2'] = $like;
    }
}
if ($category !== '') {
    if ($joinCategory) {
        $sql .= " AND c.slug = :category";
        $params[':category'] = $category;
    } elseif ($hasCategoryText) {
        $sql .= " AND LOWER(REPLACE(p.category, ' ', '-')) = :category";
        $params[':category'] = strtolower($category);
    }
}

if ($hasSortOrder) {
    $sql .= " ORDER BY p.sort_order ASC, p.id DESC";
} else {
    $sql .= " ORDER BY p.id DESC";
}

$products = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $products = array_filter($products, function($product) {
        return (int)($product['is_in_stock'] ?? 1) === 1;
    });
} catch (Throwable $e) {
    error_log('[shop.php] Product query failed: ' . $e->getMessage());
}

$categories = [];
if ($joinCategory) {
    try { $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id ASC"); $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
} elseif ($hasCategoryText) {
    try { $catStmt = $pdo->query("SELECT DISTINCT category AS name, LOWER(REPLACE(category, ' ', '-')) AS slug FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC"); $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
}

$paymentUploaded    = isset($_GET['payment_uploaded']) && $_GET['payment_uploaded'] === '1';
$orderUploaded      = isset($_GET['order'])         ? trim((string) $_GET['order'])        : '';
$invoiceEmailStatus  = isset($_GET['invoice_email']) ? trim((string) $_GET['invoice_email']) : '';

$uploadedOrder = null;
if ($paymentUploaded && $orderUploaded !== '') {
    $popupStmt = $pdo->prepare("SELECT order_number, payment_status FROM orders WHERE order_number = :order_number LIMIT 1");
    $popupStmt->execute(['order_number' => $orderUploaded]);
    $uploadedOrder = $popupStmt->fetch(PDO::FETCH_ASSOC);
}

function isDiscountCurrentlyActive(array $product): bool
{
    if ((int)($product['is_discount_active'] ?? 0) !== 1) return false;
    if ((float)($product['discount_percent'] ?? 0) <= 0)  return false;
    $now = time();
    if (!empty($product['discount_start_at'])) { $s = strtotime((string)$product['discount_start_at']); if ($s !== false && $now < $s) return false; }
    if (!empty($product['discount_end_at']))   { $e = strtotime((string)$product['discount_end_at']);   if ($e !== false && $now > $e) return false; }
    return true;
}

function getBasePrice(array $product, string $currency = 'IDR'): float { 
    return $currency === 'USD' ? (float)($product['price_usd'] ?? 0) : (float)($product['price_idr'] ?? 0); 
}

function getFinalPrice(array $product, string $currency = 'IDR'): float
{
    $base = getBasePrice($product, $currency);
    if (!isDiscountCurrentlyActive($product)) return $base;
    return max($base - ($base * ((float)($product['discount_percent'] ?? 0) / 100)), 0);
}

function formatCurrency(float $amount, string $currency = 'IDR'): string { 
    if ($currency === 'USD') {
        return '$' . number_format($amount, 2, '.', ',');
    }
    return 'RP ' . number_format($amount, 0, ',', '.'); 
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <?php
    seoHead([
        'title'       => $category !== '' ? ucwords(str_replace('-', ' ', $category)) . ' — Shop ' . BRAND_NAME : 'Shop Fragrances — ' . BRAND_NAME,
        'description' => 'Browse all ' . BRAND_NAME . ' Eau de Parfum 50ml fragrances. Unisex, long-lasting scents from Bandung, Indonesia.',
        'path'        => $category !== '' && $search === '' ? 'shop?category=' . rawurlencode($category) : 'shop',
        'noindex'     => $search !== '',
        'schema'      => [
            seoBreadcrumbSchema([['Home', ''], ['Shop', 'shop']]),
            seoItemListSchema(BRAND_NAME . ' fragrances', array_values($products)),
        ],
    ]);
  ?>
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
.brand-logo{width:28px;height:28px;object-fit:contain;flex:0 0 auto;border-radius:50%;}
.menu-icon,.icon-btn{background:transparent;border:0;color:#000;font-size:13px;line-height:1}
.navbar a:hover,.nav-center a.active{opacity:.65}

/* ── DROPDOWN ────────────────────────────────────────── */
.dropdown { position: relative; }
.dropdown-toggle {
  background: none; border: none; font-size: 14px; letter-spacing: .5px; color: #111; 
  text-transform: uppercase; cursor: pointer; display: flex; align-items: center; 
  justify-content: space-between; font-family: inherit; padding: 0; transition: opacity .15s;
}
.dropdown-toggle:hover { opacity: .65; }
.dropdown-menu { 
  display: none; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); 
  background: #fff; border: 1px solid rgba(0,0,0,.08); padding: 10px 0; min-width: 100px;
  flex-direction: column; text-align: center; gap: 10px; box-shadow: 0 4px 12px rgba(0,0,0,.05);
}
.dropdown.open .dropdown-menu { display: flex; animation: pageFadeIn .3s ease forwards; }
.dropdown-menu a { padding: 5px 15px; display: block; font-size: 13px; transition: background .15s; }
.dropdown-menu a:hover { background: #f0f0f0; }
.menu-nav .dropdown-menu { position: relative; left: 0; transform: none; box-shadow: none; border: none; border-left: 1px solid #eee; margin-left: 20px; padding-left: 10px; align-items: flex-start; }

/* ── SEARCH ──────────────────────────────────────────── */
.search-form{display:flex;align-items:center;border-bottom:1px solid #111;padding-bottom:0px}
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
.menu-nav{display:flex;flex-direction:column; padding-top: 10px;}
.menu-nav a, .menu-nav .dropdown-toggle{padding:18px 22px;border-bottom:1px solid rgba(0,0,0,.08);font-size:9px;letter-spacing:.6px;transition:background .15s; width: 100%; text-align: left; box-sizing: border-box;}
.menu-nav a:hover, .menu-nav .dropdown-toggle:hover{background:rgba(0,0,0,.04)}

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

/* ── RESULT BAR ──────────────────────────────────────── */
.result-bar{display:flex;gap:16px;align-items:center;justify-content:space-between;padding:12px 18px;background:#fff;border-top:1px solid rgba(0,0,0,.08);border-bottom:1px solid rgba(0,0,0,.08);font-size:12px;letter-spacing:.8px}
.result-bar a{text-decoration:underline;text-underline-offset:3px}

/* ── PRODUCTS ────────────────────────────────────────── */
.product-section{width:100%;flex:1 0 auto;padding:16px 8px 56px;background:#f6f6f4;overflow:visible}
.section-head{max-width:1888px;margin:0 auto 14px;display:flex;justify-content:space-between;align-items:baseline;padding:6px 4px 10px;font-size:12px;letter-spacing:.8px;border-bottom:1px solid rgba(0,0,0,.08)}
.section-title{font-size:15px;letter-spacing:1.2px}
.section-count{color:#777}
.product-grid{--cols:5;--gap:12px;width:100%;max-width:1888px;margin:0 auto;display:flex;flex-wrap:wrap;justify-content:center;gap:var(--gap)}
.product-card{position:relative;display:block;min-width:0;flex:0 0 calc((100% - (var(--cols) - 1) * var(--gap)) / var(--cols));background:#fff;border-radius:7px;overflow:hidden;box-shadow:0 1px 8px rgba(0,0,0,.05);opacity:0;transform:translateY(24px);animation:productFadeUp .7s ease forwards;transition:box-shadow .25s}
.product-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.1)}
.product-card.sold-out { opacity: 0.6; }
.product-card.sold-out:hover { box-shadow: 0 1px 8px rgba(0,0,0,.05); }

.product-label,.discount-label{position:absolute;top:8px;z-index:3;min-height:18px;display:inline-flex;align-items:center;padding:2px 5px;color:#fff;font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:1;font-weight:700;letter-spacing:0}
.product-label{left:8px;background:#9b9f9f}
.product-label:empty{display:none} 
.discount-label{right:8px;background:#050505}

.product-wishlist{position:absolute;right:10px;bottom:88px;z-index:3;width:32px;height:32px;background:rgba(255,255,255,.9);border:none;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;cursor:pointer;transition:background .2s,transform .15s;color:#555;font-family:Helvetica,Arial,sans-serif}
.product-wishlist:hover{background:#fff;transform:scale(1.1)}
.product-wishlist.active{color:#e53935}
.product-wishlist.active::before{content:'♥'}
.product-wishlist:not(.active)::before{content:'♡'}

.product-image{background:#f3f3f3;height:clamp(310px,25vw,470px);display:flex;align-items:center;justify-content:center;overflow:hidden}
.product-image img{width:100%;height:100%;object-fit:contain;object-position:center;transition:transform .35s ease}
.product-card:hover .product-image img{transform:scale(1.035)}
.product-info{min-height:84px;padding:12px 10px 13px;background:#fff;font-family:Helvetica,Arial,sans-serif;text-transform:uppercase}
.product-card h3{margin:0 0 7px;font-size:10px;line-height:1.2;font-weight:400;letter-spacing:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.product-category{display:none}
.product-price{padding:0;font-size:10px;line-height:1.35;color:#000;letter-spacing:0}
.price-original{display:block;margin:0;color:#777;text-decoration:line-through}
.price-final{display:block;color:#000}
.sold-out-text{font-size:12px;color:#888;margin-top:4px;letter-spacing:.1em;font-weight:700;}
.empty-text{max-width:1888px;margin:0 auto;padding:40px;font-size:18px;background:#fff}

/* ── PAYMENT POPUP ───────────────────────────────────── */
.payment-popup,.info-popup{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;padding:20px;pointer-events:none}
.payment-popup{pointer-events:auto}
.info-popup.active{pointer-events:auto}
.payment-popup__backdrop,.info-popup__backdrop{position:absolute;inset:0;background:rgba(17,17,17,.42);backdrop-filter:blur(5px);opacity:0;transition:opacity .34s ease}
.payment-popup--open .payment-popup__backdrop,.info-popup.active .info-popup__backdrop{opacity:1}
.payment-popup__dialog,.info-popup__dialog{position:relative;width:100%;max-width:640px;max-height:85vh;background:#f8f8f8;padding:34px 28px 28px;box-shadow:0 28px 80px rgba(0,0,0,.18);opacity:0;transform:translateY(24px) scale(.96);transition:opacity .34s ease,transform .34s ease;overflow:hidden}
.payment-popup__dialog{max-width:540px;text-align:center}
.payment-popup--open .payment-popup__dialog,.info-popup.active .info-popup__dialog{opacity:1;transform:translateY(0) scale(1)}
.payment-popup--closing .payment-popup__backdrop,.payment-popup--closing .payment-popup__dialog{opacity:0}
.popup-close{position:absolute;top:12px;right:12px;width:38px;height:38px;border:0;background:transparent;color:#111;font-size:24px;line-height:1}
.payment-popup__eyebrow{margin-bottom:12px;font-size:11px;letter-spacing:1.2px;color:#666}
.payment-popup__title,.info-popup__dialog h2{margin:0 0 18px;font-size:28px;line-height:1.05;font-weight:400;letter-spacing:.8px;text-align:center}
.payment-popup__text{margin:0 auto 18px;max-width:390px;font-size:15px;line-height:1.8;color:#444;text-transform:none}
.payment-popup__meta{margin:0 auto 16px;padding:14px 16px;border:1px solid #d8d8d8;background:#fff;max-width:400px;display:grid;gap:6px}
.payment-popup__actions{display:flex;justify-content:center;gap:12px;flex-wrap:wrap}
.button{min-height:48px;padding:0 20px;border:1px solid #111;background:transparent;color:#111;display:inline-flex;align-items:center;justify-content:center;text-transform:uppercase;letter-spacing:.7px;font-size:12px;transition:background .2s,color .2s}
.button:hover{background:#111;color:#fff}
.button--dark{background:#111;color:#fff}
.button--dark:hover{background:#333}
.info-popup__content{overflow-y:auto;max-height:calc(85vh - 120px);padding-right:10px;color:#444;text-transform:none}
.info-section{margin-bottom:24px}
.info-section h3{font-size:13px;letter-spacing:.8px;text-transform:uppercase;color:#111;margin-bottom:8px}
.info-section p{font-size:14px;line-height:1.7}

/* ── TOAST NOTIFICATION ──────────────────────────────── */
.toast-container{position:fixed;bottom:24px;right:24px;z-index:200;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{background:#111;color:#fff;padding:12px 18px;font-size:12px;letter-spacing:.5px;opacity:0;transform:translateY(12px);transition:opacity .25s ease,transform .25s ease;pointer-events:none;max-width:280px}
.toast.show{opacity:1;transform:translateY(0)}

/* ── FOOTER ──────────────────────────────────────────── */
.footer{background:#111;color:#fff;padding:64px 28px 28px;margin-top:auto;flex-shrink:0}
.footer-inner{max-width:1400px;margin:0 auto}
.footer-grid{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr 1.2fr;gap:48px;margin-bottom:48px;align-items:flex-start}
.footer-brand-col .footer-brand{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.footer-brand-col .footer-brand img{width:28px;height:28px;object-fit:contain;filter:invert(1);border-radius:50%;}
.footer-brand-name{font-size:14px;letter-spacing:2px;font-weight:400}
.footer-tagline{font-size:12px;color:rgba(255,255,255,.55);letter-spacing:.4px;line-height:1.7;text-transform:none;max-width:260px}
.footer-heading{font-size:11px;letter-spacing:1.8px;color:#fff;margin-bottom:18px;font-weight:700}
.footer-links{display:flex;flex-direction:column;gap:12px}
.footer-links a{font-size:12px;letter-spacing:.5px;color:rgba(255,255,255,.65);transition:color .2s}
.footer-links a:hover{color:#fff}

/* Newsletter */
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
.footer-socials a{font-size:11px;letter-spacing:1px;color:rgba(255,255,255,.55);transition:color .2s;text-transform:uppercase;display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px}
.footer-socials a:hover{color:#fff}
.footer-socials svg{display:block}

/* ── RESPONSIVE ──────────────────────────────────────── */
@media(max-width:1000px){
  .navbar{grid-template-columns:1fr auto 1fr;padding:18px 20px;}
  .nav-left, .nav-center, .nav-right {width:100%; justify-content:flex-start;}
  .nav-center {justify-content:center; overflow-x:visible;}
  .nav-right {justify-content:flex-end;}
  .search-form input{width:120px}
}
@media(max-width:600px){
  .navbar{padding:16px 14px;gap:8px}
  .nav-right{gap:10px;font-size:12px}
  .search-form{display:none}
  #openAccountBtn{display:none}
  .brand span { display:none; }
  .payment-popup,.info-popup{padding:14px}
  .payment-popup__dialog,.info-popup__dialog{padding:28px 18px 18px}
  .payment-popup__title,.info-popup__dialog h2{font-size:22px}
  .button{width:100%}
  .cart-panel{width:100%;max-width:100%}
  .account-popup__dialog{padding:28px 18px 20px}
  .toast-container{right:14px;bottom:14px;left:14px}
  .toast{max-width:100%}
  .footer{padding:48px 18px 24px}
  .footer-grid{grid-template-columns:1fr 1fr;gap:36px 24px;margin-bottom:36px}
  .footer-brand-col{grid-column:1/-1}
  .footer-bottom{flex-direction:column;align-items:center;text-align:center;gap:14px}
}
@media(max-width:1280px){.product-grid{--cols:4}}
@media(max-width:900px){.product-grid{--cols:3;--gap:10px}.product-image{height:330px}}
@media(max-width:640px){
  .product-section{padding:10px 6px 42px}
  .section-head{padding:0 2px 4px}
  .product-grid{--cols:2;--gap:7px}
  .product-image{height:255px}
  .product-info{min-height:78px;padding:10px 8px 11px}
  .product-card h3,.product-price{font-size:12px}
  .product-wishlist{bottom:84px;width:28px;height:28px;font-size:14px}
}

/* ── ANIMATIONS ──────────────────────────────────────── */
@keyframes pageFadeIn{from{opacity:0}to{opacity:1}}
@keyframes productFadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
  </style>
  <link rel="stylesheet" href="assets/css/apple.css?v=<?= @filemtime(__DIR__ . '/assets/css/apple.css') ?>">
  <link rel="stylesheet" href="assets/css/fx.css?v=<?= @filemtime(__DIR__ . '/assets/css/fx.css') ?>">
  <script src="assets/js/fx.js?v=<?= @filemtime(__DIR__ . '/assets/js/fx.js') ?>" defer></script>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/loader.php')) include_once 'loader.php'; ?>
  <div class="overlay" id="overlay"></div>

  <div class="toast-container" id="toastContainer" aria-live="polite"></div>

  <?php if ($paymentUploaded): ?>
  <div id="paymentPopup" class="payment-popup payment-popup--open" role="dialog" aria-modal="true" aria-labelledby="paymentPopupTitle">
    <div class="payment-popup__backdrop"></div>
    <div class="payment-popup__dialog">
      <button type="button" class="popup-close" id="closePaymentPopup" aria-label="Tutup popup">×</button>
      <div class="payment-popup__eyebrow"><?= htmlspecialchars($brandName) ?></div>
      <h2 class="payment-popup__title" id="paymentPopupTitle">Invoice Sent to Gmail</h2>
      <p class="payment-popup__text">Your payment proof has been submitted successfully. The invoice has been sent to your email, and your order is now waiting for verification.</p>
      <?php if ($orderUploaded !== ''): ?>
        <div class="payment-popup__meta">
          <span>Order Number</span>
          <strong><?= htmlspecialchars($orderUploaded) ?></strong>
        </div>
      <?php endif; ?>
      <div class="payment-popup__actions">
        <button type="button" id="closePaymentPopupSecondary" class="button">Continue Shopping</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <aside class="menu-panel" id="menuPanel" aria-label="Main menu">
    <div class="menu-head">
      <div class="menu-title">Menu</div>
      <button class="icon-btn" id="closeMenu" aria-label="Tutup menu">×</button>
    </div>
    <nav class="menu-nav">
      <form class="search-form" action="shop.php" method="GET" role="search" style="padding: 18px 22px; border-bottom:1px solid rgba(0,0,0,.08); margin-bottom: 0;">
        <?php if ($category !== ''): ?>
          <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
        <?php endif; ?>
        <input type="text" name="search" placeholder="SEARCH" value="<?= htmlspecialchars($search) ?>" aria-label="Search products" style="width: 100%;">
        <button type="submit" aria-label="Search">&#8594;</button>
      </form>

      <button class="icon-btn" id="menuAccountBtn" aria-label="My account" style="background:transparent;border:none;font-size:9px;letter-spacing:.6px;cursor:pointer;font-family:inherit;text-transform:uppercase;padding:18px 22px;border-bottom:1px solid rgba(0,0,0,.08);width:100%;text-align:left;box-sizing:border-box;display:flex;align-items:center;justify-content:space-between;">Account</button>

      <div class="dropdown">
        <button class="dropdown-toggle" id="shopAllDropdownBtn" aria-expanded="false">Shop All ▾</button>
        <div class="dropdown-menu">
          <a href="shop.php">All Products</a>
          <?php foreach ($categories as $cat): ?>
            <a href="shop.php?category=<?= urlencode((string)$cat['slug']) ?>"><?= htmlspecialchars((string)$cat['name']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <a href="lookbook.php">LookBook</a>
      <a href="#" id="openTermsLink">Terms &amp; Conditions</a>
      <a href="#" id="openFaqLink">Frequently Asked Questions</a>

      <div class="dropdown">
        <button class="dropdown-toggle" id="currencyMenuDropdownBtn" aria-expanded="false">Currency (<?= $currentCurrency ?>) ▾</button>
        <div class="dropdown-menu">
          <a href="?currency=IDR<?= $search !== '' ? '&search='.urlencode($search) : '' ?><?= $category !== '' ? '&category='.urlencode($category) : '' ?>">IDR</a>
          <a href="?currency=USD<?= $search !== '' ? '&search='.urlencode($search) : '' ?><?= $category !== '' ? '&category='.urlencode($category) : '' ?>">USD</a>
        </div>
      </div>
    </nav>
  </aside>

  <aside class="cart-panel" id="cartPanel" aria-label="Shopping cart">
    <div class="cart-head">
      <div class="cart-title">Cart (<span id="cartCount">0</span>)</div>
      <button class="icon-btn" id="closeCart" aria-label="Tutup cart">×</button>
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
      <button type="button" class="popup-close" id="closeAccountBtn" aria-label="Tutup">×</button>
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
      <button class="icon-btn menu-icon" id="menuToggle" aria-label="Buka menu">&#9776;</button>
    </div>

    <nav class="nav-center" aria-label="Primary navigation">
      <a href="index.php" class="brand">
        <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?> logo" class="brand-logo">
        <span></span>
      </a>
    </nav>

    <div class="nav-right">
      <form class="search-form" action="shop.php" method="GET" role="search">
        <label for="searchInput" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">Search products</label>
        <?php if ($category !== ''): ?>
          <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
        <?php endif; ?>
        <input type="text" id="searchInput" name="search" placeholder="SEARCH" value="<?= htmlspecialchars($search) ?>" aria-label="Search products">
        <button type="submit" aria-label="Submit search">&#8981;</button>
      </form>
      <button class="icon-btn" id="openAccountBtn" aria-label="My account" style="background:transparent;border:none;font-size:9px;letter-spacing:.5px;cursor:pointer;font-family:inherit;text-transform:uppercase">Account</button>
      <button class="icon-btn" id="openCartBtn" aria-label="Open cart" style="background:transparent;border:none;font-size:9px;letter-spacing:.5px;cursor:pointer;font-family:inherit;text-transform:uppercase">Cart <span id="navCartCount">0</span></button>
    </div>
  </header>

  <?php if ($search !== '' || $category !== ''): ?>
  <div class="result-bar" role="status">
    <?php if ($category !== ''): ?>
      <span>Category: <strong><?= htmlspecialchars(strtoupper(str_replace('-', ' ', $category))) ?></strong></span>
    <?php endif; ?>
    <?php if ($search !== ''): ?>
      <span>Search: <strong><?= htmlspecialchars(strtoupper($search)) ?></strong></span>
    <?php endif; ?>
    <a href="shop.php">Clear</a>
  </div>
  <?php endif; ?>

  <section class="product-section" id="products" aria-label="Products">
    <div class="section-head">
      <span class="section-title" data-fx="reveal"><?= $category !== '' ? htmlspecialchars(strtoupper(str_replace('-', ' ', $category))) : 'All Products' ?></span>
      <span class="section-count"><?= count($products) ?> <?= count($products) === 1 ? 'item' : 'items' ?></span>
    </div>

    <?php if (!$products): ?>
      <div class="empty-text">No active products yet.</div>
    <?php else: ?>
      <main class="product-grid">
        <?php foreach ($products as $product): ?>
          <?php
            $originalPrice = getBasePrice($product, $currentCurrency);
            $finalPrice    = getFinalPrice($product, $currentCurrency);
            $hasDiscount   = $finalPrice < $originalPrice;
            $isInStock     = (int)($product['is_in_stock'] ?? 1) === 1; 
            $slug          = htmlspecialchars((string)$product['slug']);
            $name          = htmlspecialchars(strtoupper((string)$product['name']));
            $thumb         = htmlspecialchars((string)$product['thumbnail']);
            $productId     = (int)$product['id'];
          ?>
          <div class="product-card <?= !$isInStock ? 'sold-out' : '' ?>" data-product-id="<?= $productId ?>" data-product-name="<?= $name ?>" data-product-price="<?= $finalPrice ?>" data-product-thumb="<?= $thumb ?>">
            <?php if ($hasDiscount && $isInStock): ?>
              <span class="discount-label" aria-label="Sale">Sale</span>
            <?php endif; ?>
            <button class="product-wishlist" aria-label="Add to wishlist" data-id="<?= $productId ?>"></button>
            <a href="product.php?slug=<?= urlencode((string)$product['slug']) ?>" tabindex="-1" style="display:block;" aria-label="<?= $name ?>">
              <div class="product-image">
                <img src="<?= $thumb ?>" alt="<?= $name ?>" loading="lazy">
              </div>
              <div class="product-info">
                <h3><?= $name ?></h3>
                <?php if (!empty($product['category_name'])): ?>
                  <p class="product-category"><?= htmlspecialchars(strtoupper((string)$product['category_name'])) ?></p>
                <?php endif; ?>
                
                <?php if (!$isInStock): ?>
                  <p class="sold-out-text">SOLD OUT</p>
                <?php else: ?>
                  <p class="product-price">
                    <?php if ($hasDiscount): ?>
                      <span class="price-original"><?= htmlspecialchars(formatCurrency($originalPrice, $currentCurrency)) ?></span>
                      <span class="price-final"><?= htmlspecialchars(formatCurrency($finalPrice, $currentCurrency)) ?></span>
                    <?php else: ?>
                      <span class="price-final"><?= htmlspecialchars(formatCurrency($originalPrice, $currentCurrency)) ?></span>
                    <?php endif; ?>
                  </p>
                <?php endif; ?>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </main>
    <?php endif; ?>
  </section>

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
            <a href="shop.php">All Products</a>
            <?php foreach ($categories as $cat): ?>
              <a href="shop.php?category=<?= urlencode((string)$cat['slug']) ?>"><?= htmlspecialchars((string)$cat['name']) ?></a>
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
        <a href="https://www.instagram.com/albryciaa/" target="_blank" rel="noopener" aria-label="Instagram">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
        </a>
      </div>
    </div>
  </footer>

  <div id="termsPopup" class="info-popup" role="dialog" aria-modal="true" aria-label="Terms and Conditions">
    <div class="info-popup__backdrop" id="termsBackdrop"></div>
    <div class="info-popup__dialog">
      <button type="button" class="popup-close" id="closeTermsBtn" aria-label="Tutup">×</button>
      <h2>Terms &amp; Conditions</h2>
      <div class="info-popup__content">
        <div class="info-section"><h3>General</h3><p>This website is operated by <?= htmlspecialchars($brandName) ?>. By visiting our site and/or purchasing from us, you agree to be bound by the following Terms and Conditions.</p></div>
        <div class="info-section"><h3>Payments and Charges</h3><p>We currently accept payments via Bank Transfer and other payment methods displayed at checkout. Shipping costs are calculated based on your order total, delivery location, and selected shipping method.</p></div>
        <div class="info-section"><h3>Shipping Information</h3><p>Orders will be prepared for shipment within 1–2 working days after payment confirmation, unless otherwise stated.</p></div>
        <div class="info-section"><h3>Exchange &amp; Return Policy</h3><p>All sales are final, unless the item received is defective or incorrect. Requests must be submitted within 3 days of receiving the order.</p></div>
      </div>
    </div>
  </div>

  <div id="faqPopup" class="info-popup" role="dialog" aria-modal="true" aria-label="Frequently Asked Questions">
    <div class="info-popup__backdrop" id="faqBackdrop"></div>
    <div class="info-popup__dialog">
      <button type="button" class="popup-close" id="closeFaqBtn" aria-label="Tutup">×</button>
      <h2>FAQ</h2>
      <div class="info-popup__content">
        <div class="info-section"><h3>Do I need an account to place an order?</h3><p>Creating an account is not mandatory. Registering allows you to track orders, view order history, and receive release updates.</p></div>
        <div class="info-section"><h3>How do I place an order?</h3><p>Select your desired product, choose the available option, and proceed to checkout.</p></div>
        <div class="info-section"><h3>How can I check my order status?</h3><p>After payment verification, you will receive order and shipping updates through email.</p></div>
        <div class="info-section"><h3>Do you accept returns or exchanges?</h3><p>All sales are final unless the received item is defective or incorrect.</p></div>
      </div>
    </div>
  </div>

  <script>
  (function () {
    'use strict';

    const CURRENT_CURRENCY = '<?= $currentCurrency ?>';

    const body        = document.body;
    const overlay     = document.getElementById('overlay');
    const menuPanel   = document.getElementById('menuPanel');
    const cartPanel   = document.getElementById('cartPanel');
    const accountPopup = document.getElementById('accountPopup');

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
             (document.getElementById('termsPopup') && document.getElementById('termsPopup').classList.contains('active')) ||
             (document.getElementById('faqPopup') && document.getElementById('faqPopup').classList.contains('active'));
    }

    function closeAll() {
      if (menuPanel) menuPanel.classList.remove('active');
      if (cartPanel) cartPanel.classList.remove('active');
      if (accountPopup) accountPopup.classList.remove('active');
      const tp = document.getElementById('termsPopup'); if (tp) tp.classList.remove('active');
      const fp = document.getElementById('faqPopup');   if (fp) fp.classList.remove('active');
    }

    function openMenu()  { closeAll(); menuPanel.classList.add('active');  setOverlay(true); }
    function openCart()  { closeAll(); cartPanel.classList.add('active');  setOverlay(true); }
    function openAccount() { closeAll(); accountPopup.classList.add('active'); setOverlay(true); }

    document.getElementById('menuToggle').addEventListener('click', openMenu);
    document.getElementById('closeMenu').addEventListener('click', () => { menuPanel.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); });
    document.getElementById('openCartBtn').addEventListener('click', openCart);
    document.getElementById('closeCart').addEventListener('click', () => { cartPanel.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); });
    document.getElementById('openAccountBtn').addEventListener('click', openAccount);
    document.getElementById('menuAccountBtn').addEventListener('click', openAccount);

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

    document.getElementById('loginBtn').addEventListener('click', function () {
      const email = document.getElementById('loginEmail').value.trim();
      const pass  = document.getElementById('loginPassword').value;
      const msg   = document.getElementById('loginMsg');
      if (!email || !pass) { msg.className='account-msg error'; msg.textContent='Please fill in all fields.'; msg.style.display='block'; return; }
      msg.className='account-msg success'; msg.textContent='Redirecting...'; msg.style.display='block';
      setTimeout(() => { window.location.href = 'login.php?email=' + encodeURIComponent(email); }, 800);
    });
    document.getElementById('registerBtn').addEventListener('click', function () {
      const name  = document.getElementById('regName').value.trim();
      const email = document.getElementById('regEmail').value.trim();
      const pass  = document.getElementById('regPassword').value;
      const msg   = document.getElementById('registerMsg');
      if (!name || !email || !pass) { msg.className='account-msg error'; msg.textContent='Please fill in all fields.'; msg.style.display='block'; return; }
      msg.className='account-msg success'; msg.textContent='Redirecting...'; msg.style.display='block';
      setTimeout(() => { window.location.href = 'register.php?email=' + encodeURIComponent(email) + '&name=' + encodeURIComponent(name); }, 800);
    });

    const termsPopup = document.getElementById('termsPopup');
    const faqPopup   = document.getElementById('faqPopup');

    function openModal(modal)  { closeAll(); modal.classList.add('active');  setOverlay(true); }
    function closeModal(modal) { modal.classList.remove('active'); if (!anyPanelOpen()) setOverlay(false); }

    ['openTermsLink','footerTermsLink','menuOpenTermsBtn'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('click', e => { e.preventDefault(); openModal(termsPopup); });
    });
    ['openFaqLink','footerFaqLink','menuOpenFaqBtn'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('click', e => { e.preventDefault(); openModal(faqPopup); });
    });
    document.getElementById('closeTermsBtn').addEventListener('click', () => closeModal(termsPopup));
    document.getElementById('termsBackdrop').addEventListener('click', () => closeModal(termsPopup));
    document.getElementById('closeFaqBtn').addEventListener('click',   () => closeModal(faqPopup));
    document.getElementById('faqBackdrop').addEventListener('click',   () => closeModal(faqPopup));

    /* ── WISHLIST ─────────────────────────────────────── */
    document.querySelectorAll('.product-wishlist').forEach(btn => {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.toggle('active');
        showToast(this.classList.contains('active') ? 'Added to wishlist' : 'Removed from wishlist');
      });
    });

    /* ── CART ──────────────────────────────────────────── */
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
      if (document.getElementById('menuCartCount')) document.getElementById('menuCartCount').textContent = count;

      if (cartItems.length === 0) {
        body.innerHTML = '<div class="cart-empty"><span class="cart-empty-icon">&#128722;</span><span>Your cart is empty</span></div>';
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

    /* ── PAYMENT POPUP ─────────────────────────────────── */
    (function () {
      const popup = document.getElementById('paymentPopup');
      if (!popup) return;
      const closeBtn  = document.getElementById('closePaymentPopup');
      const closeBtn2 = document.getElementById('closePaymentPopupSecondary');
      const backdrop  = popup.querySelector('.payment-popup__backdrop');

      function clearUrlParams() {
        if (!window.history.replaceState) return;
        const url = new URL(window.location.href);
        ['payment_uploaded','order','invoice_email'].forEach(p => url.searchParams.delete(p));
        window.history.replaceState({}, document.title, url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : ''));
      }
      function closePopup() {
        popup.classList.remove('payment-popup--open');
        popup.classList.add('payment-popup--closing');
        setTimeout(() => { popup.remove(); clearUrlParams(); }, 250);
      }
      if (closeBtn)  closeBtn.addEventListener('click',  closePopup);
      if (closeBtn2) closeBtn2.addEventListener('click', closePopup);
      if (backdrop)  backdrop.addEventListener('click',  closePopup);
    })();

  })();
  </script>
</body>
</html>
