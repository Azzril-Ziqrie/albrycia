<?php
session_start();
require_once __DIR__ . '/includes/seo.php';

// Tangkap pilihan currency dari URL
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

require_once __DIR__ . '/config/database.php';

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
if ($slug === '') { http_response_code(404); exit('Product not found.'); }

$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$search   = isset($_GET['search']) ? trim($_GET['search']) : '';

/* ── brand constants (Diubah ke ALBRYCIA) ────── */
$brandName = 'ALBRYCIA';
$brandLogo = 'assets/images/brand/logo.png';

// Default ke IDR jika belum ada session
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

/* ── helpers (mendukung multi-currency) ──────────────── */
function formatCurrency(float $amount, string $currency = 'IDR'): string
{
    if ($currency === 'USD') {
        return '$' . number_format($amount, 2, '.', ',');
    }
    return 'RP ' . number_format($amount, 0, ',', '.');
}

function tableExistsProd(PDO $pdo, string $table): bool
{
    try { $s = $pdo->prepare("SHOW TABLES LIKE :t"); $s->execute(['t' => $table]); return (bool) $s->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

function columnExistsProd(PDO $pdo, string $table, string $column): bool
{
    try {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $s->execute(['t' => $table, 'c' => $column]);
        return ((int) $s->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/* ── product query ── */
$hasComparePriceIdr = columnExistsProd($pdo, 'products', 'compare_price_idr');
$hasComparePriceUsd = columnExistsProd($pdo, 'products', 'compare_price_usd');
$hasPriceUsd        = columnExistsProd($pdo, 'products', 'price_usd');
$hasColorColumn     = columnExistsProd($pdo, 'products', 'color');
$hasCategorySize    = columnExistsProd($pdo, 'categories', 'has_size');
$hasSizeChart       = columnExistsProd($pdo, 'products', 'size_chart_image');

$hasDiscountPercent = columnExistsProd($pdo, 'products', 'discount_percent');
$hasDiscountStart   = columnExistsProd($pdo, 'products', 'discount_start_at');
$hasDiscountEnd     = columnExistsProd($pdo, 'products', 'discount_end_at');
$hasDiscountActive  = columnExistsProd($pdo, 'products', 'is_discount_active');

$priceUsdSelect     = $hasPriceUsd        ? 'p.price_usd,'              : 'NULL AS price_usd,';
$compareUsdSelect   = $hasComparePriceUsd ? 'p.compare_price_usd,'      : 'NULL AS compare_price_usd,';
$compareSelect      = $hasComparePriceIdr ? 'p.compare_price_idr,'      : 'NULL AS compare_price_idr,';
$colorSelect        = $hasColorColumn     ? 'p.color,'                  : 'NULL AS color,';
$catSizeSelect      = $hasCategorySize    ? 'c.has_size AS category_has_size' : '0 AS category_has_size';
$sizeChartSelect    = $hasSizeChart       ? 'p.size_chart_image,'       : 'NULL AS size_chart_image,';
$discountPercentSelect = $hasDiscountPercent ? 'p.discount_percent,'    : 'NULL AS discount_percent,';
$discountStartSelect   = $hasDiscountStart   ? 'p.discount_start_at,'   : 'NULL AS discount_start_at,';
$discountEndSelect     = $hasDiscountEnd     ? 'p.discount_end_at,'     : 'NULL AS discount_end_at,';
$discountActiveSelect  = $hasDiscountActive  ? 'p.is_discount_active,'  : '0 AS is_discount_active,';

$productStmt = $pdo->prepare(
    "SELECT p.id, p.category_id, p.name, p.slug, {$colorSelect} p.description, p.composition, p.care, p.thumbnail, {$sizeChartSelect}
            p.price_idr, {$priceUsdSelect} {$compareSelect} {$compareUsdSelect}
            {$discountPercentSelect} {$discountStartSelect} {$discountEndSelect} {$discountActiveSelect} p.is_active,
            c.name AS category_name, c.slug AS category_slug, {$catSizeSelect}
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.slug = :slug AND p.is_active = 1
     LIMIT 1"
);
$productStmt->execute(['slug' => $slug]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) { http_response_code(404); exit('Product not found.'); }

$categoryName = $product['category_name'] ?: 'Product';
$categorySlug = strtolower((string)($product['category_slug'] ?: ''));

if ($hasCategorySize) {
    $hasSizeSelector = (int)$product['category_has_size'] === 1;
} else {
    $hasSizeSelector = $categorySlug === 'fashion';
}

/* ── gallery images ──────────────────────────────────── */
$imageStmt = $pdo->prepare(
    'SELECT image_path FROM product_images
     WHERE product_id = :product_id
     ORDER BY sort_order ASC, id ASC'
);
$imageStmt->execute(['product_id' => $product['id']]);
$detailImages = $imageStmt->fetchAll(PDO::FETCH_COLUMN);

$galleryImages = [];
if (!empty($product['thumbnail'])) $galleryImages[] = $product['thumbnail'];
foreach ($detailImages as $p) {
    if ($p !== '' && !in_array($p, $galleryImages, true)) $galleryImages[] = $p;
}
if (empty($galleryImages)) $galleryImages[] = 'assets/images/placeholder.svg';

/* ── sizes ───────────────────────────────────────────── */
$sizeStmt = $pdo->prepare(
    'SELECT ps.id AS product_size_id, s.name, s.sort_order, ps.stock, ps.is_active
     FROM product_sizes ps INNER JOIN sizes s ON s.id=ps.size_id
     WHERE ps.product_id=:pid ORDER BY s.sort_order ASC, s.name ASC'
);
$sizeStmt->execute(['pid' => $product['id']]);
$productSizes = $sizeStmt->fetchAll(PDO::FETCH_ASSOC);

usort($productSizes, function ($a, $b) {
    $nameCmp = strnatcasecmp((string)$a['name'], (string)$b['name']);
    if ($nameCmp !== 0) return $nameCmp;
    return ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
});

$availableSizes = array_values(array_filter($productSizes, fn($s) => (int)$s['is_active']===1 && (int)$s['stock']>0));
$isSoldOut = count($availableSizes) === 0;
$selectedSize = $isSoldOut ? '' : $availableSizes[0]['name'];
$selectedProductSizeId = $isSoldOut ? null : (int)$availableSizes[0]['product_size_id'];

/* ── produk lain (rekomendasi di bawah halaman) ──────── */
$relatedStmt = $pdo->prepare(
    "SELECT id, name, slug, thumbnail, price_idr, price_usd FROM products
     WHERE is_active = 1 AND id <> :id AND thumbnail <> '' ORDER BY id DESC LIMIT 4"
);
$relatedStmt->execute(['id' => $product['id']]);
$relatedProducts = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);

/* ── categories ──────────────────────────────────────── */
$categories = [];
try {
    if (tableExistsProd($pdo, 'categories')) {
        $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id ASC");
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

/* ── pricing ─────────────────────────────────────────── */
function getBasePriceProd(array $product, string $currency = 'IDR'): float
{
    return $currency === 'USD'
        ? (float)($product['price_usd'] ?? 0)
        : (float)($product['price_idr'] ?? 0);
}

function getComparePriceProd(array $product, string $currency = 'IDR'): float
{
    if ($currency === 'USD') {
        return (float)($product['compare_price_usd'] ?? 0);
    }
    return (float)($product['compare_price_idr'] ?? 0);
}

function isDiscountCurrentlyActiveProd(array $product): bool
{
    if ((int)($product['is_discount_active'] ?? 0) !== 1) return false;
    if ((float)($product['discount_percent'] ?? 0) <= 0)  return false;
    $now = time();
    if (!empty($product['discount_start_at'])) { $s = strtotime((string)$product['discount_start_at']); if ($s !== false && $now < $s) return false; }
    if (!empty($product['discount_end_at']))   { $e = strtotime((string)$product['discount_end_at']);   if ($e !== false && $now > $e) return false; }
    return true;
}

function getFinalPriceProd(array $product, string $currency = 'IDR'): float
{
    $base = getBasePriceProd($product, $currency);
    if (!isDiscountCurrentlyActiveProd($product)) return $base;
    return max($base - ($base * ((float)($product['discount_percent'] ?? 0) / 100)), 0);
}

$originalPrice = getBasePriceProd($product, $currentCurrency);
$basePrice     = getFinalPriceProd($product, $currentCurrency);
$comparePrice  = getComparePriceProd($product, $currentCurrency);
$finalPriceIDR = getFinalPriceProd($product, 'IDR');
$finalPriceUSD = getFinalPriceProd($product, 'USD');

$isFlashSaleActive = isDiscountCurrentlyActiveProd($product);
$hasDiscount       = $isFlashSaleActive || ($comparePrice > 0 && $comparePrice > $basePrice);
$comparePriceToShow = $isFlashSaleActive ? $originalPrice : $comparePrice;

$displayPrice        = formatCurrency($basePrice, $currentCurrency);
$displayComparePrice = $hasDiscount ? formatCurrency($comparePriceToShow, $currentCurrency) : null;

$hasColor = $hasColorColumn && trim((string)($product['color'] ?? '')) !== '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <?php
    $seoImages = array_slice($galleryImages, 0, 6);
    seoHead([
        'title'       => $product['name'] . ' — ' . ucwords(strtolower((string)$categoryName)) . ' | ' . BRAND_NAME,
        'description' => seoProductDescription((string)$product['description']),
        'path'        => 'product?slug=' . rawurlencode((string)$product['slug']),
        'image'       => $seoImages[0],
        'ogType'      => 'product',
        'price'       => getFinalPriceProd($product, 'IDR'),
        'schema'      => [
            seoProductSchema([
                'name' => (string)$product['name'], 'slug' => (string)$product['slug'], 'images' => $seoImages,
                'description' => seoProductDescription((string)$product['description']),
                'category' => (string)$categoryName, 'price' => getFinalPriceProd($product, 'IDR'), 'in_stock' => !$isSoldOut,
            ]),
            seoBreadcrumbSchema(array_values(array_filter([
                ['Home', ''], ['Shop', 'shop'],
                $categorySlug ? [(string)$categoryName, 'shop?category=' . rawurlencode((string)$categorySlug)] : null,
                [(string)$product['name'], 'product?slug=' . rawurlencode((string)$product['slug'])],
            ]))),
        ],
    ]);
  ?>
  <style>
/* ── reset ───────────────────────────────────────────── */
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:#f3f3f0;color:#111;font-family:Helvetica,Arial,sans-serif;text-transform:uppercase;letter-spacing:.35px;overflow-x:hidden;animation:pageFadeIn .55s ease forwards}
body.lock-scroll{overflow:hidden}
a{color:inherit;text-decoration:none}
button,input,select{font:inherit}
img{display:block;max-width:100%}

/* ── NAVBAR ──────────────────────────────────────────── */
.navbar{width:100%;min-height:33px;background:#fff;color:#000;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:24px;padding:0 28px;font-size:9px;letter-spacing:.5px;position:sticky;top:0;z-index:60;border-bottom:1px solid rgba(0,0,0,.08)}
.nav-left,.nav-right,.nav-center{display:flex;align-items:center}
.nav-left{gap:12px;justify-content:flex-start}
.nav-center{justify-content:center;gap:34px;white-space:nowrap}
.nav-right{justify-content:flex-end;gap:24px}
.brand{display:inline-flex;align-items:center;gap:10px;font-size:9px;letter-spacing:1.8px}
.brand-logo{width:28px;height:28px;object-fit:contain;flex:0 0 auto;border-radius:50%;}
.menu-icon,.icon-btn{background:transparent;border:0;color:#000;font-size:9px;line-height:1;cursor:pointer}
.navbar a:hover,.nav-center a.active{opacity:.65}
.nav-btn{background:transparent;border:none;font-size:9px;letter-spacing:.5px;cursor:pointer;font-family:inherit;text-transform:uppercase;color:#111;padding:0}
.nav-btn:hover{opacity:.65}

/* ── SEARCH ──────────────────────────────────────────── */
.search-form{display:flex;align-items:center;border-bottom:1px solid #111;padding-bottom:0px}
.search-form input{background:transparent;border:none;outline:none;color:#111;width:160px;font-size:9px;text-transform:uppercase}
.search-form input::placeholder{color:#111}
.search-form button{background:transparent;border:none;color:#111;font-size:9px;line-height:1;cursor:pointer}

/* ── OVERLAY ─────────────────────────────────────────── */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.22);opacity:0;visibility:hidden;transition:opacity .25s ease,visibility .25s ease;z-index:70}
.overlay.active{opacity:1;visibility:visible}

/* ── MENU PANEL ──────────────────────────────────────── */
/* Ditambahkan height: 100dvh untuk adaptasi mobile viewport */
.menu-panel{position:fixed;top:0;left:0;width:340px;max-width:calc(100% - 42px);height:100vh;height:100dvh;background:#fff;transform:translateX(-100%);transition:transform .32s ease;z-index:80;border-right:1px solid rgba(0,0,0,.14);display:flex;flex-direction:column;}
.menu-panel.active{transform:translateX(0)}
.menu-head{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 22px;border-bottom:1px solid rgba(0,0,0,.1);flex-shrink:0}
.menu-title{font-size:13px;letter-spacing:1px}
.menu-nav{display:flex;flex-direction:column;flex:1;overflow-y:auto;}
.menu-nav a, .menu-nav button{text-align:left;background:transparent;border:none;color:#111;padding:18px 22px;border-bottom:1px solid rgba(0,0,0,.08);font-size:9px;letter-spacing:.6px;transition:background .15s;text-transform:uppercase;cursor:pointer;font-family:inherit;}
.menu-nav a:hover, .menu-nav button:hover{background:rgba(0,0,0,.04)}

/* Dropdown Menu (Untuk Currency) */
.dropdown-toggle { display: flex; align-items: center; justify-content: space-between; width: 100%; }
.dropdown-toggle::after { content: '+'; font-size: 14px; font-weight: 300; transition: transform 0.25s ease; }
.dropdown.open .dropdown-toggle::after { transform: rotate(45deg); }
.dropdown-menu { display: none; flex-direction: column; background:#f9f9f9; }
.dropdown.open .dropdown-menu { display: flex; animation: pageFadeIn .3s ease forwards; }
.dropdown-menu a { padding: 14px 22px 14px 34px; border-bottom: 1px solid rgba(0,0,0,.04); font-size: 12px; }

/* ── CART SIDEBAR ────────────────────────────────────── */
/* Ditambahkan height: 100dvh untuk adaptasi mobile viewport */
.cart-panel{position:fixed;top:0;right:0;width:380px;max-width:100vw;height:100vh;height:100dvh;background:#fff;transform:translateX(100%);transition:transform .32s ease;z-index:90;display:flex;flex-direction:column;border-left:1px solid rgba(0,0,0,.1);left:auto;box-shadow:-4px 0 24px rgba(0,0,0,.08)}
.cart-panel.active{transform:translateX(0)}
.cart-head{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;flex-shrink:0;border-bottom:1px solid rgba(0,0,0,.08)}
.cart-title{font-size:14px;letter-spacing:1px}
.cart-body{flex:1;overflow-y:auto;padding:24px 28px; padding-bottom: 30px;}
.cart-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:10px;color:#bbb;font-size:12px;letter-spacing:1px}
.cart-item{display:grid;grid-template-columns:70px 1fr auto;gap:16px;align-items:center;padding:16px 0;border-bottom:1px solid rgba(0,0,0,.06)}
.cart-item:last-child{border-bottom:none}
.cart-item-img{width:70px;height:90px;object-fit:cover;background:#f4f4f2}
.cart-item-name{font-size:12px;letter-spacing:.5px;margin-bottom:6px;line-height:1.4}
.cart-item-price{font-size:12px;color:#555;letter-spacing:.5px}
.cart-item-qty{display:flex;align-items:center;gap:10px;margin-top:8px}
.cart-item-qty button{width:24px;height:24px;border:1px solid #ddd;background:transparent;font-size:14px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.cart-item-qty span{font-size:12px;min-width:16px;text-align:center}
.cart-item-remove{background:transparent;border:none;color:#aaa;font-size:20px;cursor:pointer;padding:2px;align-self:flex-start}
.cart-item-remove:hover{color:#111}
.cart-foot{padding:20px 28px 30px;border-top:1px solid rgba(0,0,0,.08);flex-shrink:0;background:#fff;z-index:10;}
.cart-total{display:flex;justify-content:space-between;align-items:baseline;font-size:12px;margin-bottom:16px;letter-spacing:1px}
.cart-total strong{font-size:15px;letter-spacing:1px}
.cart-checkout-btn{display:block;width:100%;height:52px;background:#111;color:#fff;border:none;font-size:12px;letter-spacing:1px;cursor:pointer;text-transform:uppercase;font-family:inherit;transition:background .2s}
.cart-checkout-btn:hover{background:#333}
.cart-checkout-btn:disabled{background:#ddd;cursor:not-allowed}

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
.account-tab{flex:1;padding:12px;font-size:12px;letter-spacing:.8px;background:transparent;border:none;cursor:pointer;border-bottom:2px solid transparent;transition:border-color .2s,color .2s;color:#888;font-family:inherit;text-transform:uppercase}
.account-tab.active{border-bottom-color:#111;color:#111}
.account-form{display:flex;flex-direction:column;gap:14px}
.account-form input{width:100%;min-height:44px;padding:0 14px;border:1px solid #d0d0d0;background:#fff;font-size:13px;letter-spacing:.3px;outline:none;transition:border-color .2s;text-transform:none}
.account-form input:focus{border-color:#111}
.account-form input::placeholder{text-transform:uppercase;letter-spacing:.5px;font-size:12px;color:#aaa}
.account-submit{min-height:48px;background:#111;color:#fff;border:none;font-size:12px;letter-spacing:1px;cursor:pointer;transition:background .2s;margin-top:4px;font-family:inherit;text-transform:uppercase}
.account-submit:hover{background:#333}
.account-msg{font-size:12px;text-align:center;padding:8px;text-transform:none;letter-spacing:.3px}
.account-msg.success{background:#e8f5e9;color:#2e7d32}
.account-msg.error{background:#ffebee;color:#c62828}
.popup-close{position:absolute;top:12px;right:12px;width:38px;height:38px;border:0;background:transparent;color:#111;font-size:24px;line-height:1;cursor:pointer}

/* ── SIZE CHART MODAL ────────────────────────────────── */
.size-chart-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 999; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: opacity 0.3s ease; }
.size-chart-modal.active { opacity: 1; visibility: visible; }
.size-chart-content { position: relative; max-width: 90%; max-height: 90vh; background: #fff; padding: 20px; border-radius: 8px; text-align: center; }
.size-chart-content img { max-width: 100%; max-height: 80vh; object-fit: contain; }
.close-size-chart { position: absolute; top: -15px; right: -15px; background: #111; color: #fff; border: none; border-radius: 50%; width: 30px; height: 30px; cursor: pointer; font-size: 16px; font-weight: bold; display: flex; align-items: center; justify-content: center;}

/* ── TOAST ───────────────────────────────────────────── */
.toast-container{position:fixed;bottom:24px;right:24px;z-index:200;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{background:#111;color:#fff;padding:12px 18px;font-size:12px;letter-spacing:.5px;opacity:0;transform:translateY(12px);transition:opacity .25s ease,transform .25s ease;max-width:280px}
.toast.show{opacity:1;transform:translateY(0)}

/* ── BREADCRUMB ──────────────────────────────────────── */
.breadcrumb{padding:12px 28px;font-size:11px;letter-spacing:.8px;color:#888;background:#fff;border-bottom:1px solid rgba(0,0,0,.06);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.breadcrumb a{color:#888;transition:color .2s}
.breadcrumb a:hover{color:#111}
.breadcrumb span{color:#bbb}
.breadcrumb strong{color:#111;font-weight:400}


/* ── FOOTER ──────────────────────────────────────────── */
.footer{background:#111;color:#fff;padding:64px 28px 28px}
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

/* ── INFO POPUP ──────────────────────────────────────── */
.info-popup{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;padding:20px;pointer-events:none}
.info-popup.active{pointer-events:auto}
.info-popup__backdrop{position:absolute;inset:0;background:rgba(17,17,17,.42);backdrop-filter:blur(5px);opacity:0;transition:opacity .34s ease}
.info-popup.active .info-popup__backdrop{opacity:1}
.info-popup__dialog{position:relative;width:100%;max-width:640px;max-height:85vh;background:#f8f8f8;padding:34px 28px 28px;box-shadow:0 28px 80px rgba(0,0,0,.18);opacity:0;transform:translateY(24px) scale(.96);transition:opacity .34s ease,transform .34s ease;overflow:hidden}
.info-popup.active .info-popup__dialog{opacity:1;transform:translateY(0) scale(1)}
.info-popup__dialog h2{margin:0 0 18px;font-size:28px;font-weight:400;letter-spacing:.8px;text-align:center}
.info-popup__content{overflow-y:auto;max-height:calc(85vh - 120px);padding-right:10px;color:#444;text-transform:none}
.info-section{margin-bottom:24px}
.info-section h3{font-size:13px;letter-spacing:.8px;text-transform:uppercase;color:#111;margin-bottom:8px}
.info-section p{font-size:14px;line-height:1.7}

/* ── RESPONSIVE ──────────────────────────────────────── */
@media(max-width:1000px){
  .navbar{grid-template-columns:1fr auto 1fr;padding:18px 20px;min-height:33px; position:relative;}
  .nav-left{flex:0 0 auto;justify-content:flex-start}
  .brand.nav-center{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);display:inline-flex;align-items:center}
  .nav-right{flex:0 0 auto;justify-content:flex-end;margin-left:auto; grid-column: 3;}
  
  /* HILANGKAN SEARCH DAN ACCOUNT DI MOBILE */
  .nav-right .search-form,
  .nav-right #openAccountBtn { display: none; }
  
  .breadcrumb{padding:10px 20px}
}
@media(max-width:600px){
  .navbar{padding:8px 14px;gap:8px;min-height:33px}
  .nav-right{gap:10px;font-size:9px}
  .breadcrumb{padding:10px 14px}
  .toast-container{right:14px;bottom:14px;left:14px}
  .toast{max-width:100%}
  .cart-panel{width:340px;max-width:85vw;right:0;left:auto}
  .cart-panel.active{box-shadow:-4px 0 24px rgba(0,0,0,.12)}
  .cart-head{padding:0 18px}
  .cart-body{padding:20px 18px;padding-bottom:24px}
  
  /* Diperbarui agar padding bottom tidak menabrak bar browser di HP */
  .cart-foot{padding:16px 18px calc(24px + env(safe-area-inset-bottom));}
  
  .cart-item{grid-template-columns:64px 1fr auto;gap:12px}
  .cart-item-img{width:64px;height:80px}
  .cart-title{font-size:13px}
  .footer{padding:48px 18px 24px}
  .footer-grid{grid-template-columns:1fr 1fr;gap:36px 24px;margin-bottom:36px}
  .footer-brand-col{grid-column:1/-1}
  .footer-bottom{flex-direction:column;align-items:center;text-align:center;gap:14px}
}

@keyframes pageFadeIn{from{opacity:0}to{opacity:1}}
@keyframes imageReveal{from{opacity:0;transform:translateY(16px) scale(1.015)}to{opacity:1;transform:translateY(0) scale(1)}}
  </style>
  <link rel="stylesheet" href="assets/css/apple.css?v=<?= @filemtime(__DIR__ . '/assets/css/apple.css') ?>">
  <link rel="stylesheet" href="assets/css/pdp.css?v=<?= @filemtime(__DIR__ . '/assets/css/pdp.css') ?>">
  <link rel="stylesheet" href="assets/css/fx.css?v=<?= @filemtime(__DIR__ . '/assets/css/fx.css') ?>">
  <script src="assets/js/fx.js?v=<?= @filemtime(__DIR__ . '/assets/js/fx.js') ?>" defer></script>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/loader.php')) include_once 'loader.php'; ?>

  <div class="overlay" id="overlay"></div>
  <div class="toast-container" id="toastContainer" aria-live="polite"></div>

  <!-- ── SIDE MENU ───────────────────────────────────── -->
  <aside class="menu-panel" id="menuPanel" aria-label="Main menu">
    <div class="menu-head">
      <div class="menu-title">Menu</div>
      <button class="icon-btn" id="closeMenu" aria-label="Tutup menu">×</button>
    </div>
    <div class="menu-nav">
      <a href="shop.php">Shop All</a>
      <?php foreach ($categories as $cat): ?>
        <a href="index.php?category=<?= urlencode((string)$cat['slug']) ?>"><?= htmlspecialchars((string)$cat['name']) ?></a>
      <?php endforeach; ?>
      <a href="lookbook.php">LookBook</a>
      <a href="about.php">About</a>
      
      <!-- Currency Dropdown -->
      <div class="dropdown" id="currencyDropdown">
        <button class="dropdown-toggle" id="currencyDropdownBtn" aria-expanded="false">Currency (<?= $currentCurrency ?>)</button>
        <div class="dropdown-menu">
          <a href="product.php?slug=<?= urlencode($slug) ?><?= $search !== '' ? '&search='.urlencode($search) : '' ?><?= $category !== '' ? '&category='.urlencode($category) : '' ?>&currency=IDR">IDR</a>
          <a href="product.php?slug=<?= urlencode($slug) ?><?= $search !== '' ? '&search='.urlencode($search) : '' ?><?= $category !== '' ? '&category='.urlencode($category) : '' ?>&currency=USD">USD</a>
        </div>
      </div>
    </div>
  </aside>

  <!-- ── CART SIDEBAR ────────────────────────────────── -->
  <aside class="cart-panel" id="cartPanel" aria-label="Shopping cart">
    <div class="cart-head">
      <div class="cart-title">Cart (<span id="cartCount">0</span>)</div>
      <button class="icon-btn" id="closeCart" aria-label="Close cart">×</button>
    </div>
    <div class="cart-body" id="cartBody">
      <div class="cart-empty"><span>Your cart is empty</span></div>
    </div>
    <div class="cart-foot" id="cartFoot" style="display:none">
      <div class="cart-total">
        <span>Total</span>
        <strong id="cartTotal">RP 0</strong>
      </div>
      <button class="cart-checkout-btn" id="checkoutBtn">Proceed to Checkout</button>
    </div>
  </aside>

  <!-- ── ACCOUNT MODAL ───────────────────────────────── -->
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
          <input type="password" id="regPassword" placeholder="Password" autocomplete="new-password">
          <button class="account-submit" id="registerBtn">Create Account</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── TERMS & FAQ POPUP ───────────────────────────── -->
  <div id="termsPopup" class="info-popup" role="dialog" aria-modal="true">
    <div class="info-popup__backdrop" id="termsBackdrop"></div>
    <div class="info-popup__dialog">
      <button class="popup-close" id="closeTermsBtn" aria-label="Tutup">×</button>
      <h2>Terms &amp; Conditions</h2>
      <div class="info-popup__content">
        <div class="info-section"><h3>General</h3><p>This website is operated by Albrycia. By visiting our site and/or purchasing from us, you agree to be bound by the following Terms and Conditions.</p></div>
        <div class="info-section"><h3>Payments and Charges</h3><p>We accept payments via Bank Transfer and other methods shown at checkout. Shipping costs are calculated based on your order total, delivery location, and selected shipping method.</p></div>
        <div class="info-section"><h3>Shipping Information</h3><p>Orders will be prepared within 1–2 working days after payment confirmation.</p></div>
        <div class="info-section"><h3>Exchange &amp; Return Policy</h3><p>All sales are final unless the item received is defective or incorrect. Requests must be submitted within 3 days of receiving the order.</p></div>
      </div>
    </div>
  </div>

  <div id="faqPopup" class="info-popup" role="dialog" aria-modal="true">
    <div class="info-popup__backdrop" id="faqBackdrop"></div>
    <div class="info-popup__dialog">
      <button class="popup-close" id="closeFaqBtn" aria-label="Tutup">×</button>
      <h2>FAQ</h2>
      <div class="info-popup__content">
        <div class="info-section"><h3>Do I need an account to place an order?</h3><p>Creating an account is not mandatory. Registering allows you to track orders and receive updates.</p></div>
        <div class="info-section"><h3>How do I place an order?</h3><p>Select your desired product and proceed to checkout.</p></div>
        <div class="info-section"><h3>How can I check my order status?</h3><p>After payment verification, you will receive order and shipping updates via email.</p></div>
        <div class="info-section"><h3>Do you accept returns or exchanges?</h3><p>All sales are final unless the received item is defective or incorrect.</p></div>
      </div>
    </div>
  </div>

  <!-- ── NAVBAR ──────────────────────────────────────── -->
  <header class="navbar">
    <div class="nav-left">
      <button class="icon-btn menu-icon" id="menuToggle" aria-label="Buka menu">&#9776;</button>
    </div>
    <a href="index.php" class="brand nav-center">
      <!-- Logo di tengah navbar -->
      <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?> logo" class="brand-logo">
    </a>
    <div class="nav-right">
      <form class="search-form" action="index.php" method="GET" role="search">
        <label for="searchInput" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">Search</label>
        <input type="text" id="searchInput" name="search" placeholder="SEARCH" aria-label="Search products">
        <button type="submit" aria-label="Submit search">&#8981;</button>
      </form>
      <button class="nav-btn" id="openAccountBtn">Account</button>
      <button class="nav-btn" id="openCartBtn" aria-label="Cart">Cart <span id="navCartCount">0</span></button>
    </div>
  </header>

  <!-- ── BREADCRUMB ──────────────────────────────────── -->
  <nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="index.php">Home</a>
    <span aria-hidden="true">›</span>
    <?php if ($categorySlug): ?>
      <a href="index.php?category=<?= urlencode($categorySlug) ?>"><?= htmlspecialchars($categoryName) ?></a>
      <span aria-hidden="true">›</span>
    <?php endif; ?>
    <strong aria-current="page"><?= htmlspecialchars($product['name']) ?></strong>
  </nav>

  <!-- ── PRODUCT SHELL ───────────────────────────────── -->
  <main class="pdp">

    <!-- Galeri: foto penuh bertumpuk (carousel geser di mobile) -->
    <section class="pdp-gallery" id="pdpGallery" aria-label="Product gallery">
      <?php foreach ($galleryImages as $i => $imagePath): ?>
        <figure class="pdp-shot">
          <img src="<?= htmlspecialchars($imagePath) ?>"
               alt="<?= htmlspecialchars($product['name']) ?><?= count($galleryImages) > 1 ? ' — ' . ($i + 1) : '' ?>"
               class="main-product-image"<?= $i === 0 ? ' id="mainProductImage" fetchpriority="high"' : ' loading="lazy"' ?> decoding="async">
        </figure>
      <?php endforeach; ?>
      <?php if (count($galleryImages) > 1): ?>
        <div class="pdp-count" id="pdpCount" aria-hidden="true">1 / <?= count($galleryImages) ?></div>
      <?php endif; ?>
    </section>

    <!-- Info -->
    <aside class="pdp-info" aria-label="Product information">
      <div class="pdp-eyebrow"><?= htmlspecialchars($categoryName) ?></div>
      <h1 class="product-title" data-fx="reveal"><?= htmlspecialchars($product['name']) ?></h1>

      <div class="product-price">
        <?php if ($hasDiscount): ?><span class="price-compare"><?= htmlspecialchars($displayComparePrice) ?></span><?php endif; ?>
        <span class="price-current" id="displayPrice"><?= htmlspecialchars($displayPrice) ?></span>
      </div>
      <?php if ($hasColor): ?>
        <div class="meta-row"><span>Color: <?= htmlspecialchars((string)$product['color']) ?></span></div>
      <?php endif; ?>

      <?php if ($hasSizeSelector): ?>
        <div class="option-block">
          <div class="size-header">
            <span class="section-label">Size</span>
            <?php if (!empty($product['size_chart_image'])): ?>
              <button type="button" class="size-chart-link" id="btn-size-chart">Size chart</button>
            <?php endif; ?>
          </div>
          <?php if ($isSoldOut): ?>
            <p class="sold-out-text">Sold out</p>
          <?php else: ?>
            <div class="size-select-wrap">
              <select class="size-select" id="sizeSelect" aria-label="Select size">
                <?php foreach ($availableSizes as $size): ?>
                  <option
                    value="<?= (int)$size['product_size_id'] ?>"
                    data-size="<?= htmlspecialchars($size['name']) ?>"
                    data-stock="<?= (int)$size['stock'] ?>"
                    <?= $selectedProductSizeId === (int)$size['product_size_id'] ? 'selected' : '' ?>
                  ><?= htmlspecialchars($size['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="pdp-buy">
        <div class="qty-wrap" aria-label="Quantity">
          <button type="button" class="qty-btn" id="decreaseQty" aria-label="Kurangi jumlah">−</button>
          <div class="qty-value" id="qtyValue" aria-live="polite">1</div>
          <button type="button" class="qty-btn" id="increaseQty" aria-label="Tambah jumlah">+</button>
        </div>
        <button type="button" class="cart-btn" id="addToCartBtn" <?= $isSoldOut ? 'disabled' : '' ?>>
          <span class="cart-btn__label"><?= $isSoldOut ? 'Sold out' : 'Add to cart' ?></span>
        </button>
      </div>
      <p class="pdp-note">Ships within 1–2 working days after payment confirmation.<br>Pay securely with QRIS, Virtual Account or e-wallet.</p>

      <div class="pdp-details">
        <?php
          $detailSections = [
              ['descPanel',  'Description', (string)$product['description']],
              ['compPanel',  'Composition', (string)($product['composition'] ?? '')],
              ['carePanel',  'Care',        (string)($product['care'] ?? '')],
          ];
        ?>
        <?php foreach ($detailSections as [$panelId, $label, $text]): ?>
          <?php if (trim($text) === '') { continue; } ?>
          <div class="accordion">
            <button type="button" class="accordion-trigger" aria-expanded="false" aria-controls="<?= $panelId ?>">
              <span><?= $label ?></span>
              <span class="accordion-chevron" aria-hidden="true">+</span>
            </button>
            <div class="accordion-panel" id="<?= $panelId ?>">
              <div class="accordion-panel__inner"><p><?= nl2br(htmlspecialchars($text)) ?></p></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </aside>
  </main>

  <?php if ($relatedProducts): ?>
  <section class="pdp-related" aria-label="You may also like">
    <h2>You may also like</h2>
    <div class="pdp-related__grid">
      <?php foreach ($relatedProducts as $rel): ?>
        <?php $relPrice = $currentCurrency === 'USD' ? (float)$rel['price_usd'] : (float)$rel['price_idr']; ?>
        <a class="rel-card" href="product.php?slug=<?= urlencode((string)$rel['slug']) ?>">
          <div class="rel-card__img"><img src="<?= htmlspecialchars((string)$rel['thumbnail']) ?>" alt="<?= htmlspecialchars((string)$rel['name']) ?>" loading="lazy" decoding="async"></div>
          <div class="rel-card__name"><?= htmlspecialchars((string)$rel['name']) ?></div>
          <div class="rel-card__price"><?= htmlspecialchars(formatCurrency($relPrice, $currentCurrency)) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Bar keranjang melayang (mobile): muncul saat tombol utama tak terlihat -->
  <div class="pdp-bar" id="pdpBar" aria-hidden="true">
    <div class="pdp-bar__text">
      <div class="pdp-bar__name"><?= htmlspecialchars($product['name']) ?></div>
      <div class="pdp-bar__price"><?= htmlspecialchars($displayPrice) ?></div>
    </div>
    <button type="button" class="pdp-bar__btn" id="pdpBarBtn" tabindex="-1" <?= $isSoldOut ? 'disabled' : '' ?>><?= $isSoldOut ? 'Sold out' : 'Add to cart' ?></button>
  </div>

  <!-- ── SIZE CHART MODAL ────────────────────────────── -->
  <?php if (!empty($product['size_chart_image'])): ?>
  <div id="modal-size-chart" class="size-chart-modal" role="dialog" aria-modal="true">
      <div class="size-chart-content">
          <button class="close-size-chart" id="close-size-chart" aria-label="Close">&times;</button>
          <img src="<?= htmlspecialchars($product['size_chart_image']) ?>" alt="Size Chart <?= htmlspecialchars($product['name']) ?>">
      </div>
  </div>
  <?php endif; ?>

  <!-- ── FOOTER ──────────────────────────────────────── -->
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
        <a href="https://www.instagram.com/albryciaa/" target="_blank" rel="noopener" aria-label="Instagram">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
        </a>
      </div>
    </div>
  </footer>

  <script>
  (function () {
    'use strict';

    /* ── PHP Variables ── */
    const productId       = <?= (int)$product['id'] ?>;
    const hasSizeSelector = <?= $hasSizeSelector ? 'true' : 'false' ?>;
    const isSoldOut       = <?= $isSoldOut ? 'true' : 'false' ?>;
    const CURRENT_CURRENCY = <?= json_encode($currentCurrency) ?>;

    const prodName  = <?= json_encode($product['name']) ?>;
    const prodSlug  = <?= json_encode($product['slug']) ?>;
    const prodThumb = <?= json_encode($product['thumbnail']) ?>;
    const prodPrice = <?= json_encode((float)$basePrice) ?>;
    
    const prodPriceIDR = <?= json_encode((float)$finalPriceIDR) ?>;
    const prodPriceUSD = <?= json_encode((float)$finalPriceUSD) ?>;

    let selectedSize          = <?= json_encode($selectedSize) ?>;
    let selectedProductSizeId = <?= json_encode($selectedProductSizeId) ?>;
    let selectedStock         = <?= json_encode($selectedProductSizeId ? (int)($availableSizes[0]['stock'] ?? 0) : 0) ?>;
    let quantity              = 1;

    /* ── DOM refs ───────────────────────────────────── */
    const body         = document.body;
    const overlay      = document.getElementById('overlay');
    const menuPanel    = document.getElementById('menuPanel');
    const cartPanel    = document.getElementById('cartPanel');
    const accountPopup = document.getElementById('accountPopup');
    const termsPopup   = document.getElementById('termsPopup');
    const faqPopup     = document.getElementById('faqPopup');
    const addToCartBtn = document.getElementById('addToCartBtn');
    const sizeSelect   = document.getElementById('sizeSelect');
    const mainImg      = document.getElementById('mainProductImage');
    const qtyValueEl   = document.getElementById('qtyValue');

    let cartItems = JSON.parse(localStorage.getItem('ppf_cart') || '[]');

    /* ── UI Helpers ─────────────────────────────────── */
    function showToast(msg) {
      const c = document.getElementById('toastContainer');
      const t = document.createElement('div');
      t.className = 'toast'; t.textContent = msg;
      c.appendChild(t);
      requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
      setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2600);
    }

    function anyOpen() {
      return menuPanel.classList.contains('active') ||
             cartPanel.classList.contains('active') ||
             accountPopup.classList.contains('active') ||
             termsPopup.classList.contains('active') ||
             faqPopup.classList.contains('active');
    }
    function setOverlay(on) {
      overlay.classList.toggle('active', on);
      body.classList.toggle('lock-scroll', on);
    }
    function closeAll()  {
      [menuPanel, cartPanel, accountPopup, termsPopup, faqPopup].forEach(el => el.classList.remove('active'));
    }
    function openMenu()    { closeAll(); menuPanel.classList.add('active');    setOverlay(true); }
    function openCart()    { closeAll(); cartPanel.classList.add('active');    setOverlay(true); }
    function openAccount() { closeAll(); accountPopup.classList.add('active'); setOverlay(true); }

    overlay.addEventListener('click', () => { closeAll(); setOverlay(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeAll(); setOverlay(false); } });

    document.getElementById('menuToggle').addEventListener('click', openMenu);
    document.getElementById('closeMenu').addEventListener('click', () => { menuPanel.classList.remove('active'); if(!anyOpen()) setOverlay(false); });
    
    document.getElementById('openCartBtn').addEventListener('click', openCart);
    document.getElementById('closeCart').addEventListener('click', () => { cartPanel.classList.remove('active'); if(!anyOpen()) setOverlay(false); });

    document.getElementById('openAccountBtn').addEventListener('click', openAccount);
    document.getElementById('closeAccountBtn').addEventListener('click', () => { accountPopup.classList.remove('active'); if(!anyOpen()) setOverlay(false); });
    document.getElementById('accountBackdrop').addEventListener('click', () => { accountPopup.classList.remove('active'); if(!anyOpen()) setOverlay(false); });

    /* ── Dropdown / Acc Tabs ────────────────────────── */
    document.querySelectorAll('.dropdown-toggle').forEach(btn => {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        this.parentElement.classList.toggle('open');
        const expanded = this.getAttribute('aria-expanded') === 'true';
        this.setAttribute('aria-expanded', !expanded);
      });
    });

    document.querySelectorAll('.account-tab').forEach(tab => {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.account-tab').forEach(t => { t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
        this.classList.add('active'); this.setAttribute('aria-selected','true');
        const target = this.dataset.tab;
        document.getElementById('tabLogin').style.display    = target === 'login'    ? '' : 'none';
        document.getElementById('tabRegister').style.display = target === 'register' ? '' : 'none';
      });
    });

    function openModal(m) { closeAll(); m.classList.add('active'); setOverlay(true); }
    function closeModal(m){ m.classList.remove('active'); if(!anyOpen()) setOverlay(false); }

    ['footerTermsLink'].forEach(id => {
      const el = document.getElementById(id); if (el) el.addEventListener('click', e => { e.preventDefault(); openModal(termsPopup); });
    });
    ['footerFaqLink'].forEach(id => {
      const el = document.getElementById(id); if (el) el.addEventListener('click', e => { e.preventDefault(); openModal(faqPopup); });
    });
    document.getElementById('closeTermsBtn').addEventListener('click', () => closeModal(termsPopup));
    document.getElementById('termsBackdrop').addEventListener('click', () => closeModal(termsPopup));
    document.getElementById('closeFaqBtn').addEventListener('click',   () => closeModal(faqPopup));
    document.getElementById('faqBackdrop').addEventListener('click',   () => closeModal(faqPopup));

    /* ── Accordion ──────────────────────────────────── */
    document.querySelectorAll('.accordion-trigger').forEach(trigger => {
      trigger.addEventListener('click', function() {
        const panelId = this.getAttribute('aria-controls');
        const panel = document.getElementById(panelId);
        const isOpen = this.getAttribute('aria-expanded') === 'true';
        
        this.setAttribute('aria-expanded', String(!isOpen));
        this.classList.toggle('open', !isOpen);
        panel.style.maxHeight = isOpen ? null : (panel.scrollHeight + 'px');
      });
    });

    /* ── Selection Logic ────────────────────────────── */
    if (sizeSelect) {
      const initOpt = sizeSelect.options[sizeSelect.selectedIndex];
      if (initOpt) {
          selectedSize = initOpt.dataset.size;
          selectedStock = parseInt(initOpt.dataset.stock || '0', 10) || 0;
      }
      
      sizeSelect.addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        selectedSize = opt ? opt.dataset.size : '';
        selectedProductSizeId = this.value || null;
        selectedStock = parseInt(opt ? opt.dataset.stock : '0', 10) || 0;
      });
    }

    /* ── Quantity Incrementor ───────────────────────── */
    document.getElementById('decreaseQty').addEventListener('click', () => {
      if (quantity > 1) { quantity--; qtyValueEl.textContent = quantity; }
    });
    document.getElementById('increaseQty').addEventListener('click', () => {
      quantity++; qtyValueEl.textContent = quantity;
    });

    function hasSelection() { return !hasSizeSelector || Boolean(selectedProductSizeId); }

    /* ── CART LOGIC ─────────────────────────────────── */
    function saveCart() { localStorage.setItem('ppf_cart', JSON.stringify(cartItems)); }

    function formatCurrencyJS(amount) {
      const validAmount = parseFloat(amount) || 0;
      if (CURRENT_CURRENCY === 'USD') {
        return '$' + validAmount.toFixed(2);
      }
      return 'RP ' + Math.round(validAmount).toLocaleString('id-ID');
    }

    function renderCart() {
      const body  = document.getElementById('cartBody');
      const foot  = document.getElementById('cartFoot');
      const count = cartItems.reduce((s, i) => s + i.qty, 0);
      
      // Hitung total dengan memeriksa kurs yang sedang aktif
      const total = cartItems.reduce((s, i) => {
        let itemPrice = parseFloat(i.price) || 0;
        if (CURRENT_CURRENCY === 'USD' && i.price_usd !== undefined) itemPrice = parseFloat(i.price_usd);
        if (CURRENT_CURRENCY === 'IDR' && i.price_idr !== undefined) itemPrice = parseFloat(i.price_idr);
        return s + (itemPrice * i.qty);
      }, 0);

      document.getElementById('cartCount').textContent    = count;
      document.getElementById('navCartCount').textContent = count;

      if (cartItems.length === 0) {
        body.innerHTML = '<div class="cart-empty"><span>Your cart is empty</span></div>';
        foot.style.display = 'none';
        return;
      }

      foot.style.display = '';
      document.getElementById('cartTotal').textContent = formatCurrencyJS(total);

      // Render setiap item sesuai harga mata uang aktif
      body.innerHTML = cartItems.map((item, idx) => {
        let itemPrice = parseFloat(item.price) || 0;
        if (CURRENT_CURRENCY === 'USD' && item.price_usd !== undefined) itemPrice = parseFloat(item.price_usd);
        if (CURRENT_CURRENCY === 'IDR' && item.price_idr !== undefined) itemPrice = parseFloat(item.price_idr);

        return `
          <div class="cart-item">
            <img class="cart-item-img" src="${item.thumb}" alt="${item.name}">
            <div>
              <div class="cart-item-name">${item.name}</div>
              <div class="cart-item-price">${formatCurrencyJS(itemPrice * item.qty)}</div>
              <div class="cart-item-qty">
                <button aria-label="Decrease" data-action="dec" data-idx="${idx}">−</button>
                <span>${item.qty}</span>
                <button aria-label="Increase" data-action="inc" data-idx="${idx}">+</button>
              </div>
            </div>
            <button class="cart-item-remove" aria-label="Remove" data-action="remove" data-idx="${idx}">×</button>
          </div>
        `;
      }).join('');

      body.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', () => {
          const idx    = parseInt(btn.dataset.idx, 10);
          const action = btn.dataset.action;
          if (action === 'inc') {
            const stockLimit = parseInt(cartItems[idx].stock || '0', 10) || 0;
            if (stockLimit > 0 && cartItems[idx].qty >= stockLimit) {
              showToast('Out Of Stock'); return;
            }
            cartItems[idx].qty++;
          }
          else if (action === 'dec') { cartItems[idx].qty--; if (cartItems[idx].qty <= 0) cartItems.splice(idx, 1); }
          else if (action === 'remove') { cartItems.splice(idx, 1); }
          saveCart(); renderCart();
        });
      });
    }

    document.getElementById('checkoutBtn').addEventListener('click', async () => { 
      const btn = document.getElementById('checkoutBtn');
      const origText = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Preparing Checkout...';

      try {
        const fd = new FormData();
        fd.append('cart_data', JSON.stringify(cartItems));
        await fetch('api/sync-cart.php', { method: 'POST', body: fd });
        window.location.href = 'checkout.php';
      } catch (err) {
        showToast('Failed to prepare checkout.');
        btn.disabled = false;
        btn.textContent = origText;
      }
    });

    async function doAddToCart() {
      if (!hasSelection()) { showToast('Please select an available option first.'); return; }
      if (selectedStock <= 0) { showToast('Stok untuk ukuran ini habis.'); return; }

      const origText = addToCartBtn.querySelector('.cart-btn__label').textContent;
      addToCartBtn.disabled = true;
      addToCartBtn.querySelector('.cart-btn__label').textContent = 'Adding...';

      try {
        const fd = new FormData();
        fd.append('product_id', productId);
        fd.append('quantity', quantity);
        if (selectedProductSizeId) fd.append('product_size_id', selectedProductSizeId);
        await fetch('api/add-to-cart.php', { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} });
      } catch (err) { }

      const itemName = prodName + (selectedSize ? ` - ${selectedSize}` : '');
      const existingIdx = cartItems.findIndex(i => i.id === productId && i.name === itemName);

      if (existingIdx > -1) {
        if (cartItems[existingIdx].qty + quantity > selectedStock) {
          showToast('Stok tersisa hanya ' + selectedStock);
          addToCartBtn.disabled = false;
          addToCartBtn.querySelector('.cart-btn__label').textContent = origText;
          return;
        }
        cartItems[existingIdx].qty += quantity;
      } else {
        cartItems.push({
          id: productId,
          slug: prodSlug,
          size_id: selectedProductSizeId,
          name: itemName,
          thumb: prodThumb,
          price: prodPrice,
          price_idr: prodPriceIDR,
          price_usd: prodPriceUSD,
          qty: quantity,
          stock: selectedStock,
          currency: CURRENT_CURRENCY
        });
      }

      saveCart();
      renderCart();

      setTimeout(() => {
        if (!isSoldOut) addToCartBtn.disabled = false;
        addToCartBtn.querySelector('.cart-btn__label').textContent = origText;
        showToast('Added to cart ✓');
        openCart();
      }, 300);
    }

    addToCartBtn.addEventListener('click', doAddToCart);
    renderCart();

    /* ── Size Chart Modal Logic ─────────────────────── */
    const btnSizeChart = document.getElementById('btn-size-chart');
    const modalSizeChart = document.getElementById('modal-size-chart');
    const closeSizeChart = document.getElementById('close-size-chart');

    if (btnSizeChart && modalSizeChart) {
        btnSizeChart.addEventListener('click', (e) => {
            e.preventDefault();
            modalSizeChart.classList.add('active');
        });
        closeSizeChart.addEventListener('click', () => {
            modalSizeChart.classList.remove('active');
        });
        modalSizeChart.addEventListener('click', (e) => {
            if (e.target === modalSizeChart) {
                modalSizeChart.classList.remove('active');
            }
        });
    }

    /* ── Gallery Thumbnails Swap ────────────────────── */
    document.querySelectorAll('.thumb-card').forEach(thumb => {
      thumb.addEventListener('click', function () {
        if (!mainImg || !this.dataset.image) return;

        document.querySelectorAll('.thumb-card').forEach(t => t.classList.remove('active-thumb'));
        this.classList.add('active-thumb');

        mainImg.style.opacity = '0';
        mainImg.style.transform = 'scale(1.015)';
        mainImg.style.transition = 'opacity .2s ease, transform .2s ease';
        setTimeout(() => {
          mainImg.src = this.dataset.image;
          mainImg.style.opacity = '1';
          mainImg.style.transform = 'scale(1)';
        }, 180);
      });
    });

  })();
  </script>
  <script>
  (function () {
    'use strict';
    /* Deskripsi terbuka sejak awal */
    var first = document.querySelector('.accordion-trigger');
    if (first) { first.click(); }

    /* Foto muncul halus saat terlihat (desktop) */
    var shots = document.querySelectorAll('.pdp-shot');
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
      }, { threshold: .08 });
      shots.forEach(function (el) { io.observe(el); });
    } else { shots.forEach(function (el) { el.classList.add('is-in'); }); }

    /* Penghitung foto di carousel mobile */
    var gallery = document.getElementById('pdpGallery'), count = document.getElementById('pdpCount');
    if (gallery && count) {
      gallery.addEventListener('scroll', function () {
        var i = Math.round(gallery.scrollLeft / gallery.clientWidth) + 1;
        count.textContent = i + ' / ' + shots.length;
      }, { passive: true });
    }

    /* Bar keranjang melayang: tampil hanya saat tombol utama & footer tidak terlihat */
    var bar = document.getElementById('pdpBar'), mainBtn = document.getElementById('addToCartBtn'), barBtn = document.getElementById('pdpBarBtn');
    if (bar && mainBtn && 'IntersectionObserver' in window) {
      var hidden = { main: false, footer: false };
      function update() { var show = !hidden.main && !hidden.footer; bar.classList.toggle('is-visible', show); bar.setAttribute('aria-hidden', String(!show)); barBtn.tabIndex = show ? 0 : -1; }
      new IntersectionObserver(function (e) { hidden.main = e[0].isIntersecting; update(); }).observe(mainBtn);
      var footer = document.querySelector('.footer');
      if (footer) { new IntersectionObserver(function (e) { hidden.footer = e[0].isIntersecting; update(); }).observe(footer); }
      barBtn.addEventListener('click', function () { mainBtn.click(); });
      /* sinkronkan label/status dari tombol utama ("Adding...", dst.) */
      new MutationObserver(function () { var l = mainBtn.querySelector('.cart-btn__label'); if (l) { barBtn.textContent = l.textContent; } barBtn.disabled = mainBtn.disabled; })
        .observe(mainBtn, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['disabled'] });
    }
  })();
  </script>
</body>
</html>