<?php
/** /sitemap.xml (lewat .htaccess): halaman publik + semua produk aktif beserta gambarnya. */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/seo.php';

header('Content-Type: application/xml; charset=utf-8');

function sitemapEntry(string $path, ?string $lastmod = null, string $priority = '0.5', array $images = []): string
{
    $xml = '  <url><loc>' . seoEsc(seoUrl($path)) . '</loc>';
    if ($lastmod) {
        $xml .= '<lastmod>' . date('Y-m-d', strtotime($lastmod)) . '</lastmod>';
    }
    $xml .= '<priority>' . $priority . '</priority>';
    foreach ($images as $image) {
        $xml .= '<image:image><image:loc>' . seoEsc(seoAbsoluteImage($image)) . '</image:loc></image:image>';
    }
    return $xml . "</url>\n";
}

function fetchRows(PDO $pdo, string $sql): array
{
    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

$products   = fetchRows($pdo, "SELECT slug, thumbnail, updated_at FROM products WHERE is_active = 1 ORDER BY id ASC")
           ?: fetchRows($pdo, "SELECT slug, thumbnail, NULL AS updated_at FROM products WHERE is_active = 1 ORDER BY id ASC");
$categories = fetchRows($pdo, "SELECT slug FROM categories ORDER BY id ASC");

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
echo sitemapEntry('', null, '1.0');
echo sitemapEntry('shop', null, '0.9');
foreach ($categories as $category) {
    echo sitemapEntry('shop?category=' . rawurlencode((string)$category['slug']), null, '0.7');
}
foreach ($products as $product) {
    echo sitemapEntry('product?slug=' . rawurlencode((string)$product['slug']), $product['updated_at'] ?? null, '0.8',
        $product['thumbnail'] ? [(string)$product['thumbnail']] : []);
}
echo sitemapEntry('lookbook', null, '0.6');
echo sitemapEntry('legal', null, '0.3');
echo "</urlset>\n";
