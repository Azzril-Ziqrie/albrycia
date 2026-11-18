<?php
/**
 * Loading screen bersama (di-include di awal <body> tiap halaman toko).
 *
 *  - Kunjungan pertama per sesi: intro layar penuh (logo, wordmark, progres yang mengikuti gambar yang benar-benar termuat).
 *  - Perpindahan halaman berikutnya: tanpa layar penuh, cukup garis progres tipis di atas layar.
 *  - Tidak pernah menahan halaman: batas tunggu maksimum, dan hilang sendiri tanpa JavaScript.
 *  - Menghormati prefers-reduced-motion dan prefers-reduced-transparency.
 */
?>
<style>
  :root { --ld-spring: cubic-bezier(.32, .72, 0, 1); }

  /* ── Intro layar penuh ── */
  #global-loader {
    position: fixed; inset: 0; z-index: 99999;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 22px;
    background: radial-gradient(60% 50% at 50% 44%, #1f1f1f 0%, #0b0b0b 72%);
    color: #fff;
    transition: opacity .6s var(--ld-spring), visibility .6s, backdrop-filter .6s;
  }
  html.loader-skip #global-loader { display: none; }
  /* animasi fade-in <body> bawaan halaman akan membuat intro tampak abu-abu; matikan hanya selama intro */
  html.loader-intro body { animation: none !important; opacity: 1 !important; }
  #global-loader.is-leaving { opacity: 0; visibility: hidden; }
  #global-loader.is-leaving .ld-mark { transform: scale(1.12); }

  .ld-mark { position: relative; width: 84px; height: 84px; transition: transform .7s var(--ld-spring); }
  .ld-mark img {
    position: relative; z-index: 1; display: block; width: 100%; height: 100%;
    object-fit: contain; filter: invert(1);
    animation: ld-in .9s var(--ld-spring) both, ld-breathe 2.6s ease-in-out .9s infinite;
  }
  /* kilau krom yang menyapu HANYA di bentuk logo (di-mask dengan logo itu sendiri) */
  .ld-mark::after {
    content: ""; position: absolute; inset: 0; z-index: 2; pointer-events: none;
    -webkit-mask: url(assets/images/brand/logo.png) center / contain no-repeat;
            mask: url(assets/images/brand/logo.png) center / contain no-repeat;
    background: linear-gradient(105deg, transparent 38%, rgba(255,255,255,.95) 50%, transparent 62%) no-repeat;
    background-size: 260% 100%; background-position: 160% 0;
    animation: ld-sheen 2.4s ease-in-out .6s infinite;
  }

  .ld-word {
    font: 500 11px/1 -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, Arial, sans-serif;
    letter-spacing: .6em; text-indent: .6em; text-transform: uppercase; color: rgba(255,255,255,.72);
    animation: ld-in .9s var(--ld-spring) .15s both;
  }

  .ld-track { width: 168px; height: 2px; border-radius: 2px; background: rgba(255,255,255,.14); overflow: hidden; animation: ld-in .9s var(--ld-spring) .3s both; }
  .ld-bar { display: block; width: 100%; height: 100%; background: #fff; transform: scaleX(0); transform-origin: left; transition: transform .35s var(--ld-spring); }

  @keyframes ld-in { from { opacity: 0; transform: translateY(8px) scale(.94); } to { opacity: 1; transform: none; } }
  @keyframes ld-breathe { 0%, 100% { opacity: 1; } 50% { opacity: .72; } }
  @keyframes ld-sheen { 0% { background-position: 160% 0; } 60%, 100% { background-position: -60% 0; } }

  /* ── Garis progres tipis untuk perpindahan halaman ── */
  #nav-progress {
    position: fixed; top: 0; left: 0; right: 0; height: 2px; z-index: 99998; pointer-events: none;
    background: #111; transform: scaleX(0); transform-origin: left; opacity: 0;
  }
  #nav-progress.is-running { opacity: 1; transform: scaleX(.82); transition: transform 8s cubic-bezier(.1, .6, .2, 1), opacity .2s; }

  @media (prefers-reduced-motion: reduce) {
    #global-loader, .ld-mark, .ld-bar { transition-duration: .01ms; }
    .ld-mark img, .ld-mark::after, .ld-word, .ld-track { animation: none; }
    .ld-mark::after { display: none; }
    #nav-progress.is-running { transition-duration: .01ms; }
  }
