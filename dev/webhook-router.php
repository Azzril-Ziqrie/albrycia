<?php
/**
 * Router untuk PHP built-in server (hanya untuk pengujian lokal lewat ngrok).
 * HANYA endpoint webhook Kasera yang dilayani; semua alamat lain dibalas 404, jadi situs lokal
 * (yang berisi data pelanggan) tidak ikut terbuka ke internet.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (in_array($path, ['/kasera-webhook', '/kasera-webhook.php'], true)) {
    require __DIR__ . '/../kasera-webhook.php';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found.';
return true;
