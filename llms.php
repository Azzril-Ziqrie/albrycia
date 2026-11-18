<?php
/** /llms.txt (lewat .htaccess): ringkasan brand yang mudah dibaca mesin jawaban AI (GEO). Hanya fakta yang ada di situs. */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/seo.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $products = $pdo->query("SELECT name, slug, description, price_idr FROM products WHERE is_active = 1 ORDER BY id ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $products = [];
}

echo '# ' . BRAND_NAME . "\n\n";
echo '> ' . BRAND_DESCRIPTION . "\n\n";

echo "## About\n";
echo BRAND_NAME . ' (' . BRAND_TAGLINE . ') is a fragrance brand from ' . BRAND_CITY . ', ' . BRAND_REGION . ', ' . BRAND_COUNTRY_NAME . ". "
   . "Its fragrances are Eau de Parfum 50ml, unisex and long lasting, sold online at " . rtrim(SITE_BASE_URL, '/') . ".\n\n";

echo "## Key pages\n";
echo '- [Home](' . seoUrl() . "): featured collection and latest fragrances\n";
echo '- [Shop](' . seoUrl('shop') . "): all current fragrances\n";
echo '- [Lookbook](' . seoUrl('lookbook') . "): visual journal of collections and campaigns\n";
echo '- [Terms & Conditions](' . seoUrl('legal') . "): payments, shipping, returns, privacy\n\n";

if ($products) {
    echo "## Fragrances\n";
    foreach ($products as $p) {
        $summary = seoProductDescription((string)$p['description']);
        echo '- [' . $p['name'] . '](' . seoUrl('product?slug=' . rawurlencode((string)$p['slug'])) . '): ' . $summary
           . ' Price: IDR ' . number_format((float)$p['price_idr'], 0, ',', '.') . ".\n";
    }
    echo "\n";
}

echo "## Ordering, shipping and returns\n";
echo "- Pay securely at checkout with QRIS, Virtual Account or e-wallet. Prices are in Indonesian Rupiah (IDR).\n";
echo "- Orders are prepared for shipment within 1-2 working days after payment confirmation.\n";
echo "- All sales are final unless the item received is defective or incorrect; requests must be made within 3 days of receiving the order.\n";
echo "- An account is optional; registering lets customers track orders and receive release updates.\n\n";

echo "## FAQ\n";
foreach (seoFaqItems() as [$question, $answer]) {
    echo '- ' . $question . ' ' . $answer . "\n";
}

echo "\n## Contact and social\n";
echo '- Email: ' . BRAND_EMAIL . "\n";
echo '- Instagram: ' . BRAND_INSTAGRAM . "\n";
echo '- Location: ' . BRAND_CITY . ', ' . BRAND_REGION . ', ' . BRAND_COUNTRY_NAME . "\n";
