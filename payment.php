<?php
session_start();
require_once __DIR__ . '/includes/seo.php';

// Tangkap pilihan currency dari URL (sama seperti halaman lain)
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

require_once __DIR__ . '/config/database.php';

require_once __DIR__ . '/includes/kasera_payment_view.php';

/* ── brand constants ─────────────────────────────────── */
$brandName = 'ALBRYCIA';
$brandLogo = 'assets/images/brand/logo.png';

/* ── load order ──────────────────────────────────────── */
$orderNumber = trim((string)($_GET['order'] ?? ''));
if ($orderNumber === '') die('Order number is required.');

$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :order_number LIMIT 1");
$stmt->execute(['order_number' => $orderNumber]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) die('Order not found.');

// Currency order sudah tersimpan di kolom `orders.currency` (default 'IDR' untuk legacy).
// Sinkronkan dengan session kalau session aktif USD tapi order IDR (atau sebaliknya) —
// supaya display konsisten dengan checkout.
$orderCurrency = strtoupper((string)($order['currency'] ?? 'IDR'));
if (!in_array($orderCurrency, ['IDR', 'USD'], true)) $orderCurrency = 'IDR';

if (isset($_SESSION['currency']) && in_array(strtoupper($_SESSION['currency']), ['IDR', 'USD'])) {
    $currentCurrency = strtoupper($_SESSION['currency']);
} else {
    $currentCurrency = $orderCurrency;
}

$error = '';

$isOrderPaid = strtolower((string)$order['payment_status']) === 'paid';

/* ── polling status (+ sinkron ke Kasera sebagai cadangan webhook) ── */
if (isset($_GET['status'])) {
    respondWithOrderStatus($pdo, $order, isset($_GET['sync']));
}

/* ── pilih metode Kasera (QRIS / VA / e-wallet) ───────── */
if (!$isOrderPaid && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kasera_method'])) {
    $error = startKaseraPayment($pdo, $order, (string)$_POST['kasera_method']);
}

$kasera = loadKaseraView($order, $isOrderPaid);

function formatMoney($amount, string $currency = 'IDR'): string
{
    if ($currency === 'USD') {
        return '$' . number_format((float)$amount, 2, '.', ',');
    }
    return 'RP ' . number_format((float)$amount, 0, ',', '.');
}

// Rate konversi USD (sinkron dengan checkout.php)
$usdRateFromIdr = ddUsdToIdrRate();

// Kalau session currency berbeda dari orderCurrency, konversi nilai display
// (DB tetap menyimpan nilai asli sesuai currency order — tidak diubah).
function convertForDisplay(float $amount, string $fromCurrency, string $toCurrency, float $rate): float
{
    if ($fromCurrency === $toCurrency) return $amount;
    if ($fromCurrency === 'IDR' && $toCurrency === 'USD') return $amount / $rate;
    if ($fromCurrency === 'USD' && $toCurrency === 'IDR') return $amount * $rate;
    return $amount;
}

$subtotalDisplay = convertForDisplay((float)($order['subtotal'] ?? 0), $orderCurrency, $currentCurrency, $usdRateFromIdr);
$shippingDisplay = convertForDisplay((float)($order['shipping_cost'] ?? 0), $orderCurrency, $currentCurrency, $usdRateFromIdr);
$grandDisplay    = convertForDisplay((float)($order['grand_total'] ?? 0), $orderCurrency, $currentCurrency, $usdRateFromIdr);

$paymentStatus   = strtoupper(str_replace('_', ' ', $order['payment_status']));
$grandTotalFmt   = formatMoney($grandDisplay, $currentCurrency);
$orderNum        = htmlspecialchars($order['order_number']);

// Item order untuk ringkasan (sama seperti panel kanan di checkout)
$itemStmt = $pdo->prepare('SELECT product_name, size_name, quantity, line_total, thumbnail FROM order_items WHERE order_id = :oid ORDER BY id ASC');
$itemStmt->execute(['oid' => (int)$order['id']]);
$orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php seoHead(['title' => 'Payment — ' . BRAND_NAME, 'path' => 'payment', 'noindex' => true]); ?>
  <style>
