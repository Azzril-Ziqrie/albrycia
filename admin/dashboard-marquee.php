<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/site_options.php';

$activePage = 'dashboard-marquee.php';
$message = '';
$error   = '';

/* ── simpan ─────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals(adminCsrfToken(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'Session expired. Please reload the page and try again.';
    } elseif (marqueeParseWords($_POST['words'] ?? '') === []) {
        $error = 'Add at least one word or phrase.';
    } else {
        siteOptionSet($pdo, MARQUEE_OPTION_KEY, marqueeNormalize([
            'enabled'   => isset($_POST['enabled']),
            'words'     => $_POST['words'] ?? '',
            'separator' => $_POST['separator'] ?? '',
            'seconds'   => $_POST['seconds'] ?? 0,
        ]));
        $message = 'Saved. The homepage now shows the new moving text.';
    }
}

if (($_GET['reset'] ?? '') === '1' && hash_equals(adminCsrfToken(), (string)($_GET['csrf'] ?? ''))) {
    siteOptionSet($pdo, MARQUEE_OPTION_KEY, marqueeDefaults());
    header('Location: dashboard-marquee.php?reset_done=1');
    exit;
}
if (isset($_GET['reset_done'])) {
    $message = 'Restored the default moving text.';
}

$options = marqueeOptions($pdo);
$marqueeOverride = $options + ['enabled' => true];   // pratinjau selalu tampil, walau sedang dimatikan
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Moving Text — albrycia Admin</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, Arial, sans-serif; background: #fff; color: var(--ink); -webkit-font-smoothing: antialiased; }
    a { color: inherit; text-decoration: none; }
    .main { max-width: 860px; margin: 0 auto; padding: 32px 36px 64px; }
    .eyebrow { font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: var(--muted); margin-bottom: 8px; }
    h1 { margin: 0 0 6px; font-size: 34px; line-height: 1.05; letter-spacing: -.022em; font-weight: 600; }
    .sub { margin: 0 0 26px; font-size: 14px; color: #5a5852; }
    .card { border: 1px solid var(--line); border-radius: 16px; background: #fff; padding: 22px; margin-bottom: 16px; }
    .card h2 { margin: 0 0 16px; font-size: 15px; font-weight: 600; letter-spacing: -.01em; }
    .field { margin-bottom: 20px; }
    .field:last-child { margin-bottom: 0; }
    .lbl { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 500; }
    .hint { margin-top: 6px; font-size: 12px; color: var(--muted); }
    textarea, select { width: 100%; border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; font: inherit; font-size: 14px; background: #fff; transition: border-color .15s, box-shadow .15s; }
    textarea { min-height: 150px; resize: vertical; line-height: 1.6; }
    textarea:focus, select:focus { outline: none; border-color: var(--ink); box-shadow: 0 0 0 1px var(--ink); }
    .toggle { display: flex; align-items: center; gap: 12px; cursor: pointer; font-size: 14px; font-weight: 500; }
    .toggle input { position: absolute; opacity: 0; }
    .switch { width: 46px; height: 28px; border-radius: 99px; background: #d4d1c9; position: relative; flex: none; transition: background .25s; }
    .switch::after { content: ""; position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform .35s var(--spring); }
    .toggle input:checked + .switch { background: #111; }
    .toggle input:checked + .switch::after { transform: translateX(18px); }
    .toggle input:focus-visible + .switch { outline: 2px solid var(--ink); outline-offset: 2px; }
    .range-row { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; }
    input[type=range] { width: 100%; accent-color: #111; }
    .range-val { min-width: 74px; text-align: right; font-size: 14px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .range-ends { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); margin-top: 4px; }
    .seps { display: flex; gap: 8px; flex-wrap: wrap; }
    .seps label { cursor: pointer; }
    .seps input { position: absolute; opacity: 0; }
    .seps span { display: grid; place-items: center; width: 46px; height: 46px; border: 1px solid var(--line); border-radius: 12px; font-size: 18px; transition: border-color .15s, background .15s, transform .35s var(--spring); }
    .seps input:checked + span { border-color: var(--ink); background: #111; color: #fff; }
    .seps input:focus-visible + span { outline: 2px solid var(--ink); outline-offset: 2px; }
    .seps label:active span { transform: scale(.92); }
    .actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .btn { height: 48px; padding: 0 26px; border: 0; border-radius: 12px; background: #111; color: #fff; font: 500 14px/1 inherit; font-family: inherit; cursor: pointer; transition: transform .35s var(--spring), background .2s; }
    .btn:active { transform: scale(.97); transition-duration: .1s; }
    .btn-ghost { display: inline-flex; align-items: center; height: 48px; padding: 0 20px; border: 1px solid var(--line); border-radius: 12px; font-size: 14px; background: #fff; }
    .btn-ghost:hover { border-color: var(--ink); }
    .notice { padding: 12px 16px; border-radius: 12px; font-size: 13.5px; margin-bottom: 16px; }
    .notice.ok { background: #e3f4ea; color: #1f7a45; }
    .notice.err { background: #fbe4e1; color: #b3261e; }
    .preview { border-radius: 12px; overflow: hidden; }
    .preview .fx-marquee { margin: 0; }
    @media (max-width: 900px) { .main { padding: 72px 18px 48px; } h1 { font-size: 28px; } }
  </style>
  <link rel="stylesheet" href="../assets/css/admin-sidebar.css?v=<?= @filemtime(__DIR__ . '/../assets/css/admin-sidebar.css') ?>">
  <link rel="stylesheet" href="../assets/css/fx.css?v=<?= @filemtime(__DIR__ . '/../assets/css/fx.css') ?>">
</head>
<body class="has-sidebar">

  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <main class="main">
    <div class="eyebrow">Homepage</div>
    <h1>Moving Text</h1>
    <p class="sub">The text strip that scrolls across the homepage, between the hero and the products.</p>

    <?php if ($message !== ''): ?><div class="notice ok" role="status"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice err" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <section class="card" aria-label="Preview">
      <h2>Preview</h2>
      <div class="preview" id="livePreview"><?php include __DIR__ . '/../includes/partials/marquee.php'; ?></div>
      <p class="hint">Hover the strip to pause it, same as on the site.</p>
    </section>

    <form method="POST" class="card" action="dashboard-marquee.php" id="marqueeForm">
      <h2>Settings</h2>
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(adminCsrfToken()) ?>">

      <div class="field">
        <label class="toggle">
          <input type="checkbox" name="enabled" value="1" <?= $options['enabled'] ? 'checked' : '' ?>>
          <span class="switch" aria-hidden="true"></span>
          <span>Show the moving text on the homepage</span>
        </label>
      </div>

      <div class="field">
        <label class="lbl" for="words">Words or phrases</label>
        <textarea id="words" name="words" spellcheck="false"><?= htmlspecialchars(implode("\n", $options['words'])) ?></textarea>
        <div class="hint">One per line, up to <?= MARQUEE_MAX_WORDS ?>. They repeat in this order.</div>
      </div>

      <div class="field">
        <span class="lbl" id="sepLabel">Separator</span>
        <div class="seps" role="radiogroup" aria-labelledby="sepLabel">
          <?php foreach (MARQUEE_SEPARATORS as $sep): ?>
            <label><input type="radio" name="separator" value="<?= htmlspecialchars($sep) ?>" <?= $options['separator'] === $sep ? 'checked' : '' ?>><span><?= htmlspecialchars($sep) ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field">
        <label class="lbl" for="seconds">Speed</label>
        <div class="range-row">
          <input type="range" id="seconds" name="seconds" min="<?= MARQUEE_MIN_SECONDS ?>" max="<?= MARQUEE_MAX_SECONDS ?>" step="2" value="<?= (int)$options['seconds'] ?>">
          <span class="range-val" id="secondsVal"><?= (int)$options['seconds'] ?>s</span>
        </div>
        <div class="range-ends"><span>Faster</span><span>Slower</span></div>
        <div class="hint">Time for one full loop.</div>
      </div>

      <div class="actions">
        <button type="submit" class="btn">Save changes</button>
        <a class="btn-ghost" href="dashboard-marquee.php?reset=1&amp;csrf=<?= urlencode(adminCsrfToken()) ?>" onclick="return confirm('Restore the default text and speed?')">Restore defaults</a>
        <a class="btn-ghost" href="../index.php" target="_blank" rel="noopener">View homepage</a>
      </div>
    </form>
  </main>

  <script>
  (function () {
    'use strict';
    /* Pratinjau hidup: kecepatan & pemisah berubah langsung saat digeser/dipilih (belum disimpan sampai tombol Save) */
    var range = document.getElementById('seconds'), val = document.getElementById('secondsVal');
    var marquee = document.querySelector('#livePreview .fx-marquee');
    range.addEventListener('input', function () {
      val.textContent = range.value + 's';
      if (marquee) { marquee.style.setProperty('--fx-marquee-duration', range.value + 's'); }
    });
    document.querySelectorAll('input[name=separator]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        document.querySelectorAll('#livePreview .fx-marquee__item i').forEach(function (i) { i.textContent = radio.value; });
      });
    });
  })();
  </script>
</body>
</html>
