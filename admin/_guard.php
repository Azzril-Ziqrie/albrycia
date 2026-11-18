<?php
/**
 * Penjaga halaman admin: di-include di baris pertama setiap halaman admin.
 * Belum login → alihkan ke login.php (atau balas 401 JSON untuk request AJAX).
 */
require_once __DIR__ . '/_auth.php';

function adminRequestWantsJson(): bool
{
    return stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false
        || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

function adminDenyAccess(): never
{
    if (adminRequestWantsJson()) {
        http_response_code(401);
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'message' => 'Not authenticated.']));
    }
    $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $next = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        ? $self . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')
        : '';
    header('Location: login.php' . ($next !== '' ? '?next=' . urlencode($next) : ''));
    exit;
}

if (!adminIsLoggedIn()) {
    adminDenyAccess();
}
header('Cache-Control: no-store'); // halaman admin jangan di-cache browser/proxy
