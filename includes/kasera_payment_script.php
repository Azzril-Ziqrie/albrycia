<?php
/** Script halaman payment.php untuk Kasera. Variabel: $order, $kasera, $isOrderPaid. */
?>
<?php if ($kasera['show'] && $kasera['method'] === 'qris'): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<?php endif; ?>
<?php if (!$isOrderPaid): ?>
<script>
(function () {
  'use strict';
  const POLL_INTERVAL_MS = 4000;
  const SYNC_EVERY_N_POLLS = 3;
  const WARN_BEFORE_EXPIRY_MS = 300000;
  const ORDER_NUMBER = <?= json_encode($order['order_number']) ?>;
  const QR_STRING = <?= json_encode((string)($kasera['payment']['qr_string'] ?? '')) ?>;
  const statusEl = document.getElementById('copyStatus');

  function say(message) {
    if (!statusEl) return;
    statusEl.textContent = message;
    setTimeout(() => { statusEl.textContent = ''; }, 2200);
  }

  async function copyText(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        const t = document.createElement('textarea');
        t.value = text; t.style.cssText = 'position:fixed;left:-9999px';
        document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove();
      }
      say('Copied ✓');
    } catch (e) { say('Failed to copy.'); }
  }

  function bindCopyButtons() {
    document.querySelectorAll('#kaseraPay [data-copy]').forEach(b =>
      b.addEventListener('click', () => copyText(b.dataset.copy)));
  }

  function bindSubmitGuards() {
    document.querySelectorAll('form[data-pay]').forEach(form => {
      const btn = form.querySelector('button[type=submit]');
      const label = btn.textContent;
      const needsBank = () => form.dataset.needs === 'bank' && !form.querySelector('input[name=kasera_method]:checked');
      form.addEventListener('submit', () => { btn.disabled = true; btn.textContent = 'Processing...'; });
      window.addEventListener('pageshow', () => { btn.textContent = label; btn.disabled = needsBank(); });
    });
  }

  function bindBankPicker() {
    const form = document.getElementById('vaForm');
    if (!form) return;
    const btn = form.querySelector('button[type=submit]');
    form.addEventListener('change', () => {
      const picked = form.querySelector('input[name=kasera_method]:checked');
      btn.disabled = !picked;
      btn.textContent = picked ? 'Pay with ' + picked.dataset.label : 'Select a bank';
    });
  }

  function renderQr() {
    const box = document.getElementById('qrbox');
    if (!box) return;
    if (!window.QRCode || !QR_STRING) {
      document.getElementById('qrFallback').textContent = 'QR could not be displayed. Copy the QR text instead.';
      return;
    }
    new QRCode(box, { text: QR_STRING, width: 480, height: 480, correctLevel: QRCode.CorrectLevel.M });
    document.getElementById('qrDownload').addEventListener('click', () => {
      const canvas = box.querySelector('canvas');
      if (!canvas) return;
      const a = document.createElement('a');
      a.href = canvas.toDataURL('image/png'); a.download = 'qris-' + ORDER_NUMBER + '.png'; a.click();
    });
  }

  function startCountdown() {
    const el = document.getElementById('countdown');
    if (!el) return { isExpired: () => false };
    const expiresAt = el.dataset.expires ? new Date(el.dataset.expires).getTime() : 0;
    let expired = false;
    const tick = () => {
      if (!expiresAt) { el.textContent = ''; return; }
      const left = Math.max(0, expiresAt - Date.now());
      const mm = String(Math.floor(left / 60000)).padStart(2, '0');
      const ss = String(Math.floor(left % 60000 / 1000)).padStart(2, '0');
      el.textContent = mm + ':' + ss;
      el.classList.toggle('warn', left > 0 && left < WARN_BEFORE_EXPIRY_MS);
      if (left === 0 && !expired) {
        expired = true;
        document.getElementById('payLive').style.display = 'none';
        document.getElementById('expiredMsg').style.display = 'block';
      }
    };
    tick(); setInterval(tick, 1000);
    return { isExpired: () => expired };
  }

  function startStatusPolling(countdown) {
    let polls = 0;
    setInterval(async () => {
      if (countdown.isExpired()) return;
      polls++;
      const sync = polls % SYNC_EVERY_N_POLLS === 0 ? '&sync=1' : '';
      try {
        const res = await fetch('payment?order=' + encodeURIComponent(ORDER_NUMBER) + '&status=1' + sync, { cache: 'no-store' });
        const { status } = await res.json();
        if (status === 'expired') { window.location.reload(); }
        if (status === 'paid') {
          window.location.href = 'index.php?payment_uploaded=1&invoice_email=sent&order=' + encodeURIComponent(ORDER_NUMBER);
        }
      } catch (e) { /* coba lagi di polling berikutnya */ }
    }, POLL_INTERVAL_MS);
  }

  bindSubmitGuards();
  bindBankPicker();
  <?php if ($kasera['show']): ?>
  bindCopyButtons();
  renderQr();
  startStatusPolling(startCountdown());
  <?php endif; ?>
})();
</script>
<?php endif; ?>
