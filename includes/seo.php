<?php
/**
 * SEO & GEO (Generative Engine Optimization): meta tag, Open Graph, canonical, dan data terstruktur JSON-LD.
 * Pemakaian di <head> halaman:  <?php seoHead(['title' => ..., 'description' => ..., 'path' => 'shop']); ?>
 * URL kanonik memakai bentuk TANPA .php (sama dengan hasil redirect .htaccess), mis. "shop?category=perfume".
 */

require_once __DIR__ . '/../config/site.php';

const SEO_DESCRIPTION_MAX = 155;

function seoUrl(string $path = ''): string
{
    return rtrim(SITE_BASE_URL, '/') . '/' . ltrim($path, '/');
}

function seoAbsoluteImage(string $path): string
{
    return preg_match('#^https?://#i', $path) ? $path : seoUrl($path);
}

/** Rapikan teks untuk meta description: tanpa baris baru, dipotong rapi di batas kata. */
function seoDescription(string $text, int $max = SEO_DESCRIPTION_MAX): string
{
    $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    if (mb_strlen($clean) <= $max) {
        return $clean;
    }
    $cut = mb_substr($clean, 0, $max - 1);
    return rtrim(mb_substr($cut, 0, (int)mb_strrpos($cut, ' ') ?: $max - 1), " ,.;:-") . '…';
}

function seoEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ── skema JSON-LD ─────────────────────────────────────── */

function seoOrganizationSchema(): array
{
    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'Organization',
        '@id'         => seoUrl('#organization'),
        'name'        => BRAND_NAME,
        'url'         => seoUrl(),
        'logo'        => ['@type' => 'ImageObject', 'url' => seoUrl(BRAND_LOGO)],
        'slogan'      => BRAND_TAGLINE,
        'description' => BRAND_DESCRIPTION,
        'email'       => BRAND_EMAIL,
        'sameAs'      => [BRAND_INSTAGRAM],
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => BRAND_CITY,
            'addressRegion'   => BRAND_REGION,
            'addressCountry'  => BRAND_COUNTRY,
        ],
        'areaServed'  => ['@type' => 'Country', 'name' => BRAND_COUNTRY_NAME],
        'contactPoint' => [['@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => BRAND_EMAIL, 'availableLanguage' => ['en', 'id']]],
    ];
}

function seoWebsiteSchema(): array
{
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        '@id'             => seoUrl('#website'),
        'url'             => seoUrl(),
        'name'            => BRAND_NAME,
        'inLanguage'      => 'en',
        'publisher'       => ['@id' => seoUrl('#organization')],
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => seoUrl('shop?search={search_term_string}'),
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

/** @param list<array{0:string,1:string}> $crumbs pasangan [nama, path] */
function seoBreadcrumbSchema(array $crumbs): array
{
    $items = [];
    foreach ($crumbs as $i => [$name, $path]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => seoUrl($path)];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** @param array{name:string,slug:string,images:list<string>,description:string,category:string,price:float,in_stock:bool} $p */
function seoProductSchema(array $p): array
{
    $url = seoUrl('product?slug=' . rawurlencode($p['slug']));
    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        '@id'         => $url . '#product',
        'name'        => $p['name'],
        'url'         => $url,
        'sku'         => $p['slug'],
        'image'       => array_map('seoAbsoluteImage', $p['images']),
        'description' => $p['description'],
        'category'    => $p['category'],
        'brand'       => ['@type' => 'Brand', 'name' => BRAND_NAME],
        'offers'      => [
            '@type'           => 'Offer',
            'url'             => $url,
            'priceCurrency'   => 'IDR',
            'price'           => number_format($p['price'], 0, '.', ''),
            'itemCondition'   => 'https://schema.org/NewCondition',
            'availability'    => $p['in_stock'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'seller'          => ['@id' => seoUrl('#organization')],
        ],
    ];
}

/** @param list<array{name:string,slug:string}> $products */
function seoItemListSchema(string $name, array $products): array
{
    $items = [];
    foreach (array_values($products) as $i => $p) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => seoUrl('product?slug=' . rawurlencode((string)$p['slug'])), 'name' => (string)$p['name']];
    }
    return ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $name, 'itemListElement' => $items];
}

