<?php
/**
 * Pita teks bergulir tanpa henti. Isi & kecepatan diatur dari admin (Moving Text).
 * Butuh $pdo; $marqueeOverride (opsional) dipakai untuk pratinjau di admin.
 */
require_once __DIR__ . '/../site_options.php';

$marquee = isset($marqueeOverride) ? marqueeNormalize($marqueeOverride) : marqueeOptions($pdo);
if (!$marquee['enabled'] && !isset($marqueeOverride)) {
    return;
}
?>
<div class="fx-marquee" style="--fx-marquee-duration: <?= (int)$marquee['seconds'] ?>s" aria-label="<?= htmlspecialchars(implode(' · ', $marquee['words'])) ?>" role="marquee">
  <div class="fx-marquee__track" aria-hidden="true">
    <?php for ($copy = 0; $copy < 2; $copy++): /* dua salinan agar putaran tanpa jeda */ ?>
      <?php for ($repeat = 0; $repeat < 3; $repeat++): ?>
        <?php foreach ($marquee['words'] as $word): ?>
          <span class="fx-marquee__item"><?= htmlspecialchars($word) ?> <i><?= htmlspecialchars($marquee['separator']) ?></i></span>
        <?php endforeach; ?>
      <?php endfor; ?>
    <?php endfor; ?>
  </div>
</div>
