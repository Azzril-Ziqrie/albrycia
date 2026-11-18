<?php
/**
 * Panel Kasera di payment.php.
 * Variabel dari payment.php: $order, $kasera, $isOrderPaid, $orderCurrency.
 */
$orderNumberRaw = (string)$order['order_number'];
$amountIdrText  = formatMoney((float)$kasera['amountIdr'], 'IDR');
?>
<?php if ($isOrderPaid): ?>
  <section class="panel" aria-label="Payment">
    <div class="panel-head"><span class="panel-title">Payment</span></div>
    <div class="panel-body"><p class="upload-note">This order has been paid. Thank you!</p></div>
  </section>

<?php elseif ($kasera['show']): ?>
  <?php
    $isQris = $kasera['method'] === 'qris';
    $steps  = is_array($kasera['data']['instructions']['steps'] ?? null) ? $kasera['data']['instructions']['steps'] : [];
    $vaCode = (string)($kasera['payment']['payment_code'] ?? '');
    $qrText = (string)($kasera['payment']['qr_string'] ?? '');
  ?>
  <section class="panel" aria-label="<?= htmlspecialchars($kasera['label']) ?>">
    <div class="panel-head">
      <span class="panel-title"><?= htmlspecialchars($kasera['label']) ?></span>
      <span class="countdown" id="countdown" data-expires="<?= htmlspecialchars($kasera['expiresAt']) ?>">--:--</span>
    </div>
    <div class="panel-body" id="kaseraPay">
      <div class="notice error" id="expiredMsg" style="display:none">This payment has expired. Please choose a payment method again.</div>
      <div id="payLive">
        <div class="bank-row">
          <div class="bank-key">Amount to pay</div>
          <div class="bank-value"><?= htmlspecialchars($amountIdrText) ?></div>
        </div>
        <?php if ($orderCurrency === 'USD'): ?>
          <p class="upload-note">Your USD total is charged in IDR at 1 USD = <?= htmlspecialchars(formatMoney(ddUsdToIdrRate(), 'IDR')) ?>.</p>
        <?php endif; ?>
        <div class="copy-row">
          <button type="button" class="btn-secondary" data-copy="<?= (int)$kasera['amountIdr'] ?>">Copy amount</button>
        </div>

        <?php if ($isQris): ?>
          <div class="qr-wrap">
            <div id="qrbox"></div>
            <div class="copy-status" id="qrFallback" style="color:#c0392b"></div>
            <div class="copy-row">
              <button type="button" class="btn-secondary" id="qrDownload">Download QR</button>
              <button type="button" class="btn-secondary" data-copy="<?= htmlspecialchars($qrText) ?>">Copy QR text</button>
            </div>
          </div>
        <?php else: ?>
          <div class="bank-row">
            <div class="bank-key">Virtual account</div>
            <div class="bank-value"><?= htmlspecialchars($vaCode) ?></div>
          </div>
          <div class="copy-row">
            <button type="button" class="btn-secondary" data-copy="<?= htmlspecialchars($vaCode) ?>">Copy number</button>
          </div>
          <p class="upload-note">Transfer the exact amount shown above.</p>
        <?php endif; ?>

        <?php if ($steps): ?>
          <ol class="steps-list">
            <?php foreach ($steps as $step): ?>
              <li><?= htmlspecialchars(is_array($step) ? (string)($step['text'] ?? $step['description'] ?? '') : (string)$step) ?></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>
      <div class="copy-status" id="copyStatus"></div>
      <div class="copy-row">
        <a href="payment.php?order=<?= urlencode($orderNumberRaw) ?>&change=1" class="btn-secondary">← Choose another method</a>
      </div>
    </div>
  </section>

<?php else: ?>
  <?php
    $enabled = kaseraEnabledMethods();
    $banks   = array_diff_key($enabled, ['qris' => 1]);
  ?>
  <section class="panel" aria-label="Kasera payment options">
    <div class="panel-head">
      <span class="panel-step">01</span>
      <span class="panel-title">Instant Payment (Auto-Confirmed)</span>
    </div>
    <?php if ($orderCurrency === 'USD'): ?>
      <div class="notice" style="background:#fff8e1;color:#7a5b00">QRIS, Virtual Account and e-wallets are charged in IDR: <strong><?= htmlspecialchars($amountIdrText) ?></strong>.</div>
    <?php endif; ?>

    <div class="accordion">
      <?php if (isset($enabled['qris'])): ?>
        <div class="accordion-item">
          <button type="button" class="accordion-trigger" aria-expanded="false">
            <div class="accordion-left"><span>QRIS</span></div>
            <span class="accordion-arrow" aria-hidden="true">⌄</span>
          </button>
          <div class="accordion-content">
            <p class="upload-note">Scan with any e-wallet or mobile banking app. Confirmed automatically.</p>
            <form method="POST" data-pay>
              <input type="hidden" name="kasera_method" value="qris">
              <button type="submit" class="btn-primary">Pay with QRIS</button>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($banks): ?>
        <div class="accordion-item">
          <button type="button" class="accordion-trigger" aria-expanded="false">
            <div class="accordion-left"><span>Virtual Account</span></div>
            <span class="accordion-arrow" aria-hidden="true">⌄</span>
          </button>
          <div class="accordion-content">
            <p class="upload-note">Choose your bank to get a virtual account number. Confirmed automatically.</p>
            <form method="POST" data-pay data-needs="bank" id="vaForm">
              <div class="va-grid">
                <?php foreach ($banks as $code => $label): ?>
                  <label class="va-tile" title="<?= htmlspecialchars($label) ?>">
                    <input type="radio" name="kasera_method" value="<?= htmlspecialchars($code) ?>" data-label="<?= htmlspecialchars(trim(str_replace('Virtual Account', '', $label))) ?>">
                    <span class="tile"><?= bankLogoHtml($code, $label) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <button type="submit" class="btn-primary" id="vaBtn" disabled>Select a bank</button>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <div class="accordion-item">
        <button type="button" class="accordion-trigger" aria-expanded="false">
          <div class="accordion-left"><span>E-wallet &amp; other methods</span></div>
          <span class="accordion-arrow" aria-hidden="true">⌄</span>
        </button>
        <div class="accordion-content">
          <p class="upload-note">You will be redirected to a secure page to choose another payment method.</p>
          <form method="POST" data-pay>
            <input type="hidden" name="kasera_method" value="<?= KASERA_OTHER_METHODS ?>">
            <button type="submit" class="btn-primary">Continue</button>
          </form>
        </div>
      </div>
    </div>
  </section>
<?php endif; ?>
