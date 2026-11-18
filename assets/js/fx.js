/* Efek antarmuka: 3D card + glare, text generate. Tanpa dependensi. Lihat assets/css/fx.css */
(function () {
  'use strict';
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var MAX_TILT = 5;        // derajat
  var IMG_SHIFT = 10;      // piksel, gambar bergeser sedikit berlawanan arah (rasa kedalaman)

  /* ── 1. 3D Card + Glare ── */
  function initCards() {
    if (reduced || !finePointer) { return; }
    document.querySelectorAll('.product-card:not(.sold-out)').forEach(function (card) {
      var link = card.querySelector(':scope > a');
      if (!link) { return; }
      var frame = 0;
      card.addEventListener('pointermove', function (e) {
        var box = link.getBoundingClientRect();
        var x = (e.clientX - box.left) / box.width;     // 0..1
        var y = (e.clientY - box.top) / box.height;
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(function () {
          card.classList.add('is-tilting');
          card.style.setProperty('--fx-ry', ((x - .5) * 2 * MAX_TILT).toFixed(2) + 'deg');
          card.style.setProperty('--fx-rx', ((.5 - y) * 2 * MAX_TILT).toFixed(2) + 'deg');
          card.style.setProperty('--fx-mx', (x * 100).toFixed(1) + '%');
          card.style.setProperty('--fx-my', (y * 100).toFixed(1) + '%');
          card.style.setProperty('--fx-ix', ((.5 - x) * IMG_SHIFT).toFixed(1) + 'px');
          card.style.setProperty('--fx-iy', ((.5 - y) * IMG_SHIFT).toFixed(1) + 'px');
        });
      });
      card.addEventListener('pointerleave', function () {
        cancelAnimationFrame(frame);
        card.classList.remove('is-tilting');
        ['--fx-rx', '--fx-ry', '--fx-ix', '--fx-iy'].forEach(function (p) { card.style.removeProperty(p); });
      });
    });
  }

  /* ── 2. Text Generate: kata muncul bergantian dari buram → jelas ── */
  function splitWords(el) {
    var text = el.textContent.trim();
    el.setAttribute('aria-label', text);
    el.textContent = '';
    text.split(/\s+/).forEach(function (word, i, all) {
      var span = document.createElement('span');
      span.className = 'fx-word';
      span.setAttribute('aria-hidden', 'true');
      span.style.setProperty('--i', i);
      span.textContent = word;
      el.appendChild(span);
      if (i < all.length - 1) { el.appendChild(document.createTextNode(' ')); }
    });
  }
  function initReveal() {
    var targets = document.querySelectorAll('[data-fx="reveal"]');
    if (!targets.length) { return; }
    targets.forEach(splitWords);
    var introDelay = document.documentElement.classList.contains('loader-intro') ? 1000 : 0;  // tunggu intro selesai
    function show(el) { setTimeout(function () { el.classList.add('is-revealed'); }, introDelay); }
    if (reduced || !('IntersectionObserver' in window)) { targets.forEach(function (el) { el.classList.add('is-revealed'); }); return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) { if (entry.isIntersecting) { show(entry.target); io.unobserve(entry.target); } });
    }, { threshold: .3 });
    targets.forEach(function (el) { io.observe(el); });
  }

  function init() { initCards(); initReveal(); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