:root{--ink:#1a1a1a;--muted:#707070;--line:#d9d9d9;--soft:#f5f5f5;--panel:#fafafa;--radius:10px;--ease:cubic-bezier(.32,.72,0,1)}
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:#fff;color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;line-height:1.45;animation:pageFadeIn .3s ease forwards;-webkit-font-smoothing:antialiased}
a{color:inherit}
img{display:block;max-width:100%}
button,input,select,textarea{font:inherit;color:inherit}
button{cursor:pointer;-webkit-tap-highlight-color:transparent}

/* ── layout (sama dengan checkout) ──────────────────── */
.checkout-wrapper{display:grid;grid-template-columns:minmax(0,1.12fr) minmax(0,1fr);min-height:100vh}
.left-col{display:flex;justify-content:flex-end;padding:32px 48px 56px}
.left-inner{width:100%;max-width:560px}
.right-col{background:var(--panel);border-left:1px solid #e1e1e1;padding:40px 48px}
.right-inner{width:100%;max-width:460px;position:sticky;top:32px}
.co-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px}
.brand-mark img{height:52px;width:auto;border-radius:50%}
.back-link{font-size:13px;color:var(--muted);text-decoration:none}
.back-link:hover{color:var(--ink);text-decoration:underline}

.sec{margin-bottom:30px}
.sec h2{font-size:21px;font-weight:500;letter-spacing:-.01em;margin-bottom:14px}
.sec-sub{color:var(--muted);font-size:14px;margin:-8px 0 14px}

/* ── meta strip → kartu lembut ──────────────────────── */
.meta-strip{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:30px}
.meta-cell{padding:14px 16px;border-left:1px solid var(--line);min-width:0}
.meta-cell:first-child{border-left:none}
.meta-label{font-size:12px;color:var(--muted);display:block;margin-bottom:3px}
.meta-value{font-size:14px;font-weight:600;word-break:break-word}

/* ── notices ────────────────────────────────────────── */
.notice{padding:12px 14px;font-size:14px;border-radius:var(--radius);margin-bottom:18px;line-height:1.5}
.notice.error{color:#b3261e;background:#fdecea;border:1px solid #f5c6c2}
.notice.warn{color:#7a5b00;background:#fff8e1;border:1px solid #f1e0a6}

/* ── panel & accordion ──────────────────────────────── */
.panel{border:1px solid var(--line);border-radius:var(--radius);background:#fff;overflow:hidden;margin-bottom:18px}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px;border-bottom:1px solid var(--line);font-size:15px;font-weight:500}
.panel-step{color:var(--muted);font-weight:400}
.panel-title{flex:1}
.panel-body{padding:18px 16px}
.panel > .notice{margin:12px 16px 0}

.accordion-item{border-top:1px solid var(--line)}
.accordion-item:first-child{border-top:none}
.accordion-trigger{width:100%;min-height:56px;border:0;background:transparent;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 16px;font-size:15px;text-align:left;transition:background .12s}
.accordion-trigger:hover{background:var(--soft)}
.accordion-trigger:active{background:#ececec}
.accordion-left{display:flex;align-items:center;gap:12px}
.accordion-arrow{font-size:14px;color:var(--muted);line-height:1;transition:transform .35s var(--ease)}
.accordion-item.open .accordion-arrow{transform:rotate(180deg)}
.accordion-content{display:grid;grid-template-rows:0fr;transition:grid-template-rows .35s var(--ease)}
.accordion-item.open .accordion-content{grid-template-rows:1fr}
.accordion-content > *{min-height:0;overflow:hidden;padding:0 16px;opacity:0;visibility:hidden;transition:opacity .25s ease,visibility 0s .35s}
.accordion-item.open .accordion-content > *{opacity:1;visibility:visible;transition:opacity .25s ease}
.accordion-content > :first-child{margin-top:0}
.accordion-item.open .accordion-content > :last-child{padding-bottom:18px}

.upload-note{font-size:14px;color:var(--muted);line-height:1.55;margin-bottom:14px}

/* ── pembayaran aktif (QR / VA) ─────────────────────── */
.bank-row{display:grid;grid-template-columns:130px 1fr;gap:12px;padding:12px 0;border-top:1px solid #ececec;align-items:baseline}
.bank-row:first-child{border-top:none;padding-top:0}
.bank-key{font-size:13px;color:var(--muted)}
.bank-value{font-size:17px;font-weight:600;letter-spacing:.01em;word-break:break-all}
.copy-row{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap}
.copy-status{margin-top:8px;font-size:13px;color:#1e6b34;min-height:18px}
.countdown{font-size:15px;font-weight:600;font-variant-numeric:tabular-nums;color:var(--ink)}
.countdown.warn{color:#b3261e}
.qr-wrap{display:flex;flex-direction:column;align-items:center;gap:12px;margin:18px 0}
#qrbox canvas,#qrbox img{width:240px!important;height:240px!important;border:1px solid var(--line);border-radius:var(--radius);padding:10px;background:#fff}
.va-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.va-tile{cursor:pointer;position:relative}
.va-tile input{position:absolute;opacity:0;pointer-events:none}
.va-tile .tile{display:flex;align-items:center;justify-content:center;height:54px;border:1px solid var(--line);border-radius:var(--radius);padding:6px;font-size:13px;text-align:center;background:#fff;transition:border-color .12s,box-shadow .12s,transform .1s}
.va-tile:active .tile{transform:scale(.97)}
.va-tile input:checked + .tile{border-color:var(--ink);box-shadow:0 0 0 1px var(--ink);background:var(--soft)}
.va-tile input:focus-visible + .tile{box-shadow:0 0 0 2px #2a5db0}
.bank-logo{max-height:28px;max-width:100%;object-fit:contain}

/* ── buttons ────────────────────────────────────────── */
.btn-primary{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:56px;padding:0 24px;background:var(--ink);color:#fff;border:none;border-radius:var(--radius);font-size:15px;font-weight:600;transition:background .15s,transform .1s;text-decoration:none}
.btn-primary:hover{background:#000}
.btn-primary:active{transform:scale(.985)}
.btn-primary:disabled{background:#cfcfcf;cursor:not-allowed;transform:none}
.btn-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;background:#fff;color:var(--ink);border:1px solid var(--line);border-radius:var(--radius);font-size:14px;transition:border-color .15s,transform .1s;text-decoration:none}
.btn-secondary:hover{border-color:var(--ink)}
.btn-secondary:active{transform:scale(.97)}
.secure-note{font-size:12px;color:var(--muted);margin-top:12px}

/* ── ringkasan kanan (sama dengan checkout) ─────────── */
.summary-toggle{display:none}
.summary-body{display:grid;grid-template-rows:1fr}
.summary-inner{min-height:0;overflow:visible}
.cart-item-row{display:grid;grid-template-columns:64px 1fr auto;gap:14px;align-items:center;margin-bottom:16px}
.cart-thumb-wrap{position:relative;width:64px;height:64px}
.cart-thumb{width:64px;height:64px;object-fit:contain;border-radius:12px;border:1px solid #e6e6e6;background:#fff;padding:3px}
.cart-qty-badge{position:absolute;top:-8px;right:-8px;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:var(--ink);color:#fff;font-size:12px;display:flex;align-items:center;justify-content:center}
.cart-item-name{font-size:14px;font-weight:500;line-height:1.3}
.cart-item-meta{font-size:12.5px;color:var(--muted);margin-top:2px}
.cart-item-price{font-size:14px;text-align:right}
.totals-block{margin-top:22px;padding-top:6px}
.total-row{display:flex;justify-content:space-between;align-items:baseline;padding:5px 0;font-size:14px}
.total-row span:last-child{text-align:right}
.total-row.muted span:last-child{color:var(--muted)}
.total-row.grand{padding-top:14px;font-size:19px;font-weight:500}
.total-row.grand .cur{font-size:12px;color:var(--muted);margin-right:6px;font-weight:400}
.total-row.grand strong{font-size:22px;font-weight:600}
.summary-note{font-size:12px;color:var(--muted);margin-top:8px}
.steps-block{margin-top:26px;padding-top:20px;border-top:1px solid #e6e6e6}
.steps-block h3{font-size:15px;font-weight:500;margin-bottom:12px}
.steps-list{list-style:none;counter-reset:step;display:flex;flex-direction:column;gap:12px}
.steps-list li{counter-increment:step;display:grid;grid-template-columns:26px 1fr;gap:12px;align-items:start;font-size:13px;color:#555;line-height:1.55}
.steps-list li::before{content:counter(step);width:26px;height:26px;border-radius:50%;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600}
.panel-body .steps-list{margin-top:18px;padding-top:16px;border-top:1px solid #ececec}

.footer{margin-top:36px;font-size:12px;color:var(--muted)}

@media(max-width:900px){
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
  .summary-toggle .chev{transition:transform .4s var(--ease)}
  .summary-toggle[aria-expanded=true] .chev{transform:rotate(180deg)}
  .summary-toggle-r{display:inline-flex;align-items:baseline;gap:6px;color:var(--ink)}
  .summary-toggle-r .cur{font-size:11px;color:var(--muted)}
  .summary-toggle-r strong{font-size:17px;font-weight:600}
  .summary-body{grid-template-rows:0fr;transition:grid-template-rows .4s var(--ease)}
  .summary-body.is-open{grid-template-rows:1fr}
  .summary-inner{overflow:hidden;padding:0 20px;opacity:0;transition:opacity .25s ease}
  .summary-body.is-open .summary-inner{opacity:1;padding-bottom:20px;padding-top:4px}
  .summary-body:not(.is-open) .summary-inner{visibility:hidden;transition:opacity .2s ease,visibility 0s .4s}
  .totals-block{border-top:1px solid #e6e6e6;padding-top:12px}
}
@media(max-width:560px){
  .meta-strip{grid-template-columns:1fr}
  .meta-cell{border-left:none;border-top:1px solid var(--line)}
  .meta-cell:first-child{border-top:none}
  .bank-row{grid-template-columns:1fr;gap:2px}
  .copy-row{flex-direction:column}
  .btn-secondary{width:100%}
  .va-grid{grid-template-columns:repeat(2,1fr)}
}
@media (prefers-reduced-motion:reduce){
  *,*::before,*::after{transition-duration:.01ms!important;animation-duration:.01ms!important}
}
@keyframes pageFadeIn{from{opacity:0}to{opacity:1}}
  </style>
  <link rel="stylesheet" href="assets/css/apple.css?v=<?= @filemtime(__DIR__ . '/assets/css/apple.css') ?>">
</head>
<body>
  <?php if (file_exists(__DIR__ . '/loader.php')) include_once 'loader.php'; ?>

  <div class="checkout-wrapper">

    <!-- ── LEFT: PAYMENT ─────────────────────────────── -->
    <div class="left-col"><div class="left-inner">

      <header class="co-head">
        <a href="index.php" class="brand-mark"><img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?>"></a>
        <a href="shop.php" class="back-link">← Shop</a>
      </header>

      <?php if ($error !== ''): ?>
        <div class="notice error" role="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($orderCurrency !== $currentCurrency): ?>
        <div class="notice warn" role="status">
          This order was created in <strong><?= htmlspecialchars($orderCurrency) ?></strong>. Values are converted to <strong><?= htmlspecialchars($currentCurrency) ?></strong> for display only — please pay the original amount in <?= htmlspecialchars($orderCurrency) ?> as stored in our records.
        </div>
      <?php endif; ?>

      <section class="sec" aria-label="Payment">
        <h2>Payment</h2>

        <div class="meta-strip">
          <div class="meta-cell">
            <span class="meta-label">Order</span>
            <span class="meta-value"><?= $orderNum ?></span>
          </div>
          <div class="meta-cell">
            <span class="meta-label">Total</span>
            <span class="meta-value"><?= htmlspecialchars($grandTotalFmt) ?></span>
          </div>
          <div class="meta-cell">
            <span class="meta-label">Status</span>
            <span class="meta-value"><?= htmlspecialchars($paymentStatus) ?></span>
          </div>
        </div>

        <?php include __DIR__ . '/includes/kasera_payment_panel.php'; ?>

        <p class="secure-note">🔒 Your data is encrypted and secure. We never store your payment details.</p>
      </section>

      <footer class="footer" aria-label="Site footer">© <?= date('Y') ?> <?= htmlspecialchars($brandName) ?>. All rights reserved.</footer>
    </div></div>

    <!-- ── RIGHT: SUMMARY ────────────────────────────── -->
    <aside class="right-col" aria-label="Order summary"><div class="right-inner">
      <button type="button" class="summary-toggle" id="summaryToggle" aria-expanded="false" aria-controls="summaryBody">
        <span class="summary-toggle-l"><span id="summaryToggleLabel">Show order summary</span>
          <svg class="chev" width="12" height="8" viewBox="0 0 12 8" aria-hidden="true"><path d="M1 1.5l5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></span>
        <span class="summary-toggle-r"><span class="cur"><?= htmlspecialchars($currentCurrency) ?></span><strong><?= htmlspecialchars($grandTotalFmt) ?></strong></span>
      </button>

      <div class="summary-body" id="summaryBody"><div class="summary-inner">
        <?php foreach ($orderItems as $item): ?>
          <div class="cart-item-row">
            <div class="cart-thumb-wrap">
              <?php if (!empty($item['thumbnail'])): ?>
                <img class="cart-thumb" src="<?= htmlspecialchars((string)$item['thumbnail']) ?>" alt="<?= htmlspecialchars((string)$item['product_name']) ?>" loading="lazy">
              <?php endif; ?>
              <span class="cart-qty-badge"><?= (int)$item['quantity'] ?></span>
            </div>
            <div>
              <div class="cart-item-name"><?= htmlspecialchars((string)$item['product_name']) ?></div>
              <?php if (!empty($item['size_name'])): ?>
                <div class="cart-item-meta"><?= htmlspecialchars((string)$item['size_name']) ?></div>
              <?php endif; ?>
            </div>
            <div class="cart-item-price"><?= htmlspecialchars(formatMoney(convertForDisplay((float)$item['line_total'], $orderCurrency, $currentCurrency, $usdRateFromIdr), $currentCurrency)) ?></div>
          </div>
        <?php endforeach; ?>

        <div class="totals-block">
          <div class="total-row"><span>Subtotal</span><span><?= htmlspecialchars(formatMoney($subtotalDisplay, $currentCurrency)) ?></span></div>
          <div class="total-row"><span>Shipping</span><span><?= htmlspecialchars(formatMoney($shippingDisplay, $currentCurrency)) ?></span></div>
          <div class="total-row grand"><span>Total</span><span><span class="cur"><?= htmlspecialchars($currentCurrency) ?></span><strong><?= htmlspecialchars($grandTotalFmt) ?></strong></span></div>
          <div class="total-row muted"><span>Order</span><span><?= $orderNum ?></span></div>
        </div>

        <div class="steps-block">
          <h3>How it works</h3>
          <ol class="steps-list">
            <li>Choose QRIS, a bank virtual account, or another method.</li>
            <li>Pay the exact total shown.</li>
            <li>Your payment is confirmed automatically — no receipt upload needed.</li>
          </ol>
        </div>
      </div></div>
    </div></aside>

  </div>

  <?php include __DIR__ . '/includes/kasera_payment_script.php'; ?>

  <script>
  (function () {
    'use strict';

    document.querySelectorAll('.accordion-item').forEach(item => {
      item.querySelector('.accordion-trigger').addEventListener('click', function () {
        const isOpen = item.classList.contains('open');
        document.querySelectorAll('.accordion-item').forEach(i => {
          i.classList.remove('open');
          i.querySelector('.accordion-trigger').setAttribute('aria-expanded', 'false');
        });
        if (!isOpen) {
          item.classList.add('open');
          this.setAttribute('aria-expanded', 'true');
        }
      });
    });

    var btn = document.getElementById('summaryToggle'), body = document.getElementById('summaryBody');
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
