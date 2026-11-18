<?php
/** Pop-up pilih mata uang tampilan (estimasi; tagihan tetap IDR). Variabel: $gate = currencyGateModel(). */
$suggest = $gate['suggest'];
?>
<style>
#currencyGate{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;
  background:rgba(0,0,0,.35);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);animation:cgFade .3s ease both}
#currencyGate .cg-dialog{width:min(420px,100%);padding:30px 26px 22px;background:rgba(255,255,255,.92);border-radius:18px;
  text-align:center;font-family:-apple-system,BlinkMacSystemFont,"Helvetica Neue",Helvetica,Arial,sans-serif;color:#111;
  box-shadow:0 30px 80px rgba(0,0,0,.25);animation:cgRise .55s cubic-bezier(.32,.72,0,1) both;outline:none}
#currencyGate h2{margin:0 0 10px;font-size:21px;font-weight:600;letter-spacing:-.02em}
#currencyGate p{margin:0 0 18px;font-size:14px;line-height:1.55;color:#444}
#currencyGate .cg-flag{border:1px solid #e4e1d9;border-radius:4px;margin-bottom:12px}
#currencyGate .cg-list{display:grid;gap:8px}
#currencyGate a.cg-opt{position:relative;display:grid;gap:2px;padding:13px 14px;border:1px solid #e4e1d9;border-radius:12px;
  text-decoration:none;color:inherit;transition:transform .25s cubic-bezier(.32,.72,0,1),border-color .15s,background .15s}
#currencyGate a.cg-opt:hover,#currencyGate a.cg-opt:focus-visible,#currencyGate a.cg-opt.cg-rec{border-color:#111}
#currencyGate a.cg-opt.cg-rec{background:#111;color:#fff}
#currencyGate a.cg-opt:active{transform:scale(.97)}
#currencyGate .cg-code{font-size:14px;font-weight:600}
#currencyGate .cg-name{font-size:12px;opacity:.7}
#currencyGate .cg-badge{position:absolute;top:8px;right:10px;font-size:9px;font-weight:600;letter-spacing:.08em;opacity:.7}
#currencyGate select{width:100%;margin-top:10px;height:44px;border:1px solid #e4e1d9;border-radius:12px;padding:0 12px;background:#fff;font:inherit;font-size:14px}
#currencyGate .cg-note{margin:16px 0 0;font-size:11.5px;color:#777}
body.cg-lock{overflow:hidden}
@keyframes cgFade{from{opacity:0}}
@keyframes cgRise{from{opacity:0;transform:translateY(16px) scale(.97)}}
@media (prefers-reduced-motion:reduce){#currencyGate,#currencyGate .cg-dialog{animation:none}#currencyGate a.cg-opt{transition:none}}
@media (prefers-reduced-transparency:reduce){#currencyGate{-webkit-backdrop-filter:none;backdrop-filter:none;background:rgba(0,0,0,.6)}}
</style>
<div id="currencyGate" role="dialog" aria-modal="true" aria-labelledby="cgTitle">
  <div class="cg-dialog" tabindex="-1" id="cgDialog">
    <h2 id="cgTitle">Choose your currency</h2>
    <p>
      <?php if ($suggest): ?>
        <img class="cg-flag" src="https://flagcdn.com/w80/<?= htmlspecialchars(strtolower($suggest['country_code'])) ?>.png" alt="" width="40" height="30" onerror="this.style.display='none'"><br>
        Are you from <strong><?= htmlspecialchars($suggest['country']) ?></strong>? The currency there is <strong><?= htmlspecialchars($suggest['currency']) ?></strong>.
        See prices in <?= htmlspecialchars($suggest['currency']) ?>, or stay on IDR?
      <?php else: ?>
        Pick the currency you'd like to see price estimates in.
      <?php endif; ?>
    </p>
    <div class="cg-list">
      <?php if ($suggest): ?>
        <a class="cg-opt cg-rec" href="set-currency.php?to=<?= urlencode($suggest['currency']) ?>">
          <span class="cg-badge">SUGGESTED</span>
          <span class="cg-code">Switch to <?= htmlspecialchars($suggest['currency']) ?></span>
          <span class="cg-name">1 IDR ≈ <?= htmlspecialchars($suggest['currency']) ?> <?= htmlspecialchars(rtrim(rtrim(number_format($suggest['rate'], 6, '.', ''), '0'), '.')) ?></span>
        </a>
      <?php endif; ?>
      <a class="cg-opt" href="set-currency.php?to=IDR">
        <span class="cg-code"><?= $suggest ? 'Stay on IDR' : 'IDR' ?></span>
        <span class="cg-name">Indonesian Rupiah</span>
      </a>
    </div>
    <?php if ($gate['options']): ?>
      <select id="cgOther" aria-label="Other currency">
        <option value="">Other currency…</option>
        <?php foreach ($gate['options'] as $code): ?>
          <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($code) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <p class="cg-note">Prices in other currencies are estimates. You are charged in IDR at checkout.</p>
  </div>
</div>
<script>
(function () {
  document.body.classList.add('cg-lock');
  var other = document.getElementById('cgOther');
  if (other) other.addEventListener('change', function () {
    if (other.value) location.href = 'set-currency.php?to=' + encodeURIComponent(other.value);
  });
  var focusTarget = document.querySelector('#currencyGate a.cg-rec') || document.getElementById('cgDialog');
  if (focusTarget) focusTarget.focus({ preventScroll: true });
})();
</script>
