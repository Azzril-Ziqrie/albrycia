<?php
/**
 * Identitas situs: satu sumber untuk SEO, data terstruktur, sitemap, dan llms.txt.
 * SITE_BASE_URL harus domain produksi (bukan localhost) agar canonical & sitemap benar.
 */
$siteConstants = [
    'SITE_BASE_URL'     => 'https://albrycia.com',
    'BRAND_NAME'        => 'ALBRYCIA',
    'BRAND_TAGLINE'     => 'Chrome State Of Mind',
    'BRAND_DESCRIPTION' => 'ALBRYCIA is a fragrance brand from Bandung, Indonesia. Discover unisex, long-lasting Eau de Parfum 50ml — Chrome State Of Mind.',
    'BRAND_EMAIL'       => 'info@albrycia.com',
    'BRAND_INSTAGRAM'   => 'https://www.instagram.com/albryciaa/',
    'BRAND_LOGO'        => 'assets/images/brand/logo.png',
    'BRAND_CITY'        => 'Bandung',
    'BRAND_REGION'      => 'West Java',
    'BRAND_REGION_ISO'  => 'ID-JB',
    'BRAND_COUNTRY'     => 'ID',
    'BRAND_COUNTRY_NAME'=> 'Indonesia',
];
foreach ($siteConstants as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
unset($siteConstants, $name, $value);
