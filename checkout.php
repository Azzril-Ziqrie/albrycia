<?php
session_start();
require_once __DIR__ . '/includes/seo.php';

// Tangkap pilihan currency dari URL (sama seperti index.php / product.php)
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/currency_helper.php';
require_once __DIR__ . '/includes/shipping_quote.php';

/* ── brand constants ─────────────────────────────────── */
$brandName = 'ALBRYCIA';
$brandLogo = 'assets/images/brand/logo.png';

// Default ke IDR jika belum ada session
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

/* ── helpers ─────────────────────────────────────────── */
function moneyFormatCheckout(float $amount, string $currency): string
{
    if ($currency === 'USD') {
        return '$' . number_format($amount, 2, '.', ',');
    }
    return 'RP ' . number_format($amount, 0, ',', '.');
}

function makeOrderNumber(): string
{
    return 'ALBRYCIA-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function normalizeCurrency(string $currency): string
{
    $cur = strtoupper(trim($currency));
    return in_array($cur, ['IDR', 'USD'], true) ? $cur : 'IDR';
}

function isDiscountCurrentlyActiveCheckout(array $item): bool
{
    if ((int)($item['is_discount_active'] ?? 0) !== 1) return false;
    if ((float)($item['discount_percent'] ?? 0) <= 0)  return false;
    $now = time();
    if (!empty($item['discount_start_at'])) {
        $s = strtotime((string)$item['discount_start_at']);
        if ($s !== false && $now < $s) return false;
    }
    if (!empty($item['discount_end_at'])) {
        $e = strtotime((string)$item['discount_end_at']);
        if ($e !== false && $now > $e) return false;
    }
    return true;
}

function getProductSizeRow(PDO $pdo, string $slug, int $productSizeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.id AS product_id, p.name, p.slug, p.thumbnail, p.price_idr, p.price_usd,
                p.discount_percent, p.discount_start_at, p.discount_end_at, p.is_discount_active,
                COALESCE(c.slug,\'fashion\') AS category_slug,
                ps.id AS product_size_id, ps.stock, ps.is_active, s.name AS size_name
         FROM products p
         INNER JOIN product_sizes ps ON ps.product_id = p.id
         INNER JOIN sizes s ON s.id = ps.size_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.slug = :slug AND ps.id = :product_size_id AND p.is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['slug' => $slug, 'product_size_id' => $productSizeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && (int)$row['is_active'] === 1) ? $row : null;
}

function getProductSizeById(PDO $pdo, int $productSizeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.id AS product_id, p.name, p.slug, p.thumbnail, p.price_idr, p.price_usd,
                p.discount_percent, p.discount_start_at, p.discount_end_at, p.is_discount_active,
                COALESCE(c.slug,\'fashion\') AS category_slug,
                ps.id AS product_size_id, ps.stock, ps.is_active, s.name AS size_name
         FROM product_sizes ps
         INNER JOIN products p ON p.id = ps.product_id
         INNER JOIN sizes s ON s.id = ps.size_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE ps.id = :product_size_id AND p.is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['product_size_id' => $productSizeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && (int)$row['is_active'] === 1) ? $row : null;
}

function getPriceByCurrency(array $item, string $currency): float
{
    if ($currency === 'USD') {
        $usd = (float)($item['price_usd'] ?? 0);
        if ($usd > 0) {
            $base = $usd;
        } else {
            $usdRateFromIdr = defined('USD_RATE_FROM_IDR') ? (float)constant('USD_RATE_FROM_IDR') : 16000;
            $base = (float)($item['price_idr'] ?? 0) / $usdRateFromIdr;
        }
    } else {
        $base = (float)($item['price_idr'] ?? 0);
    }

    if (isDiscountCurrentlyActiveCheckout($item)) {
        $base = max($base - ($base * ((float)($item['discount_percent'] ?? 0) / 100)), 0);
    }

    return $base;
}

function seedGuestCartFromQuery(PDO $pdo): void
{
    if (!isset($_GET['guest']) || $_GET['guest'] !== '1') return;
    $productSlug = trim((string)($_GET['product'] ?? ''));
    $sizeId      = (int)($_GET['size_id'] ?? 0);
    $qty         = (int)($_GET['qty'] ?? 1);
    
    $currency    = normalizeCurrency((string)($_GET['currency'] ?? ($_SESSION['currency'] ?? 'IDR')));
    if ($productSlug === '' || $sizeId <= 0 || $qty <= 0) return;
    $row = getProductSizeRow($pdo, $productSlug, $sizeId);
    if (!$row || (int)$row['stock'] <= 0) return;
    $qty = min($qty, (int)$row['stock']);
    if (!isset($_SESSION['guest_cart']) || !is_array($_SESSION['guest_cart'])) $_SESSION['guest_cart'] = [];
    $found = false;
    foreach ($_SESSION['guest_cart'] as &$ci) {
        if ((int)($ci['product_id'] ?? 0) === (int)$row['product_id'] &&
            (int)($ci['product_size_id'] ?? 0) === (int)$row['product_size_id'] &&
            strtoupper((string)($ci['currency'] ?? 'IDR')) === $currency) {
            $ci['quantity'] = min((int)$ci['quantity'] + $qty, (int)$row['stock']);
            $found = true; break;
        }
    }
    unset($ci);
    if (!$found) $_SESSION['guest_cart'][] = [
        'product_id' => (int)$row['product_id'], 'product_size_id' => (int)$row['product_size_id'],
        'slug' => $row['slug'], 'quantity' => $qty, 'currency' => $currency,
    ];
}

function getGuestCartItemsFromSession(PDO $pdo): array
{
    global $currentCurrency;
    $activeCurrency = normalizeCurrency($currentCurrency);
    $sessionCart = $_SESSION['guest_cart'] ?? [];
    if (!is_array($sessionCart) || !$sessionCart) return [];
    $items = [];
    foreach ($sessionCart as $si) {
        $slug = trim((string)($si['slug'] ?? '')); $psid = (int)($si['product_size_id'] ?? 0);
        $qty  = (int)($si['quantity'] ?? 0); $cur = $activeCurrency;
        if ($slug === '' || $psid <= 0 || $qty <= 0) continue;
        $row = getProductSizeRow($pdo, $slug, $psid);
        if (!$row || (int)$row['stock'] <= 0) continue;
        $qty   = min($qty, (int)$row['stock']);
        $price = getPriceByCurrency($row, $cur);
        $items[] = ['cart_item_id' => null, 'product_id' => (int)$row['product_id'],
            'product_size_id' => (int)$row['product_size_id'], 'name' => $row['name'],
            'slug' => $row['slug'], 'thumbnail' => $row['thumbnail'], 'size_name' => $row['size_name'],
            'category_slug' => $row['category_slug'] ?? 'fashion', 'quantity' => $qty,
            'price' => $price, 'currency' => $cur, 'line_total' => $price * $qty, 'stock' => (int)$row['stock']];
    }
    return $items;
}

function getBuyNowItemsFromSession(PDO $pdo): array
{
    global $currentCurrency;
    $activeCurrency = normalizeCurrency($currentCurrency);

    $bn = $_SESSION['buy_now'] ?? [];
    if (!is_array($bn) || !$bn) return [];
    $pid = (int)($bn['product_id'] ?? 0); $psid = (int)($bn['product_size_id'] ?? 0);
    $qty = (int)($bn['quantity'] ?? 0); $cur = $activeCurrency;
    if ($pid <= 0 || $psid <= 0 || $qty <= 0) return [];
    $row = getProductSizeById($pdo, $psid);
    if (!$row || (int)$row['product_id'] !== $pid || (int)$row['stock'] <= 0) return [];
    $qty = min($qty, (int)$row['stock']); $_SESSION['buy_now']['quantity'] = $qty;
    $price = getPriceByCurrency($row, $cur);
    return [['cart_item_id' => null, 'product_id' => (int)$row['product_id'],
        'product_size_id' => (int)$row['product_size_id'], 'name' => $row['name'],
        'slug' => $row['slug'], 'thumbnail' => $row['thumbnail'], 'size_name' => $row['size_name'],
        'category_slug' => $row['category_slug'] ?? 'fashion', 'quantity' => $qty,
        'price' => $price, 'currency' => $cur, 'line_total' => $price * $qty, 'stock' => (int)$row['stock']]];
}

function getMemberCartItems(PDO $pdo, int $userId): array
{
    global $currentCurrency;
    $cartStmt = $pdo->prepare('SELECT id FROM carts WHERE user_id=:uid AND status=:s LIMIT 1');
    $cartStmt->execute(['uid' => $userId, 's' => 'active']);
    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
    if (!$cart) return [];

    $stmt = $pdo->prepare(
        'SELECT ci.id AS cart_item_id, ci.product_id, ci.product_size_id, ci.quantity,
                p.name, p.slug, p.thumbnail, p.price_idr, p.price_usd,
                p.discount_percent, p.discount_start_at, p.discount_end_at, p.is_discount_active,
                s.name AS size_name, COALESCE(c.slug,\'fashion\') AS category_slug,
                ps.stock
         FROM cart_items ci
         INNER JOIN products p ON p.id=ci.product_id
         LEFT JOIN product_sizes ps ON ps.id=ci.product_size_id
         LEFT JOIN sizes s ON s.id=ps.size_id
         LEFT JOIN categories c ON c.id=p.category_id
         WHERE ci.cart_id=:cid ORDER BY ci.id DESC'
    );
    $stmt->execute(['cid' => $cart['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        $price = getPriceByCurrency($item, $currentCurrency);
        $item['line_total'] = (float)$price * (int)$item['quantity'];
        $item['currency'] = $currentCurrency;
        $item['price'] = $price;
        $item['stock'] = (int)$item['stock'];
    }
    unset($item);
    return $items;
}

function validateCheckoutStock(PDO $pdo, array $cartItems): void
{
    foreach ($cartItems as $item) {
        $psid = (int)($item['product_size_id'] ?? 0); $qty = (int)($item['quantity'] ?? 0);
        if ($psid <= 0 || $qty <= 0) throw new Exception('Invalid checkout item detected.');
        $row = getProductSizeById($pdo, $psid);
        if (!$row) throw new Exception('Selected size no longer available for ' . $item['name'] . '.');
        if ((int)$row['stock'] < $qty) throw new Exception('Stock not enough for ' . $item['name'] . '.');
    }
}

/* ── state ───────────────────────────────────────────── */
$isLoggedIn = isset($_SESSION['user']['id']);
$userId     = $isLoggedIn ? (int)$_SESSION['user']['id'] : null;
$userData   = $_SESSION['user'] ?? [];

$checkoutMode = strtolower(trim((string)($_POST['checkout_mode'] ?? $_GET['mode'] ?? 'cart')));
if ($checkoutMode !== 'buy_now') $checkoutMode = 'cart';

/* ════════════════════════════════════════════════════════════
   AJAX HANDLER: UPDATE QUANTITY LANGSUNG DI HALAMAN CHECKOUT
═══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_qty'])) {
    header('Content-Type: application/json');
    $psid = (int)($_POST['product_size_id'] ?? 0);
    $newQty = (int)($_POST['qty'] ?? 0);

    if ($psid <= 0 || $newQty < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid quantity.']);
        exit;
    }

    $row = getProductSizeById($pdo, $psid);
    if (!$row || $row['stock'] < $newQty) {
        echo json_encode(['success' => false, 'message' => 'Max stock is ' . ($row['stock'] ?? 0)]);
        exit;
    }

    if ($checkoutMode === 'buy_now') {
        if (isset($_SESSION['buy_now']) && (int)$_SESSION['buy_now']['product_size_id'] === $psid) {
            $_SESSION['buy_now']['quantity'] = $newQty;
        }
    } else {
        if ($isLoggedIn) {
            $cartStmt = $pdo->prepare('SELECT id FROM carts WHERE user_id=:uid AND status=:s LIMIT 1');
            $cartStmt->execute(['uid' => $userId, 's' => 'active']);
            $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
            if ($cart) {
                $upd = $pdo->prepare('UPDATE cart_items SET quantity=:qty WHERE cart_id=:cid AND product_size_id=:psid');
                $upd->execute(['qty' => $newQty, 'cid' => $cart['id'], 'psid' => $psid]);
            }
        } else {
            if (isset($_SESSION['guest_cart'])) {
                foreach ($_SESSION['guest_cart'] as &$ci) {
                    if ((int)($ci['product_size_id'] ?? 0) === $psid) {
                        $ci['quantity'] = $newQty;
                        break;
                    }
                }
                unset($ci);
            }
        }
    }

    $cartItemsUpdated = $checkoutMode === 'buy_now'
        ? getBuyNowItemsFromSession($pdo)
        : ($isLoggedIn ? getMemberCartItems($pdo, $userId) : getGuestCartItemsFromSession($pdo));

    $newSubtotal = 0.0;
    $newLineTotal = 0.0;
    foreach ($cartItemsUpdated as $it) {
        $newSubtotal += (float)$it['line_total'];
        if ((int)$it['product_size_id'] === $psid) {
            $newLineTotal = (float)$it['line_total'];
        }
    }

    echo json_encode([
        'success' => true,
        'new_qty' => $newQty,
        'new_line_total' => $newLineTotal,
        'new_subtotal' => $newSubtotal
    ]);
    exit;
}
/* ════════════════════════════════════════════════════════════ */

/* ════════════════════════════════════════════════════════════
   AJAX HANDLER: HAPUS ITEM DARI CHECKOUT
═══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_remove_item'])) {
    header('Content-Type: application/json');
    $psid = (int)($_POST['product_size_id'] ?? 0);

    if ($psid > 0) {
        if ($checkoutMode === 'buy_now') {
            unset($_SESSION['buy_now']);
        } else {
            if ($isLoggedIn) {
                $cartStmt = $pdo->prepare('SELECT id FROM carts WHERE user_id=:uid AND status=:s LIMIT 1');
                $cartStmt->execute(['uid' => $userId, 's' => 'active']);
                $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
                if ($cart) {
                    $del = $pdo->prepare('DELETE FROM cart_items WHERE cart_id=:cid AND product_size_id=:psid');
                    $del->execute(['cid' => $cart['id'], 'psid' => $psid]);
                }
            } else {
                if (isset($_SESSION['guest_cart'])) {
                    foreach ($_SESSION['guest_cart'] as $k => $ci) {
                        if ((int)($ci['product_size_id'] ?? 0) === $psid) {
                            unset($_SESSION['guest_cart'][$k]);
                        }
                    }
                    $_SESSION['guest_cart'] = array_values($_SESSION['guest_cart']);
                }
            }
        }
    }

    echo json_encode(['success' => true]);
    exit;
}
/* ════════════════════════════════════════════════════════════ */

if (!$isLoggedIn && $checkoutMode === 'cart') seedGuestCartFromQuery($pdo);

$cartItems = $checkoutMode === 'buy_now'
    ? getBuyNowItemsFromSession($pdo)
    : ($isLoggedIn ? getMemberCartItems($pdo, $userId) : getGuestCartItemsFromSession($pdo));

$subtotal = 0.0; $currency = $currentCurrency;
foreach ($cartItems as $item) { $subtotal += (float)$item['line_total']; }

// Rate konversi USD (1 USD = ... IDR) — sinkron dengan JS
$usdRateFromIdr = defined('USD_RATE_FROM_IDR') ? (float)constant('USD_RATE_FROM_IDR') : 16000;

$message = ''; $error = '';
$selectedShippingCost = 0.0;
$grandTotal = $subtotal;

$form = [
    'full_name' => $userData['full_name'] ?? '', 'email' => $userData['email'] ?? '',
    'phone' => '', 'shipping_mode' => 'domestic', 'country_code' => 'ID',
    'country_name' => 'Indonesia', 'address_line_1' => '', 'address_line_2' => '',
    'city' => '', 'state' => '', 'postal_code' => '',
    'courier_code' => '', 'courier_service' => '', 'shipping_cost' => '0', 'notes' => '',
];

// Pastikan proses checkout hanya jalan jika POST biasa (bukan request AJAX qty / hapus)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_update_qty']) && !isset($_POST['ajax_remove_item'])) {
    foreach ($form as $key => $value) $form[$key] = trim((string)($_POST[$key] ?? ''));
    // Ongkir TIDAK dipercaya dari browser: hanya nilai yang ditandatangani endpoint ongkir yang diterima.
    $shippingMode  = $form['shipping_mode'] === 'international' ? 'international' : 'domestic';
    $verifiedIdr   = shippingQuoteVerify(
        $shippingMode, trim((string)($_POST['shipping_destination'] ?? '')),
        $form['courier_code'], $form['courier_service'],
        (int)($_POST['shipping_cost_idr'] ?? 0), (string)($_POST['shipping_token'] ?? '')
    );
    $selectedShippingCost = $verifiedIdr === null ? 0.0
        : ($currency === 'USD' ? round($verifiedIdr / $usdRateFromIdr, 2) : (float)$verifiedIdr);
    $form['shipping_cost'] = (string)$selectedShippingCost;
    $grandTotal = $subtotal + $selectedShippingCost;

    if (!$cartItems) {
        $error = 'No items available for checkout.';
    } elseif ($form['full_name'] === '' || $form['email'] === '' || $form['phone'] === '' ||
              $form['country_code'] === '' || $form['address_line_1'] === '' ||
              $form['city'] === '' || $form['postal_code'] === '') {
        $error = 'Please complete all required fields.';
    } elseif ($form['courier_code'] === '' || $form['courier_service'] === '') {
        $error = 'Please calculate shipping and choose a courier service.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($verifiedIdr === null) {
        $error = 'Shipping quote is invalid or expired. Please choose a shipping service again.';
    } else {
        try {
            validateCheckoutStock($pdo, $cartItems);
            $pdo->beginTransaction();
            $orderNumber = makeOrderNumber();

            $pdo->prepare(
                'INSERT INTO orders (order_number,user_id,customer_type,full_name,email,phone,
                 country_code,country_name,address_line_1,address_line_2,city,state,postal_code,
                 courier_code,courier_service,currency,subtotal,shipping_cost,grand_total,
                 payment_provider,payment_status,fulfillment_status,notes)
                 VALUES (:on,:uid,:ct,:fn,:em,:ph,:cc,:cn,:a1,:a2,:ci,:st,:pc,:coc,:cs,:cur,
                         :sub,:sc,:gt,:pp,:ps,:fs,:no)'
            )->execute([
                'on' => $orderNumber, 'uid' => $userId,
                'ct' => $isLoggedIn ? 'member' : 'guest',
                'fn' => $form['full_name'], 'em' => $form['email'], 'ph' => $form['phone'],
                'cc' => $form['country_code'], 'cn' => $form['country_name'],
                'a1' => $form['address_line_1'],
                'a2' => $form['address_line_2'] !== '' ? $form['address_line_2'] : null,
                'ci' => $form['city'],
                'st' => $form['state'] !== '' ? $form['state'] : null,
                'pc' => $form['postal_code'], 'coc' => $form['courier_code'],
                'cs' => $form['courier_service'], 'cur' => $currency,
                'sub' => $subtotal, 'sc' => $selectedShippingCost, 'gt' => $grandTotal,
                'pp' => 'manual_transfer', 'ps' => 'pending', 'fs' => 'pending',
                'no' => $form['notes'] !== '' ? $form['notes'] : null,
            ]);
            $orderId = (int)$pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO order_items (order_id,product_id,product_size_id,product_name,
                 product_slug,size_name,quantity,price,line_total,thumbnail)
                 VALUES (:oid,:pid,:psid,:pn,:ps,:sn,:qty,:pr,:lt,:th)'
            );
            foreach ($cartItems as $item) {
                $insertItem->execute([
                    'oid' => $orderId, 'pid' => $item['product_id'],
                    'psid' => $item['product_size_id'], 'pn' => $item['name'],
                    'ps' => $item['slug'],
                    'sn' => $item['size_name'] !== '' ? $item['size_name'] : null,
                    'qty' => $item['quantity'], 'pr' => $item['price'],
                    'lt' => $item['line_total'], 'th' => $item['thumbnail'],
                ]);
            }
            $pdo->prepare(
                'INSERT INTO order_status_logs (order_id,status_type,status_value,notes)
                 VALUES (:oid,:st,:sv,:n)'
            )->execute(['oid' => $orderId, 'st' => 'payment', 'sv' => 'pending',
                        'n' => 'Order created. Waiting for manual transfer.']);
            $pdo->commit();

            if ($checkoutMode === 'buy_now') unset($_SESSION['buy_now']);
            elseif (!$isLoggedIn) $_SESSION['guest_cart'] = [];

            header('Location: payment.php?order=' . urlencode($orderNumber));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to create order: ' . $e->getMessage();
        }
    }
}

$domesticCouriers      = ['jne' => 'JNE'];
$internationalCouriers = ['pos' => 'POS Indonesia'];
$isBuyNow  = $checkoutMode === 'buy_now';
$pageTitle = $isBuyNow ? 'Checkout Now' : 'Checkout';

$backToProductUrl = 'shop.php';
if (!empty($cartItems)) {
    $first = $cartItems[0];
    $firstSlug = (string)($first['slug'] ?? '');
    if ($firstSlug !== '') {
        $backToProductUrl = 'product.php?slug=' . urlencode($firstSlug)
            . ($currentCurrency === 'USD' ? '&currency=USD' : '');
    }
} elseif ($currentCurrency === 'USD') {
    $backToProductUrl = 'shop.php?currency=USD';
}

/** Input dengan label mengambang (gaya checkout). $o: type, autocomplete, required. */
function ckField(string $id, string $label, string $value, array $o = []): string
{
    return '<div class="field"><input type="' . htmlspecialchars($o['type'] ?? 'text') . '" id="' . $id . '" name="' . $id . '"'
        . ' value="' . htmlspecialchars($value) . '" placeholder=" "'
        . (!empty($o['autocomplete']) ? ' autocomplete="' . htmlspecialchars($o['autocomplete']) . '"' : '')
        . (!empty($o['required']) ? ' required' : '') . '>'
        . '<label for="' . $id . '">' . htmlspecialchars($label) . '</label></div>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php seoHead(['title' => $pageTitle . ' — ' . BRAND_NAME, 'path' => 'checkout', 'noindex' => true]); ?>
  <style>
:root{--ink:#1a1a1a;--muted:#707070;--line:#d9d9d9;--soft:#f5f5f5;--panel:#fafafa;--radius:10px}
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:#fff;color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;line-height:1.45;animation:pageFadeIn .3s ease forwards;-webkit-font-smoothing:antialiased}
a{color:inherit}
img{display:block;max-width:100%}
button,input,select,textarea{font:inherit;color:inherit}
button{cursor:pointer}

/* ── layout ─────────────────────────────────────────── */
.checkout-wrapper{display:grid;grid-template-columns:minmax(0,1.12fr) minmax(0,1fr);min-height:100vh}
.left-col{display:flex;justify-content:flex-end;padding:32px 48px 56px}
.left-inner{width:100%;max-width:560px}
.right-col{background:var(--panel);border-left:1px solid #e1e1e1;padding:40px 48px}
.right-inner{width:100%;max-width:460px;position:sticky;top:32px}
.co-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px}
.brand-mark img{height:52px;width:auto;border-radius:50%}
.back-link{font-size:13px;color:var(--muted);text-decoration:none}
.back-link:hover{color:var(--ink);text-decoration:underline}

/* ── sections ───────────────────────────────────────── */
.sec{margin-bottom:30px}
.sec h2{font-size:21px;font-weight:500;margin-bottom:14px}
.sec h3{font-size:17px;font-weight:500;margin-bottom:12px}
.sec-sub{color:var(--muted);font-size:14px;margin:-8px 0 14px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.stack{display:flex;flex-direction:column;gap:12px}

/* ── floating-label fields ──────────────────────────── */
.field{position:relative}
.field input,.field select,.field textarea{width:100%;height:54px;border:1px solid var(--line);border-radius:var(--radius);background:#fff;padding:21px 14px 6px;font-size:15px;outline:none;-webkit-appearance:none;appearance:none;transition:box-shadow .12s,border-color .12s}
.field textarea{height:auto;min-height:92px;padding-top:24px;resize:vertical}
.field select{padding-right:38px;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1.5l5 5 5-5' fill='none' stroke='%23707070' stroke-width='1.6'/%3E%3C/svg%3E") no-repeat right 16px center;cursor:pointer}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--ink);box-shadow:0 0 0 1px var(--ink)}
.field label{position:absolute;left:15px;top:17px;font-size:15px;color:var(--muted);pointer-events:none;transition:top .12s,font-size .12s}
.field input:focus + label,.field input:not(:placeholder-shown) + label,.field textarea:focus + label,.field textarea:not(:placeholder-shown) + label,.field select + label{top:8px;font-size:11.5px}
.field-hint{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.5}

/* ── optional fields (dropdown) ─────────────────────── */
details.opt{margin:0}
details.opt > summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:14px;color:#2a5db0;padding:4px 0}
details.opt > summary::-webkit-details-marker{display:none}
details.opt > summary::before{content:'+';font-size:16px;line-height:1}
details.opt[open] > summary::before{content:'−'}
details.opt[open] > summary{margin-bottom:10px}
details.opt > summary:hover{text-decoration:underline}

/* ── shipping ───────────────────────────────────────── */
.shipping-options-box{background:var(--soft);border-radius:var(--radius);padding:20px 16px;text-align:center;color:var(--muted);font-size:14px}
.shipping-options-box:has(.shipping-option){padding:0;background:#fff;text-align:left;border:1px solid var(--line);overflow:hidden}
.shipping-option{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px;cursor:pointer;border-top:1px solid var(--line);margin:0}
.shipping-option:first-child{border-top:none}
.shipping-option:has(input:checked){background:var(--soft);box-shadow:inset 0 0 0 2px var(--ink)}
.shipping-option input[type=radio]{width:18px;height:18px;accent-color:var(--ink);flex-shrink:0}
.shipping-option-left{display:flex;align-items:center;gap:12px}
.shipping-service-name{font-size:14px}
.shipping-service-price{font-size:14px;font-weight:600}
.shipping-service-etd{font-size:12px;color:var(--muted);margin-top:2px}
.shipping-courier-badge{font-size:11px;padding:2px 8px;border:1px solid var(--line);border-radius:6px;color:var(--muted)}

/* ── buttons ────────────────────────────────────────── */
.btn-primary{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:56px;padding:0 24px;background:var(--ink);color:#fff;border:none;border-radius:var(--radius);font-size:15px;font-weight:600;transition:background .15s;text-decoration:none}
.btn-primary:hover{background:#000}
.btn-primary:disabled{background:#cfcfcf;cursor:not-allowed}
.btn-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;background:#fff;color:var(--ink);border:1px solid var(--line);border-radius:var(--radius);font-size:14px;transition:border-color .15s;text-decoration:none}
.btn-secondary:hover{border-color:var(--ink)}
.form-actions{margin-top:8px}
.secure-note{font-size:12px;color:var(--muted);margin-top:12px}
.back-row{margin-top:14px}

/* ── notices ────────────────────────────────────────── */
.notice{padding:12px 14px;font-size:14px;border-radius:var(--radius);margin-bottom:18px}
.notice.error{color:#b3261e;background:#fdecea;border:1px solid #f5c6c2}
.notice.success{color:#1e6b34;background:#eaf6ee;border:1px solid #bfe2c9}

/* ── order summary (right) ──────────────────────────── */
.cart-item-row{display:grid;grid-template-columns:64px 1fr auto;gap:14px;align-items:center;margin-bottom:16px}
.cart-thumb-wrap{position:relative;width:64px;height:64px}
.cart-thumb{width:64px;height:64px;object-fit:contain;border-radius:12px;border:1px solid #e6e6e6;background:#fff;padding:3px}
.cart-qty-badge{position:absolute;top:-8px;right:-8px;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:var(--ink);color:#fff;font-size:12px;display:flex;align-items:center;justify-content:center}
.cart-item-name{font-size:14px;font-weight:500;line-height:1.3}
.cart-item-meta{font-size:12.5px;color:var(--muted);margin-top:2px}
.cart-item-price{font-size:14px;text-align:right}
.checkout-qty-ctrl{display:inline-flex;align-items:center;gap:2px;margin-top:6px;border:1px solid var(--line);border-radius:8px;background:#fff}
.checkout-qty-ctrl button{width:26px;height:26px;border:none;background:transparent;font-size:15px;color:var(--ink)}
.checkout-qty-ctrl button:hover:not(:disabled){background:var(--soft)}
.checkout-qty-ctrl button:disabled{opacity:.4;cursor:not-allowed}
.checkout-qty-ctrl span{min-width:20px;text-align:center;font-size:13px}
.cart-item-remove-checkout{background:none;border:none;color:var(--muted);font-size:12px;text-decoration:underline;margin-left:8px;padding:4px 0}
.cart-item-remove-checkout:hover{color:#b3261e}
/* ringkasan: bar toggle (hanya mobile) + body yang membuka/menutup halus */
.summary-toggle{display:none}
.summary-body{display:grid;grid-template-rows:1fr}
.summary-inner{min-height:0;overflow:visible}
.totals-block{margin-top:22px;padding-top:6px}
.total-row{display:flex;justify-content:space-between;align-items:baseline;padding:5px 0;font-size:14px}
.total-row span:last-child{text-align:right}
.total-row.muted span:last-child{color:var(--muted)}
.total-row.grand{padding-top:14px;font-size:19px;font-weight:500}
.total-row.grand .cur{font-size:12px;color:var(--muted);margin-right:6px;font-weight:400}
.total-row.grand strong{font-size:22px;font-weight:600}
.summary-note{font-size:12px;color:var(--muted);margin-top:8px}
.empty-cart{padding:24px 0;color:var(--muted)}

.footer{margin-top:36px;font-size:12px;color:var(--muted)}
.toast-container{position:fixed;bottom:24px;right:24px;z-index:200;display:flex;flex-direction:column;gap:6px;pointer-events:none}
.toast{background:var(--ink);color:#fff;padding:10px 16px;font-size:13px;border-radius:8px;opacity:0;transform:translateY(8px);transition:opacity .2s,transform .2s;max-width:280px}
.toast.show{opacity:1;transform:translateY(0)}
@media(max-width:900px){
  /* urutan mobile: logo -> ringkasan pesanan (collapsible) -> form -> footer */
  .checkout-wrapper{display:flex;flex-direction:column;min-height:100vh;min-height:100dvh}
  .left-col,.left-inner{display:contents}
  .left-inner>*{order:2;margin-left:20px;margin-right:20px}
  .left-inner>.co-head{order:0;margin-top:20px;margin-bottom:16px}
  .left-inner>.footer{margin-bottom:max(36px,env(safe-area-inset-bottom))}
  .right-col{order:1;background:var(--panel);border-left:none;border-top:1px solid #e1e1e1;border-bottom:1px solid #e1e1e1;padding:0;margin-bottom:24px}
  .right-inner{position:static;max-width:none}
  .summary-toggle{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;min-height:58px;padding:0 20px;background:transparent;border:none;text-align:left;font-size:14px;color:#2a5db0}
  .summary-toggle:active{background:rgba(0,0,0,.04)}
  .summary-toggle-l{display:inline-flex;align-items:center;gap:8px}
  .summary-toggle .chev{transition:transform .4s cubic-bezier(.32,.72,0,1)}
  .summary-toggle[aria-expanded=true] .chev{transform:rotate(180deg)}
  .summary-toggle-r{display:inline-flex;align-items:baseline;gap:6px;color:var(--ink)}
  .summary-toggle-r .cur{font-size:11px;color:var(--muted)}
  .summary-toggle-r strong{font-size:17px;font-weight:600}
  .summary-body{grid-template-rows:0fr;transition:grid-template-rows .4s cubic-bezier(.32,.72,0,1)}
  .summary-body.is-open{grid-template-rows:1fr}
  .summary-inner{overflow:hidden;padding:0 20px;opacity:0;transition:opacity .25s ease}
  .summary-body.is-open .summary-inner{opacity:1;padding-bottom:20px;padding-top:4px}
  .summary-body:not(.is-open) .summary-inner{visibility:hidden;transition:opacity .2s ease,visibility 0s .4s}
  /* target sentuh yang layak untuk qty & remove */
  .checkout-qty-ctrl button{width:34px;height:34px;font-size:17px}
  .checkout-qty-ctrl span{min-width:24px}
  .cart-item-remove-checkout{min-height:34px;padding:0 6px}
  .cart-item-row{align-items:start}
  .cart-item-price{padding-top:2px}
  .totals-block{border-top:1px solid #e6e6e6;padding-top:12px}
}
@media (prefers-reduced-motion:reduce){
  .summary-body,.summary-toggle .chev,.summary-inner{transition-duration:.01ms!important}
}
@media(max-width:560px){
  .grid2{grid-template-columns:1fr}
  .toast-container{right:12px;left:12px;bottom:12px}
  .toast{max-width:100%}
}
@keyframes pageFadeIn{from{opacity:0}to{opacity:1}}
  </style>
  <script><?= ddDisplayJs() ?></script>
  <link rel="stylesheet" href="assets/css/apple.css?v=<?= @filemtime(__DIR__ . '/assets/css/apple.css') ?>">
</head>
<body>
  <?php if (file_exists(__DIR__ . '/loader.php')) include_once 'loader.php'; ?>

  <div class="toast-container" id="toastContainer" aria-live="polite"></div>

  <div class="checkout-wrapper">

    <!-- ── LEFT: FORM ─────────────────────────────────── -->
    <div class="left-col"><div class="left-inner">

      <header class="co-head">
        <a href="index.php" class="brand-mark"><img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?>"></a>
        <a href="<?= htmlspecialchars($backToProductUrl) ?>" class="back-link">← Product</a>
      </header>

      <?php if ($error !== ''): ?>
        <div class="notice error" role="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($message !== ''): ?>
        <div class="notice success" role="status"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <form method="POST" action="checkout.php<?= $isBuyNow ? '?mode=buy_now' : '' ?><?= $currentCurrency === 'USD' ? ($isBuyNow ? '&' : '?').'currency=USD' : '' ?>" id="checkoutForm">
        <input type="hidden" name="checkout_mode" value="<?= htmlspecialchars($checkoutMode) ?>">
        <input type="hidden" name="currency" value="<?= htmlspecialchars($currentCurrency) ?>">

        <section class="sec" aria-label="Contact information">
          <h2>Contact</h2>
          <div class="stack">
            <?= ckField('email', 'Email', $form['email'], ['type' => 'email', 'autocomplete' => 'email', 'required' => true]) ?>
            <?= ckField('phone', 'Phone', $form['phone'], ['type' => 'tel', 'autocomplete' => 'tel', 'required' => true]) ?>
          </div>
        </section>

        <section class="sec" aria-label="Delivery address">
          <h2>Delivery</h2>
          <div class="stack">
            <div class="field">
              <select id="shipping_mode" name="shipping_mode">
                <option value="domestic" <?= $form['shipping_mode'] === 'domestic' ? 'selected' : '' ?>>Indonesia (Domestic)</option>
                <option value="international" <?= $form['shipping_mode'] === 'international' ? 'selected' : '' ?>>International</option>
              </select>
              <label for="shipping_mode">Country/Region</label>
            </div>
            <div class="field">
              <input type="text" id="destination_search" placeholder=" " autocomplete="off">
              <label for="destination_search" id="destinationSearchLabel">City / Destination</label>
            </div>
            <div class="field">
              <select id="destination_select"><option value="">Choose destination</option></select>
              <label for="destination_select">Select destination</label>
              <p class="field-hint">Make sure your address is accurate — incorrect addresses may cause delivery failure.</p>
            </div>
            <?= ckField('full_name', 'Full name', $form['full_name'], ['autocomplete' => 'name', 'required' => true]) ?>
            <?= ckField('address_line_1', 'Address', $form['address_line_1'], ['autocomplete' => 'address-line1', 'required' => true]) ?>
            <details class="opt"<?= $form['address_line_2'] !== '' ? ' open' : '' ?>>
              <summary>Add apartment, suite, etc.</summary>
              <?= ckField('address_line_2', 'Apartment, suite, etc.', $form['address_line_2'], ['autocomplete' => 'address-line2']) ?>
            </details>
            <div class="grid2">
              <?= ckField('city', 'City', $form['city'], ['autocomplete' => 'address-level2', 'required' => true]) ?>
              <?= ckField('postal_code', 'Postal code', $form['postal_code'], ['autocomplete' => 'postal-code', 'required' => true]) ?>
            </div>
            <details class="opt"<?= $form['state'] !== '' ? ' open' : '' ?>>
              <summary>Add province / state</summary>
              <?= ckField('state', 'Province / State', $form['state'], ['autocomplete' => 'address-level1']) ?>
            </details>
          </div>
        </section>

        <section class="sec" aria-label="Shipping method">
          <h3>Shipping method</h3>
          <div class="stack">
            <div class="field">
              <select id="courier_picker"></select>
              <label for="courier_picker">Courier</label>
            </div>
            <div id="shippingOptions" class="shipping-options-box">Enter your shipping address to view available shipping methods.</div>
            <details class="opt"<?= $form['notes'] !== '' ? ' open' : '' ?>>
              <summary>Add order note</summary>
              <div class="field">
                <textarea id="notes" name="notes" placeholder=" "><?= htmlspecialchars($form['notes']) ?></textarea>
                <label for="notes">Special instructions</label>
              </div>
            </details>
          </div>

          <input type="hidden" name="country_code"      id="country_code"      value="<?= htmlspecialchars($form['country_code']) ?>">
          <input type="hidden" name="country_name"      id="country_name"      value="<?= htmlspecialchars($form['country_name']) ?>">
          <input type="hidden" name="courier_code"      id="courier_code"      value="<?= htmlspecialchars($form['courier_code']) ?>">
          <input type="hidden" name="courier_service"   id="courier_service"   value="<?= htmlspecialchars($form['courier_service']) ?>">
          <input type="hidden" name="shipping_cost"     id="shipping_cost"     value="<?= htmlspecialchars($form['shipping_cost']) ?>">
          <input type="hidden" name="shipping_cost_idr" id="shipping_cost_idr" value="0">
          <input type="hidden" name="shipping_token"       id="shipping_token"       value="">
          <input type="hidden" name="shipping_destination" id="shipping_destination" value="">
        </section>

        <div class="form-actions">
          <button type="submit" class="btn-primary" id="submitBtn">Proceed to payment</button>
          <p class="secure-note">🔒 Your data is encrypted and secure. We never store your payment details.</p>
        </div>
      </form>

      <footer class="footer" aria-label="Site footer">© <?= date('Y') ?> <?= htmlspecialchars($brandName) ?>. All rights reserved.</footer>
    </div></div>

    <!-- ── RIGHT: SUMMARY ─────────────────────────────── -->
    <aside class="right-col" aria-label="Order summary"><div class="right-inner">
      <span id="cartCountHeader" hidden><?= count($cartItems) ?></span>
      <?php if ($cartItems): ?>
      <button type="button" class="summary-toggle" id="summaryToggle" aria-expanded="false" aria-controls="summaryBody">
        <span class="summary-toggle-l"><span id="summaryToggleLabel">Show order summary</span>
          <svg class="chev" width="12" height="8" viewBox="0 0 12 8" aria-hidden="true"><path d="M1 1.5l5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></span>
        <span class="summary-toggle-r"><span class="cur"><?= htmlspecialchars($currency) ?></span><strong id="heroGrandTotal"><?= htmlspecialchars(moneyFormatCheckout($grandTotal, $currency)) ?></strong></span>
      </button>
      <?php else: ?>
      <span id="heroGrandTotal" hidden></span>
      <?php endif; ?>
      <div class="summary-body<?= $cartItems ? '' : ' is-open' ?>" id="summaryBody"><div class="summary-inner">
      <?php if (!$cartItems): ?>
        <div class="empty-cart">No items in cart.</div>
      <?php else: ?>
        <?php foreach ($cartItems as $item): ?>
          <div class="cart-item-row" id="row_psid_<?= $item['product_size_id'] ?>">
            <div class="cart-thumb-wrap">
              <img class="cart-thumb" src="<?= htmlspecialchars($item['thumbnail']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" loading="lazy">
              <span class="cart-qty-badge" id="qty_badge_<?= $item['product_size_id'] ?>"><?= (int)$item['quantity'] ?></span>
            </div>
            <div>
              <div class="cart-item-name"><?= htmlspecialchars($item['name']) ?></div>
              <?php if (strtolower((string)($item['category_slug'] ?? 'fashion')) === 'fashion'): ?>
                <div class="cart-item-meta"><?= htmlspecialchars((string)$item['size_name']) ?></div>
              <?php endif; ?>
              <div>
                <span class="checkout-qty-ctrl">
                  <button type="button" aria-label="Decrease" onclick="updateCheckoutQty(<?= $item['product_size_id'] ?>, -1, <?= $item['stock'] ?>)">−</button>
                  <span id="qty_display_<?= $item['product_size_id'] ?>"><?= (int)$item['quantity'] ?></span>
                  <button type="button" aria-label="Increase" onclick="updateCheckoutQty(<?= $item['product_size_id'] ?>, 1, <?= $item['stock'] ?>)">+</button>
                </span>
                <button type="button" class="cart-item-remove-checkout" aria-label="Remove item" onclick="removeCheckoutItem(<?= $item['product_size_id'] ?>)">Remove</button>
              </div>
            </div>
            <div class="cart-item-price" id="line_total_display_<?= $item['product_size_id'] ?>"><?= htmlspecialchars(moneyFormatCheckout((float)$item['line_total'], $item['currency'])) ?></div>
          </div>
        <?php endforeach; ?>

        <div class="totals-block">
          <div class="total-row"><span>Subtotal</span><span id="subtotalDisplay"><?= htmlspecialchars(moneyFormatCheckout($subtotal, $currency)) ?></span></div>
          <div class="total-row"><span>Shipping</span><span id="shippingDisplay" style="color:var(--muted)"><?= $selectedShippingCost > 0 ? htmlspecialchars(moneyFormatCheckout($selectedShippingCost, $currency)) : 'Enter shipping address' ?></span></div>
          <div class="total-row grand"><span>Total</span><span><span class="cur"><?= htmlspecialchars($currency) ?></span><strong id="grandTotalDisplay"><?= htmlspecialchars(moneyFormatCheckout($grandTotal, $currency)) ?></strong></span></div>
          <?php if ($currency === 'IDR' && ddDisplayCurrency() !== DD_BASE_CURRENCY): ?>
            <div class="total-row muted"><span>Estimate in <?= htmlspecialchars(ddDisplayCurrency()) ?></span><span id="grandEstimate">≈ <?= htmlspecialchars((string)ddFormatEstimate($grandTotal)) ?></span></div>
            <p class="summary-note">You are charged in IDR. Other currencies are estimates.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      </div></div>
    </div></aside>

  </div>

  <!-- ── JAVASCRIPT ──────────────────────────────────── -->
  <script>
  (function () {
    'use strict';

    /* ── toast ──────────────────────────────────────── */
    function showToast(msg) {
      const container = document.getElementById('toastContainer');
      const t = document.createElement('div');
      t.className = 'toast';
      t.textContent = msg;
      container.appendChild(t);
      requestAnimationFrame(() => { requestAnimationFrame(() => { t.classList.add('show'); }); });
      setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 250); }, 2600);
    }

    /* ── PHP vars ───────────────────────────────────── */
    let subtotal                = <?= json_encode((float)$subtotal) ?>;
    const currency              = <?= json_encode($currency) ?>;
    const USD_RATE_FROM_IDR     = <?= defined('USD_RATE_FROM_IDR') ? (float)constant('USD_RATE_FROM_IDR') : 16000 ?>;
    const domesticCouriers      = <?= json_encode($domesticCouriers) ?>;
    const internationalCouriers = <?= json_encode($internationalCouriers) ?>;

    /* ── DOM refs ───────────────────────────────────── */
    const shippingModeEl       = document.getElementById('shipping_mode');
    const destSearch           = document.getElementById('destination_search');
    const destSelect           = document.getElementById('destination_select');
    const courierPicker        = document.getElementById('courier_picker');
    const shippingOptionsEl    = document.getElementById('shippingOptions');
    const countryCodeInput     = document.getElementById('country_code');
    const countryNameInput     = document.getElementById('country_name');
    const courierCodeInput     = document.getElementById('courier_code');
    const courierServiceInput  = document.getElementById('courier_service');
    const shippingCostInput    = document.getElementById('shipping_cost');
    const shippingCostIdrInput = document.getElementById('shipping_cost_idr');
    const shippingTokenInput   = document.getElementById('shipping_token');
    const shippingDestInput    = document.getElementById('shipping_destination');
    const shippingDisplay      = document.getElementById('shippingDisplay');
    const grandTotalDisplay    = document.getElementById('grandTotalDisplay');
    const heroGrandTotal       = document.getElementById('heroGrandTotal');
    const heroGrandTotalTop    = document.getElementById('heroGrandTotalTop');
    const submitBtn            = document.getElementById('submitBtn');

    function formatCurrencyJS(amount) {
      if (currency === 'USD') {
        return '$' + Number(amount).toFixed(2);
      }
      return 'RP ' + Math.round(amount).toLocaleString('id-ID');
    }

    function convertFromIdr(amountIdr) {
      if (currency === 'USD') return Number(amountIdr) / USD_RATE_FROM_IDR;
      return Number(amountIdr);
    }

    function updateGrandTotal(shippingCostIdr) {
      const shippingConverted = convertFromIdr(shippingCostIdr);
      const subtotalConverted = subtotal; 
      const total = subtotalConverted + shippingConverted;
      shippingDisplay.textContent   = formatCurrencyJS(shippingConverted);
      grandTotalDisplay.textContent = formatCurrencyJS(total);
      heroGrandTotal.textContent    = formatCurrencyJS(total);
      const estimateEl = document.getElementById('grandEstimate');
      if (estimateEl && currency === 'IDR') estimateEl.textContent = '≈ ' + window.ddFormatEstimate(total);
      if (heroGrandTotalTop) heroGrandTotalTop.textContent = formatCurrencyJS(total);
    }

    /* ── AJAX QTY UPDATE LOGIC ────────────────────────── */
    window.updateCheckoutQty = async function(psid, change, maxStock) {
        const displayEl = document.getElementById('qty_display_' + psid);
        if (!displayEl) return;
        
        const currentQty = parseInt(displayEl.textContent, 10) || 1;
        const newQty = currentQty + change;

        if (newQty < 1) return;
        if (newQty > maxStock) {
            showToast('Sisa stok hanya ' + maxStock);
            return;
        }

        const btnCtrl = displayEl.parentElement;
        const btns = btnCtrl.querySelectorAll('button');
        btns.forEach(b => b.disabled = true);
        displayEl.style.opacity = '0.5';

        const fd = new FormData();
        fd.append('ajax_update_qty', '1');
        fd.append('product_size_id', psid);
        fd.append('qty', newQty);
        fd.append('checkout_mode', document.querySelector('input[name="checkout_mode"]').value);

        try {
            const res = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                displayEl.textContent = data.new_qty;
                document.getElementById('line_total_display_' + psid).textContent = formatCurrencyJS(data.new_line_total);

                subtotal = parseFloat(data.new_subtotal);
                document.getElementById('subtotalDisplay').textContent = formatCurrencyJS(subtotal);
                
                updateGrandTotal(Number(shippingCostIdrInput.value || 0));

                if (document.querySelector('input[name="checkout_mode"]').value !== 'buy_now') {
                    let lsCart = JSON.parse(localStorage.getItem('ppf_cart') || '[]');
                    let updated = false;
                    for (let i = 0; i < lsCart.length; i++) {
                        if (parseInt(lsCart[i].size_id) === parseInt(psid)) {
                            lsCart[i].qty = newQty;
                            updated = true;
                            break;
                        }
                    }
                    if (updated) {
                        localStorage.setItem('ppf_cart', JSON.stringify(lsCart));
                        const totalCount = lsCart.reduce((sum, item) => sum + item.qty, 0);
                        const hdrEl = document.getElementById('cartCountHeader');
                        if(hdrEl) hdrEl.textContent = totalCount;
                    }
                }
            } else {
                showToast(data.message || 'Update failed');
            }
        } catch (err) {
            showToast('Error connecting to server');
        } finally {
            btns.forEach(b => b.disabled = false);
            displayEl.style.opacity = '1';
        }
    };
    /* ─────────────────────────────────────────────────── */

    /* ── AJAX REMOVE ITEM LOGIC ───────────────────────── */
    window.removeCheckoutItem = async function(psid) {
        if (!confirm('Apakah Anda yakin ingin menghapus produk ini dari keranjang?')) return;
        
        const fd = new FormData();
        fd.append('ajax_remove_item', '1');
        fd.append('product_size_id', psid);
        fd.append('checkout_mode', document.querySelector('input[name="checkout_mode"]').value);

        try {
            const res = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();
            
            if (data.success) {
                if (document.querySelector('input[name="checkout_mode"]').value !== 'buy_now') {
                    let lsCart = JSON.parse(localStorage.getItem('ppf_cart') || '[]');
                    lsCart = lsCart.filter(item => parseInt(item.size_id) !== parseInt(psid));
                    localStorage.setItem('ppf_cart', JSON.stringify(lsCart));
                }
                
                window.location.reload();
            } else {
                showToast('Gagal menghapus produk.');
            }
        } catch (err) {
            showToast('Terjadi kesalahan server saat menghapus.');
        }
    };
    /* ─────────────────────────────────────────────────── */

    function renderCourierOptions() {
      const map = shippingModeEl.value === 'domestic' ? domesticCouriers : internationalCouriers;
      courierPicker.innerHTML = '<option value="">Select courier</option>';
      Object.entries(map).forEach(([code, label]) => {
        const o = document.createElement('option');
        o.value = code; o.textContent = label;
        courierPicker.appendChild(o);
      });
    }

    function resetShipping() {
      shippingOptionsEl.textContent = 'Select destination and courier to see shipping options.';
      courierCodeInput.value = ''; courierServiceInput.value = '';
      shippingCostInput.value = '0'; shippingCostIdrInput.value = '0';
      shippingTokenInput.value = ''; shippingDestInput.value = '';
      updateGrandTotal(0);
    }

    function updateModeUI() {
      const isDom = shippingModeEl.value === 'domestic';
      document.getElementById('destinationSearchLabel').textContent =
        (isDom ? 'City / District' : 'Country') + ' *';
      destSearch.placeholder = isDom ? 'Type city or district...' : 'Type country name...';
      if (isDom) { countryCodeInput.value = 'ID'; countryNameInput.value = 'Indonesia'; }
      else if (countryNameInput.value === 'Indonesia') { countryCodeInput.value = ''; countryNameInput.value = ''; }
      destSearch.value = '';
      destSelect.innerHTML = '<option value="">Choose destination</option>';
      renderCourierOptions();
      resetShipping();
    }

    const destCache = new Map();   // query yang sama tidak dipanggil ulang dalam satu sesi halaman
    let destTimer = null;
    let destSeq = 0;               // abaikan respons lama yang datang terlambat

    function scheduleLoadDestinations() {
      clearTimeout(destTimer);
      destTimer = setTimeout(loadDestinations, 450);
    }

    async function loadDestinations() {
      if (destSearch.value.trim().length < 3) {
        destSelect.innerHTML = '<option value="">Type at least 3 characters</option>';
        return;
      }
      const seq = ++destSeq;
      const endpoint = shippingModeEl.value === 'domestic'
        ? 'api/get-domestic-destinations.php'
        : 'api/get-international-destinations.php';
      try {
        const cacheKeyJs = shippingModeEl.value + '|' + destSearch.value.trim().toLowerCase();
        let result = destCache.get(cacheKeyJs);
        if (!result) {
          const res = await fetch(endpoint + '?search=' + encodeURIComponent(destSearch.value.trim()) + '&limit=10');
          result = await res.json();
          if (result.success) destCache.set(cacheKeyJs, result);
        }
        if (seq !== destSeq) return;
        if (!result.success) { destSelect.innerHTML = '<option value="">Error loading destination</option>'; return; }
        const rows = Array.isArray(result.raw?.data) ? result.raw.data : [];
        destSelect.innerHTML = '<option value="">Choose destination</option>';
        if (!rows.length) { destSelect.innerHTML = '<option value="">No result found</option>'; return; }
        rows.forEach(item => {
          const o = document.createElement('option');
          if (shippingModeEl.value === 'domestic') {
            const id    = item.id || item.subdistrict_id || item.destination_id || item.destination || '';
            const label = item.label || [item.subdistrict_name, item.district_name, item.city_name, item.province_name].filter(Boolean).join(', ') || 'Unknown';
            o.value = id; o.textContent = label; o.dataset.name = label;
          } else {
            o.value = item.country_id || '';
            o.textContent = item.country_name || 'Unknown';
            o.dataset.name = item.country_name || '';
          }
          destSelect.appendChild(o);
        });
      } catch {
        destSelect.innerHTML = '<option value="">Error loading destination</option>';
      }
    }

    destSelect.addEventListener('change', function () {
      const sel = this.options[this.selectedIndex];
      if (shippingModeEl.value === 'domestic') {
        countryCodeInput.value = 'ID'; countryNameInput.value = 'Indonesia';
      } else {
        countryCodeInput.value = sel.value || '';
        countryNameInput.value = sel.dataset.name || '';
      }
      calculateShipping();
    });

    courierPicker.addEventListener('change', calculateShipping);

    async function calculateShipping() {
      const destination = destSelect.value;
      const courier     = courierPicker.value;

      courierCodeInput.value = ''; courierServiceInput.value = '';
      shippingCostInput.value = '0'; shippingCostIdrInput.value = '0';
      shippingTokenInput.value = ''; shippingDestInput.value = '';
      updateGrandTotal(0);

      if (!destination || !courier) {
        shippingOptionsEl.textContent = 'Select destination and courier to see shipping options.';
        return;
      }

      shippingOptionsEl.innerHTML = '<span style="color:#bbb;font-size:9px;text-transform:none;letter-spacing:.04em">Loading shipping options...</span>';

      const endpoint = shippingModeEl.value === 'domestic'
        ? 'api/calculate-domestic-shipping.php'
        : 'api/calculate-shipping.php';

      const fd = new FormData();
      fd.append('destination', destination);
      fd.append('courier', courier);
      fd.append('weight', '1000');
      fd.append('price', 'lowest');

      try {
        const res     = await fetch(endpoint, { method: 'POST', body: fd });
        const rawText = await res.text();
        let result;
        try { result = JSON.parse(rawText); }
        catch { throw new Error('Server error — check shipping endpoint for PHP errors.'); }
        if (!result.success) throw new Error(result.message || 'Failed to calculate shipping.');

        const rows = Array.isArray(result.services) ? result.services : [];
        if (!rows.length) { shippingOptionsEl.textContent = 'No shipping services found.'; return; }

        shippingOptionsEl.innerHTML = '';
        rows.forEach((row, idx) => {
          const costIdr  = Number(row.cost || 0);
          const costShown = convertFromIdr(costIdr);
          const service  = row.service || 'Service';
          const etdText  = row.etd ? `<div class="shipping-service-etd">ETD: ${row.etd}</div>` : '';
          const wrapper  = document.createElement('label');
          wrapper.className = 'shipping-option';
          wrapper.innerHTML = `
            <div class="shipping-option-left">
              <input type="radio" class="shipping-radio" name="shipping_service_option" value="${idx}">
              <div>
                <div class="shipping-service-name">${service}</div>
                <div class="shipping-service-price">${formatCurrencyJS(costShown)}</div>
                ${etdText}
              </div>
            </div>
            <div class="shipping-courier-badge">${courier.toUpperCase()}</div>`;
          wrapper.querySelector('input').addEventListener('change', () => {
            courierCodeInput.value     = courier;
            courierServiceInput.value  = service;
            shippingCostIdrInput.value = costIdr;
            shippingTokenInput.value   = row.token || '';
            shippingDestInput.value    = destination;
            shippingCostInput.value    = currency === 'USD' ? costShown.toFixed(2) : costIdr;
            updateGrandTotal(costIdr);
            showToast(service + ' selected');
          });
          shippingOptionsEl.appendChild(wrapper);
        });
      } catch (err) {
        shippingOptionsEl.innerHTML = `<span style="color:#c0392b;font-size:10px;text-transform:none;letter-spacing:.04em">${err.message}</span>`;
        showToast(err.message);
      }
    }

    document.getElementById('checkoutForm').addEventListener('submit', function () {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Processing...';
    });

    /* ── init ───────────────────────────────────────── */
    shippingModeEl.addEventListener('change', updateModeUI);
    destSearch.addEventListener('input', scheduleLoadDestinations);
    updateModeUI();
    updateGrandTotal(Number(shippingCostInput.value || 0));

  })();
  </script>
  <script>
  (function () {
    var btn = document.getElementById('summaryToggle'), body = document.getElementById('summaryBody');
    if (!btn || !body) return;
    var label = document.getElementById('summaryToggleLabel');
    btn.addEventListener('click', function () {
      var open = body.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (label) label.textContent = open ? 'Hide order summary' : 'Show order summary';
    });
  })();
  </script>
</body>
</html>