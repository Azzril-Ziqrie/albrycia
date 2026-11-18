<?php
/** /robots.txt (lewat .htaccess). Halaman transaksi & admin tidak untuk mesin pencari; konten publik terbuka, termasuk crawler AI. */
require_once __DIR__ . '/config/site.php';

header('Content-Type: text/plain; charset=utf-8');

$blocked = [
    '/admin/', '/api/', '/invoices/', '/uploads/payment-proofs/',
    '/checkout', '/payment', '/cart', '/register', '/login', '/logout',
    '/kasera-webhook', '/invoice-download', '/set-currency', '/currency',
];
$aiCrawlers = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'PerplexityBot', 'Google-Extended', 'Applebot-Extended'];

function robotsGroup(array $agents, array $blocked): string
{
    $lines = array_map(fn($agent) => 'User-agent: ' . $agent, $agents);
    $lines[] = 'Allow: /';
    foreach ($blocked as $path) {
        $lines[] = 'Disallow: ' . $path;
    }
    return implode("\n", $lines) . "\n";
}

echo robotsGroup(['*'], $blocked) . "\n";
echo "# Crawler AI diizinkan membaca konten publik agar ALBRYCIA bisa ditemukan di mesin jawaban AI\n";
echo robotsGroup($aiCrawlers, $blocked) . "\n";
echo 'Sitemap: ' . rtrim(SITE_BASE_URL, '/') . "/sitemap.xml\n";