</style>

<noscript><style>#global-loader { display: none !important; }</style></noscript>

<div id="nav-progress" aria-hidden="true"></div>

<div id="global-loader" aria-hidden="true">
  <div class="ld-mark"><img src="assets/images/brand/logo.png" alt="" decoding="async" fetchpriority="high"></div>
  <div class="ld-word">Albrycia</div>
  <div class="ld-track"><span class="ld-bar" id="ldBar"></span></div>
</div>

<script>
(function () {
  'use strict';
  var KEY = 'albIntroSeen';
  var MIN_VISIBLE_MS = 900;     // intro minimal terlihat agar tidak berkedip
  var MAX_WAIT_MS = 5000;       // batas tunggu: halaman tidak pernah tertahan lebih lama dari ini
  var root = document.documentElement;
  var loader = document.getElementById('global-loader');
  var bar = document.getElementById('ldBar');
  var navBar = document.getElementById('nav-progress');
  var startedAt = Date.now();

  function seenBefore() { try { return sessionStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
  function markSeen()   { try { sessionStorage.setItem(KEY, '1'); } catch (e) {} }

  /* Kunjungan berikutnya dalam sesi: tanpa intro layar penuh */
  root.classList.add(seenBefore() ? 'loader-skip' : 'loader-intro');

  var shown = 0;
  function setProgress(ratio) {
    shown = Math.max(shown, Math.min(ratio, 1));
    if (bar) { bar.style.transform = 'scaleX(' + shown + ')'; }
  }

  /* Progres nyata: gambar yang sudah termuat dibanding semua gambar di halaman, dengan lantai waktu */
  function imageRatio() {
    var imgs = Array.prototype.slice.call(document.images).filter(function (i) { return i.loading !== 'lazy'; });
    if (!imgs.length) { return 0; }
    var done = imgs.filter(function (i) { return i.complete; }).length;
    return done / imgs.length;
  }
  var tick = setInterval(function () {
    var elapsed = (Date.now() - startedAt) / MAX_WAIT_MS;
    setProgress(Math.min(0.9, Math.max(imageRatio() * 0.9, elapsed * 0.6)));
  }, 140);

  var finished = false;
  function finish() {
    if (finished) { return; }
    finished = true;
    clearInterval(tick);
    setProgress(1);
    var wait = Math.max(0, MIN_VISIBLE_MS - (Date.now() - startedAt));
    setTimeout(function () {
      if (loader) { loader.classList.add('is-leaving'); }
      markSeen();
      setTimeout(function () { if (loader) { loader.style.display = 'none'; } }, 700);
    }, root.classList.contains('loader-skip') ? 0 : wait);
  }

  if (document.readyState === 'complete') { finish(); } else { window.addEventListener('load', finish); }
  setTimeout(finish, MAX_WAIT_MS);

  /* Pindah halaman / kirim form: garis progres tipis, bukan layar penuh */
  function startNavProgress() { if (navBar) { navBar.classList.add('is-running'); } }
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button > 0) { return; }
    var href = a.getAttribute('href');
    if (a.target === '_blank' || a.hasAttribute('download') || !href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) { return; }
    startNavProgress();
  });
  document.addEventListener('submit', function (e) { if (!e.defaultPrevented) { startNavProgress(); } });

  /* Tombol Back (bfcache): bersihkan sisa progres */
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      if (navBar) { navBar.classList.remove('is-running'); }
      if (loader) { loader.style.display = 'none'; }
    }
  });
})();
</script>
