<?php
session_start();

// Tangkap pilihan currency dari URL (sama seperti halaman lain)
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

require_once __DIR__ . '/config/database.php';

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

require_once __DIR__ . '/includes/email_helpers.php';

/* ── brand constants ─────────────────────────────────── */
$brandName = 'PASTPRESENTFURTHER';
$brandLogo = '11newlogo.PNG';

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

/* ── handle upload (bank / QRIS) ─────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payment_proof'])) {
    if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload payment proof.';
    } else {
        $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
        $tmpPath = $_FILES['payment_proof']['tmp_name'];
        $mime    = mime_content_type($tmpPath);

        if (!isset($allowedMime[$mime])) {
            $error = 'Only JPG, PNG, or PDF files are allowed.';
        } else {
            $ext          = $allowedMime[$mime];
            $dir          = __DIR__ . '/uploads/payment-proofs';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $filename     = 'proof-' . $order['order_number'] . '-' . time() . '.' . $ext;
            $absolutePath = $dir . '/' . $filename;
            $relativePath = 'uploads/payment-proofs/' . $filename;

            if (!move_uploaded_file($tmpPath, $absolutePath)) {
                $error = 'Failed to save uploaded file.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $pdo->prepare("
                        UPDATE orders
                        SET payment_proof_path = :p,
                            payment_status      = 'waiting_verification',
                            updated_at          = NOW()
                        WHERE id = :id
                    ")->execute([
                        'p'  => $relativePath,
                        'id' => $order['id']
                    ]);

                    $pdo->prepare("
                        INSERT INTO order_status_logs (order_id, status_type, status_value, notes)
                        VALUES (:oid, :st, :sv, :n)
                    ")->execute([
                        'oid' => $order['id'],
                        'st'  => 'payment',
                        'sv'  => 'waiting_verification',
                        'n'   => 'Customer uploaded payment proof.'
                    ]);

                    $pdo->commit();

                    $updStmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
                    $updStmt->execute(['id' => $order['id']]);
                    $updatedOrder = $updStmt->fetch(PDO::FETCH_ASSOC) ?: $order;

                    $emailSent = sendPPFInvoiceEmail($pdo, $updatedOrder);
                    header(
                        'Location: index.php?payment_uploaded=1&invoice_email='
                        . ($emailSent ? 'sent' : 'failed')
                        . '&order=' . urlencode($orderNumber)
                    );
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Failed to process payment proof: ' . $e->getMessage();
                }
            }
        }
    }
}

function formatMoney($amount, string $currency = 'IDR'): string
{
    if ($currency === 'USD') {
        return '$' . number_format((float)$amount, 2, '.', ',');
    }
    return 'RP ' . number_format((float)$amount, 0, ',', '.');
}

// Rate konversi USD (sinkron dengan checkout.php)
$usdRateFromIdr = defined('USD_RATE_FROM_IDR') ? (float)constant('USD_RATE_FROM_IDR') : 18000;

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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment Confirmation — <?= htmlspecialchars($brandName) ?></title>
  <style>
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:#fff;color:#111;font-family:Helvetica,Helvetica,Arial,sans-serif;text-transform:uppercase;animation:pageFadeIn .4s ease forwards;letter-spacing:.04em}
a{color:inherit;text-decoration:none}
img{display:block}
button,input,select,textarea{font:inherit}
button{cursor:pointer}

.navbar{width:100%;height:44px;background:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:60;border-bottom:1px solid #f0f0f0}
.nav-left{display:flex;align-items:center}
.nav-center{position:absolute;left:50%;transform:translateX(-50%);display:flex;align-items:center}
.nav-right{display:flex;align-items:center;gap:20px}
.brand-mark img{height:32px;width:auto;display:block;border-radius:50%}
.nav-btn{background:none;border:none;font-size:10px;letter-spacing:.12em;color:#111;cursor:pointer;text-transform:uppercase}

.breadcrumb{padding:8px 28px;font-size:9px;letter-spacing:.1em;color:#bbb;display:flex;gap:8px;align-items:center;border-bottom:1px solid #f0f0f0}
.breadcrumb a{color:#bbb;transition:color .15s}
.breadcrumb a:hover{color:#111}

.section-head{display:flex;justify-content:space-between;align-items:center;padding:14px 28px;font-size:10px;letter-spacing:.1em;border-bottom:1px solid #f0f0f0;border-top:1px solid #f0f0f0}

.notice{padding:12px 28px;font-size:10px;letter-spacing:.06em;text-transform:none;line-height:1.6;border-bottom:1px solid #f0f0f0}
.notice.error{color:#c0392b;background:#fdf0f0}

.payment-wrapper{display:grid;grid-template-columns:1fr 340px;border-top:none}
.left-col{border-right:1px solid #f0f0f0}
.right-col{position:sticky;top:44px;height:fit-content;max-height:calc(100vh - 44px);overflow-y:auto}

.panel{border-bottom:1px solid #f0f0f0}
.panel-head{display:flex;justify-content:space-between;align-items:center;padding:14px 28px;font-size:10px;letter-spacing:.1em;border-bottom:1px solid #f0f0f0}
.panel-step{color:#bbb}
.panel-title{color:#111}
.panel-body{padding:24px 28px}

.meta-strip{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid #f0f0f0}
.meta-cell{padding:16px 28px;border-right:1px solid #f0f0f0}
.meta-cell:last-child{border-right:none}
.meta-label{font-size:9px;letter-spacing:.14em;color:#bbb;display:block;margin-bottom:6px}
.meta-value{font-size:11px;letter-spacing:.06em;font-weight:700;word-break:break-word}

.accordion-item{border-bottom:1px solid #f0f0f0}
.accordion-item:last-child{border-bottom:none}
.accordion-trigger{width:100%;min-height:56px;border:0;background:transparent;display:flex;align-items:center;justify-content:space-between;padding:0 28px;cursor:pointer;font-size:10px;letter-spacing:.1em;color:#111;font-family:inherit;text-transform:uppercase;transition:background .15s}
.accordion-trigger:hover{background:#fafafa}
.accordion-left{display:flex;align-items:center;gap:14px}
.accordion-icon{width:52px;height:32px;object-fit:contain;border:1px solid #f0f0f0;padding:5px;flex-shrink:0;background:#fafafa}
.accordion-arrow{font-size:12px;color:#bbb;transition:transform .2s ease;line-height:1}
.accordion-item.open .accordion-arrow{transform:rotate(180deg)}
.accordion-content{display:none;padding:0 28px 22px}
.accordion-item.open .accordion-content{display:block}

.bank-row{display:grid;grid-template-columns:120px 1fr;gap:12px;padding:10px 0;border-top:1px solid #f0f0f0}
.bank-row:first-child{border-top:none;padding-top:0}
.bank-key{font-size:9px;letter-spacing:.12em;color:#bbb;padding-top:2px}
.bank-value{font-size:13px;font-weight:700;letter-spacing:.04em;word-break:break-all}
.copy-row{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap}
.copy-status{margin-top:6px;font-size:9px;color:#2e7d32;letter-spacing:.08em;min-height:14px}

.qris-label{font-size:9px;letter-spacing:.1em;color:#bbb;text-align:center;margin-bottom:14px}
.qris-img{width:min(100%,240px);display:block;margin:0 auto;border:1px solid #f0f0f0;background:#fff;padding:1px}

.upload-note{font-size:10px;letter-spacing:.04em;color:#888;text-transform:none;line-height:1.7;margin-bottom:18px}
.upload-zone{border:1px dashed #ddd;padding:20px 16px;margin-bottom:14px;transition:border-color .2s,background .2s}
.upload-zone:hover{border-color:#aaa;background:#fafafa}
.file-label{display:block;font-size:9px;letter-spacing:.14em;color:#bbb;margin-bottom:10px}
.file-input{width:100%;padding:10px 12px;border:1px solid #ddd;background:#fff;font-size:10px;color:#111;text-transform:none;letter-spacing:.04em;cursor:pointer;outline:none}
.file-input::-webkit-file-upload-button{background:#111;color:#fff;border:none;padding:7px 14px;font-size:9px;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;font-family:inherit;margin-right:12px}
.file-preview{margin-top:8px;font-size:9px;color:#888;letter-spacing:.06em;text-transform:none;min-height:14px}
.upload-hint{font-size:9px;color:#ccc;letter-spacing:.04em;text-transform:none;line-height:1.6;margin-top:8px}

.btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 28px;background:#111;color:#fff;border:none;font-size:10px;letter-spacing:.14em;cursor:pointer;transition:background .15s;text-transform:uppercase;width:100%}
.btn-primary:hover{background:#000}
.btn-primary:disabled{background:#ddd;cursor:not-allowed}
.btn-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 18px;background:transparent;color:#111;border:1px solid #ddd;font-size:10px;letter-spacing:.1em;cursor:pointer;transition:border-color .15s;text-transform:uppercase}
.btn-secondary:hover{border-color:#111}
.submit-note{margin-top:10px;font-size:9px;color:#ccc;text-transform:none;letter-spacing:.04em;line-height:1.6}

.summary-head{display:flex;justify-content:space-between;align-items:center;padding:14px 28px;font-size:10px;letter-spacing:.1em;border-bottom:1px solid #f0f0f0}
.summary-body{padding:20px 28px}
.total-row{display:flex;justify-content:space-between;font-size:10px;padding:5px 0;color:#888;letter-spacing:.06em}
.total-row.grand{color:#111;padding-top:12px;border-top:1px solid #f0f0f0;margin-top:8px;letter-spacing:.08em}

.steps-list{list-style:none;counter-reset:step;display:flex;flex-direction:column;gap:12px}
.steps-list li{counter-increment:step;display:grid;grid-template-columns:28px 1fr;gap:12px;align-items:start;font-size:10px;color:#555;text-transform:none;letter-spacing:.04em;line-height:1.6}
.steps-list li::before{content:counter(step,decimal-leading-zero);width:28px;height:28px;background:#111;color:#fff;display:flex;align-items:center;justify-content:center;font-size:9px;letter-spacing:.1em;flex-shrink:0}

.toast-container{position:fixed;bottom:24px;right:24px;z-index:200;display:flex;flex-direction:column;gap:6px;pointer-events:none}
.toast{background:#111;color:#fff;padding:10px 16px;font-size:10px;letter-spacing:.1em;opacity:0;transform:translateY(8px);transition:opacity .2s,transform .2s;pointer-events:none;max-width:260px}
.toast.show{opacity:1;transform:translateY(0)}

.footer{padding:28px;border-top:1px solid #f0f0f0}
.footer-copy{font-size:9px;color:#bbb;letter-spacing:.08em;text-transform:none;text-align:center}

#paypal-button-container{margin-top:10px}
#paypal-button-container iframe{max-width:100%}
.paypal-info{font-size:10px;letter-spacing:.04em;color:#666;text-transform:none;line-height:1.7}

@media(max-width:900px){
  .payment-wrapper{grid-template-columns:1fr}
  .left-col{border-right:none}
  .right-col{position:static;max-height:none;border-top:1px solid #f0f0f0}
  .meta-strip{grid-template-columns:1fr 1fr}
  .meta-cell:nth-child(3){border-top:1px solid #f0f0f0;border-right:none}
}
@media(max-width:560px){
  .navbar{padding:0 18px}
  .breadcrumb{padding:8px 18px}
  .section-head{padding:12px 18px}
  .panel-head{padding:12px 18px}
  .panel-body{padding:18px 18px}
  .accordion-trigger{padding:0 18px}
  .accordion-content{padding:0 18px 18px}
  .summary-head{padding:12px 18px}
  .summary-body{padding:16px 18px}
  .notice{padding:10px 18px}
  .meta-strip{grid-template-columns:1fr}
  .meta-cell{border-right:none!important;border-top:1px solid #f0f0f0}
  .meta-cell:first-child{border-top:none}
  .bank-row{grid-template-columns:1fr;gap:4px}
  .copy-row{flex-direction:column}
  .btn-secondary{width:100%}
  .toast-container{right:12px;bottom:12px;left:12px}
  .toast{max-width:100%}
  .footer{padding:20px 18px}
}
@keyframes pageFadeIn{from{opacity:0}to{opacity:1}}
  </style>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/loader.php')) include_once 'loader.php'; ?>

  <div class="toast-container" id="toastContainer" aria-live="polite"></div>

  <header class="navbar">
    <div class="nav-left">
      <a href="cart.php" class="nav-btn">← Cart</a>
    </div>
    <div class="nav-center">
      <a href="index.php" class="brand-mark">
        <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?>">
      </a>
    </div>
    <div class="nav-right">
      <a href="index.php" class="nav-btn">Shop</a>
    </div>
  </header>

  <nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="index.php">Home</a>
    <span>›</span>
    <a href="cart.php">Cart</a>
    <span>›</span>
    <a href="checkout.php">Checkout</a>
    <span>›</span>
    <strong>Payment</strong>
  </nav>

  <div class="section-head">
    <span>Payment Confirmation</span>
    <span><?= htmlspecialchars($grandTotalFmt) ?></span>
  </div>

  <?php if ($error !== ''): ?>
    <div class="notice error" role="alert"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($orderCurrency !== $currentCurrency): ?>
    <div class="notice" style="background:#fff8e1;color:#7a5b00" role="status">
      This order was created in <strong><?= htmlspecialchars($orderCurrency) ?></strong>. Values shown are converted to <strong><?= htmlspecialchars($currentCurrency) ?></strong> for display only — please pay the original amount in <?= htmlspecialchars($orderCurrency) ?> as stored in our records.
    </div>
  <?php endif; ?>

  <div class="meta-strip">
    <div class="meta-cell">
      <span class="meta-label">Order Number</span>
      <span class="meta-value"><?= $orderNum ?></span>
    </div>
    <div class="meta-cell">
      <span class="meta-label">Grand Total</span>
      <span class="meta-value"><?= htmlspecialchars($grandTotalFmt) ?></span>
    </div>
    <div class="meta-cell">
      <span class="meta-label">Payment Status</span>
      <span class="meta-value"><?= htmlspecialchars($paymentStatus) ?></span>
    </div>
  </div>

  <div class="payment-wrapper">
    <div class="left-col">

      <section class="panel" aria-label="Payment options">
        <div class="panel-head">
          <span class="panel-step">01</span>
          <span class="panel-title">Payment Options</span>
        </div>

        <div class="accordion">

          <div class="accordion-item open">
            <button type="button" class="accordion-trigger" aria-expanded="true">
              <div class="accordion-left">
                <img src="permata.png" alt="BNI" class="accordion-icon" onerror="this.style.opacity='.2'">
                <span>Permata Bank</span>
              </div>
              <span class="accordion-arrow" aria-hidden="true">⌄</span>
            </button>
            <div class="accordion-content">
              <div class="bank-row">
                <div class="bank-key">Account No.</div>
                <div class="bank-value">9996904145</div>
              </div>
              <div class="bank-row">
                <div class="bank-key">Account Name</div>
                <div class="bank-value">Diki Febriansyah</div>
              </div>
              <div class="copy-row">
                <button type="button" class="btn-secondary" data-copy="12345678" data-target="bni-status">Copy Number</button>
              </div>
              <div class="copy-status" id="bni-status"></div>
            </div>
          </div>


          <div class="accordion-item">
            <button type="button" class="accordion-trigger" aria-expanded="false">
              <div class="accordion-left">
                <img src="qris-logo.png" alt="QRIS" class="accordion-icon" onerror="this.style.opacity='.2'">
                <span>QRIS Scan</span>
              </div>
              <span class="accordion-arrow" aria-hidden="true">⌄</span>
            </button>
            <div class="accordion-content">
              <p class="qris-label">Scan via mobile banking or e-wallet</p>
              <img src="qris.jpg" alt="QRIS barcode" class="qris-img">
            </div>
          </div>

          <div class="accordion-item">
            <button type="button" class="accordion-trigger" aria-expanded="false">
              <div class="accordion-left">
                <img src="paypal.png" alt="PayPal" class="accordion-icon" onerror="this.style.opacity='.2'">
                <span>PayPal</span>
              </div>
              <span class="accordion-arrow" aria-hidden="true">⌄</span>
            </button>
            <div class="accordion-content">
              <p class="paypal-info">Pay with PayPal securely. When payment is successful, your order will be marked as paid automatically.</p>
              <div id="paypal-button-container"></div>
              <div class="file-preview" id="paypalPreview">PayPal button will appear here.</div>
            </div>
          </div>

        </div>
      </section>

      <section class="panel" aria-label="Upload payment proof">
        <div class="panel-head">
          <span class="panel-step">02</span>
          <span class="panel-title">Upload Payment Proof</span>
        </div>
        <div class="panel-body">
          <p class="upload-note">Upload a clear receipt or screenshot showing the transfer amount, date, and reference number.</p>
          <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-zone">
              <label for="payment_proof" class="file-label">Select File (JPG, PNG, or PDF)</label>
              <input
                type="file"
                name="payment_proof"
                id="payment_proof"
                class="file-input"
                accept=".jpg,.jpeg,.png,.pdf"
                required
              >
              <div class="file-preview" id="filePreview">No file selected</div>
              <p class="upload-hint">Max file size: 10MB. Amount, date, and reference number must be clearly visible.</p>
            </div>
            <button type="submit" class="btn-primary" id="submitBtn">Submit Payment Proof →</button>
          </form>
          <p class="submit-note">After submission, your payment status will change to "Waiting Verification". We'll notify you once confirmed.</p>
        </div>
      </section>

    </div>

    <aside class="right-col" aria-label="Order summary">
      <div class="summary-head">
        <span>Summary</span>
        <span><?= htmlspecialchars($grandTotalFmt) ?></span>
      </div>
      <div class="summary-body">
        <div class="total-row">
          <span>Order</span>
          <span><?= $orderNum ?></span>
        </div>
        <div class="total-row">
          <span>Subtotal</span>
          <span><?= htmlspecialchars(formatMoney($subtotalDisplay, $currentCurrency)) ?></span>
        </div>
        <div class="total-row">
          <span>Shipping</span>
          <span><?= htmlspecialchars(formatMoney($shippingDisplay, $currentCurrency)) ?></span>
        </div>
        <div class="total-row grand">
          <span>Total</span>
          <span><?= htmlspecialchars($grandTotalFmt) ?></span>
        </div>
        <div class="total-row" style="margin-top:8px">
          <span>Status</span>
          <span><?= htmlspecialchars($paymentStatus) ?></span>
        </div>

        <div style="margin-top:24px;border-top:1px solid #f0f0f0;padding-top:20px">
          <div style="font-size:9px;letter-spacing:.14em;color:#bbb;margin-bottom:14px">Instructions</div>
          <ol class="steps-list">
            <li>Pay with Bank Transfer, QRIS and PayPal.</li>
            <li>For bank transfer or QRIS, transfer the exact total amount shown above.</li>
            <li>For manual payment, save a clear screenshot of your receipt.</li>
            <li>Upload proof using the form on the left for bank transfer or QRIS.</li>
            <li>For PayPal, your payment will be verified automatically after success.</li>
          </ol>
        </div>

        <p style="margin-top:20px;font-size:9px;color:#ccc;letter-spacing:.04em;text-transform:none;line-height:1.6">🔒 Your data is encrypted and secure.</p>
      </div>
    </aside>
  </div>

  <footer class="footer" aria-label="Site footer">
    <p class="footer-copy">&copy; <?= date('Y') ?> PASTPRESENTFURTHER — S.L Village Klapanunggal Bogor West Java, 16871</p>
  </footer>

  <script src="https://www.paypal.com/sdk/js?client-id=<?= htmlspecialchars(PAYPAL_CLIENT_ID, ENT_QUOTES, 'UTF-8') ?>&currency=USD"></script>

  <script>
  (function () {
    const paypalPreview = document.getElementById('paypalPreview');
    const orderNumber = <?= json_encode($order['order_number']) ?>;

    if (typeof paypal === 'undefined') {
      if (paypalPreview) paypalPreview.textContent = 'PayPal SDK failed to load.';
      console.error('PayPal SDK is not available');
      return;
    }

    paypal.Buttons({
      createOrder: function () {
        if (paypalPreview) paypalPreview.textContent = 'Creating PayPal order...';

        return fetch('paypal-create-order.php?order=' + encodeURIComponent(orderNumber), {
          method: 'POST'
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          console.log('CreateOrder response:', data);
          if (data.orderID) {
            if (paypalPreview) paypalPreview.textContent = 'PayPal order created.';
            return data.orderID;
          }
          throw new Error(data.error || 'Failed to create PayPal order.');
        });
      },

      onApprove: function (data) {
        if (paypalPreview) paypalPreview.textContent = 'Capturing PayPal payment...';

        return fetch('paypal-capture-order.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            order_number: orderNumber,
            paypal_order_id: data.orderID
          })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          console.log('Capture response:', data);

          if (data.status === 'COMPLETED') {
            var invoiceMail = data.invoiceMail || 'sent';
            // Samakan query string dengan jalur bank
            var url = 'index.php?payment_uploaded=1'
              + '&invoice_email=' + encodeURIComponent(invoiceMail)
              + '&order=' + encodeURIComponent(orderNumber)
              + '&method=paypal';
            window.location.href = url;
            return;
          }
          throw new Error(
            (data.error || 'Failed to capture PayPal payment')
            + (data.message ? ' (' + data.message + ')' : '')
          );
        });
      },

      onCancel: function () {
        if (paypalPreview) paypalPreview.textContent = 'PayPal payment cancelled.';
      },

      onError: function (err) {
        if (paypalPreview) paypalPreview.textContent = 'PayPal error: ' + err;
        console.error('PayPal onError:', err);
      }
    }).render('#paypal-button-container');
  })();
  </script>

  <script>
  (function () {
    'use strict';

    function showToast(msg) {
      const container = document.getElementById('toastContainer');
      const t = document.createElement('div');
      t.className = 'toast';
      t.textContent = msg;
      container.appendChild(t);
      requestAnimationFrame(() => { requestAnimationFrame(() => { t.classList.add('show'); }); });
      setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 250); }, 2600);
    }

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

    document.querySelectorAll('[data-copy]').forEach(btn => {
      btn.addEventListener('click', async function () {
        const text     = this.dataset.copy;
        const targetId = this.dataset.target;
        const target   = document.getElementById(targetId);
        try {
          if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
          } else {
            const tmp = document.createElement('textarea');
            tmp.value = text; tmp.style.cssText = 'position:fixed;left:-9999px';
            document.body.appendChild(tmp); tmp.focus(); tmp.select();
            document.execCommand('copy'); document.body.removeChild(tmp);
          }
          if (target) target.textContent = 'Copied ✓';
          showToast('Account number copied');
          setTimeout(() => { if (target) target.textContent = ''; }, 2200);
        } catch (e) {
          if (target) target.textContent = 'Failed to copy.';
        }
      });
    });

    const fileInput   = document.getElementById('payment_proof');
    const filePreview = document.getElementById('filePreview');
    if (fileInput && filePreview) {
      fileInput.addEventListener('change', function () {
        if (this.files && this.files.length > 0) {
          const f    = this.files[0];
          const size = (f.size / 1024 / 1024).toFixed(2);
          filePreview.textContent = f.name + ' (' + size + ' MB)';
          filePreview.style.color = '#111';
        } else {
          filePreview.textContent = 'No file selected';
          filePreview.style.color = '';
        }
      });
    }

    const uploadForm = document.getElementById('uploadForm');
    const submitBtn  = document.getElementById('submitBtn');
    if (uploadForm && submitBtn) {
      uploadForm.addEventListener('submit', function () {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Uploading...';
      });
    }
  })();
  </script>
</body>
</html>
