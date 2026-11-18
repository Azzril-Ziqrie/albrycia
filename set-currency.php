<?php
// Atur mata uang TAMPILAN (estimasi harga). Tagihan & order tetap IDR.
session_start();
require_once __DIR__ . '/includes/currency_helper.php';

$target = strtoupper(trim((string)($_GET['to'] ?? DD_BASE_CURRENCY)));
$rates  = ddExchangeRates();
$_SESSION['display_currency']     = ($target !== DD_BASE_CURRENCY && !empty($rates[$target])) ? $target : DD_BASE_CURRENCY;
$_SESSION['currency_prompt_done'] = true; // jangan tanya lagi di sesi ini

// Kembali ke halaman asal (hanya satu domain), tanpa ?choose_currency supaya pop-up tidak terbuka lagi.
$back    = 'index.php';
$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
if ($referer !== '' && (parse_url($referer, PHP_URL_HOST) ?? '') === ($_SERVER['HTTP_HOST'] ?? '')) {
    $back = rtrim((string)preg_replace('/([?&])choose_currency(=[^&#]*)?(&|$)/', '$1', $referer), '?&');
}
header('Location: ' . $back);
exit;
