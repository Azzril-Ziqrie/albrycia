<?php
/**
 * Unduh invoice PDF. Boleh: admin yang sedang login, atau tautan bertanda tangan (?sig=).
 * Path file selalu diambil dari database dan dipastikan berada di folder invoices/ —
 * tidak pernah dari input pengguna. Semua kegagalan dibalas 404 yang sama (tidak membocorkan apa pun).
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/invoice_access.php';
require_once __DIR__ . '/admin/_auth.php';

const INVOICE_DIR = __DIR__ . '/invoices';

function notFound(): never
{
    http_response_code(404);
    exit('Not found.');
}

function canDownload(string $orderNumber, string $signature): bool
{
    return adminIsLoggedIn() || invoiceSignatureIsValid($orderNumber, $signature);
}

/** Path absolut invoice milik order ini, hanya bila benar-benar berada di invoices/. */
function invoiceFileFor(PDO $pdo, string $orderNumber): ?string
{
    $stmt = $pdo->prepare('SELECT invoice_path FROM orders WHERE order_number = :n LIMIT 1');
    $stmt->execute(['n' => $orderNumber]);
    $relative = (string)$stmt->fetchColumn();
    $real = $relative !== '' ? realpath(__DIR__ . '/' . $relative) : false;
    $base = realpath(INVOICE_DIR);
    $isInside = $real !== false && $base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR);
    return $isInside && is_file($real) && strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'pdf' ? $real : null;
}

$orderNumber = trim((string)($_GET['order'] ?? ''));
$signature   = (string)($_GET['sig'] ?? '');

if (!canDownload($orderNumber, $signature)) {
    notFound();
}
$file = invoiceFileFor($pdo, $orderNumber);
if ($file === null) {
    notFound();
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="invoice-' . preg_replace('/[^A-Za-z0-9_-]/', '', $orderNumber) . '.pdf"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file);