/** Teks harus sama persis dengan FAQ yang tampil di halaman (syarat Google untuk FAQPage). */
function seoFaqItems(): array
{
    return [
        ['Do I need an account to place an order?', 'Creating an account is not mandatory. Registering allows you to track orders, view order history, and receive release updates.'],
        ['How do I place an order?', 'Select your desired product, choose the available option, and proceed to checkout.'],
        ['How can I check my order status?', 'After payment verification, you will receive order and shipping updates through email.'],
        ['Do you accept returns or exchanges?', 'All sales are final unless the received item is defective or incorrect.'],
    ];
}

function seoFaqSchema(): array
{
    $entities = [];
    foreach (seoFaqItems() as [$question, $answer]) {
        $entities[] = ['@type' => 'Question', 'name' => $question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer]];
    }
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities];
}

/* ── tag <head> ────────────────────────────────────────── */

/**
 * Cetak semua tag SEO. Opsi:
 *  title, description, path (kanonik, tanpa .php), image, ogType ('website'|'product'),
 *  noindex (bool), schema (list skema JSON-LD), price (float, untuk og:product).
 */
function seoHead(array $o): void
{
    $title       = (string)($o['title'] ?? BRAND_NAME);
    $description = seoDescription((string)($o['description'] ?? BRAND_DESCRIPTION));
    $canonical   = seoUrl((string)($o['path'] ?? ''));
    $image       = seoAbsoluteImage((string)($o['image'] ?? BRAND_LOGO));
    $noindex     = !empty($o['noindex']);
    $ogType      = (string)($o['ogType'] ?? 'website');
    ?>
  <title><?= seoEsc($title) ?></title>
  <meta name="description" content="<?= seoEsc($description) ?>">
  <meta name="robots" content="<?= $noindex ? 'noindex,nofollow' : 'index,follow,max-image-preview:large,max-snippet:-1' ?>">
  <meta name="theme-color" content="#111111">
  <link rel="canonical" href="<?= seoEsc($canonical) ?>">
  <link rel="alternate" hreflang="en" href="<?= seoEsc($canonical) ?>">
  <link rel="alternate" hreflang="x-default" href="<?= seoEsc($canonical) ?>">
  <meta name="geo.region" content="<?= seoEsc(BRAND_REGION_ISO) ?>">
  <meta name="geo.placename" content="<?= seoEsc(BRAND_CITY) ?>">
  <meta property="og:type" content="<?= seoEsc($ogType) ?>">
  <meta property="og:site_name" content="<?= seoEsc(BRAND_NAME) ?>">
  <meta property="og:locale" content="en_US">
  <meta property="og:title" content="<?= seoEsc($title) ?>">
  <meta property="og:description" content="<?= seoEsc($description) ?>">
  <meta property="og:url" content="<?= seoEsc($canonical) ?>">
  <meta property="og:image" content="<?= seoEsc($image) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= seoEsc($title) ?>">
  <meta name="twitter:description" content="<?= seoEsc($description) ?>">
  <meta name="twitter:image" content="<?= seoEsc($image) ?>">
<?php if ($ogType === 'product' && isset($o['price'])): ?>
  <meta property="product:price:amount" content="<?= seoEsc(number_format((float)$o['price'], 0, '.', '')) ?>">
  <meta property="product:price:currency" content="IDR">
<?php endif; ?>
<?php foreach (($o['schema'] ?? []) as $schema): ?>
  <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endforeach; ?>
<?php
}

/**
 * Meta description produk: baris spesifikasi pertama ("EDP 50ml | Floral Gourmand | ...") + kalimat pembuka paragraf berikutnya.
 */
function seoProductDescription(string $description): string
{
    $blocks = array_values(array_filter(preg_split('/\R{2,}/', trim($description)) ?: [], fn($b) => trim($b) !== ''));
    if (!$blocks) {
        return BRAND_DESCRIPTION;
    }
    $spec = str_replace(' | ', ' · ', trim(preg_replace('/\s+/', ' ', $blocks[0])));
    $intro = isset($blocks[1]) ? trim(preg_replace('/\s+/', ' ', $blocks[1])) : '';
    return seoDescription($intro !== '' ? $spec . ' — ' . $intro : $spec);
}
