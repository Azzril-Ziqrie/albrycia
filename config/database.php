<?php
// config/database.php

$host    = 'localhost';
$db      = 'albj3628_albrycia';
$user    = 'albj3628_iney';
$pass    = 'Agacil12345!';
$charset = 'utf8mb4';

// Override khusus mesin lokal (jangan diunggah ke hosting): config/database.local.php
// berisi return ['host' => ..., 'db' => ..., 'user' => ..., 'pass' => ...];
$localOverride = __DIR__ . '/database.local.php';
if (is_file($localOverride)) {
    ['host' => $host, 'db' => $db, 'user' => $user, 'pass' => $pass] = require $localOverride;
}

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Koneksi database gagal: ' . $e->getMessage());
}

/**
 * Get database connection instance
 * @return PDO Database connection
 */
function db()
{
    global $pdo;
    return $pdo;
}

/**
 * Get global settings from the database
 * @return array Associative array of settings
 */
function getSettings(): array
{
    global $pdo;
    
    try {
        $stmt = $pdo->query('SELECT * FROM settings LIMIT 1');
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Return default values if no settings found
        if (!$settings) {
            return [
                'brand_name' => 'BRAND',
                'meta_title' => 'Lookbook',
                'meta_description' => ''
            ];
        }
        
        return $settings;
    } catch (PDOException $e) {
        // Return defaults on database error
        return [
            'brand_name' => 'BRAND',
            'meta_title' => 'Lookbook',
            'meta_description' => ''
        ];
    }
}

/*
|--------------------------------------------------------------------------
| PayPal settings
|--------------------------------------------------------------------------
| Mode 'sandbox' untuk testing, 'live' untuk produksi.
| Client ID & Secret ambil dari PayPal Developer Dashboard.
*/

const PAYPAL_ENABLED       = false;  // PayPal disembunyikan dulu; ubah ke true saat siap dipakai
const PAYPAL_MODE          = 'live'; // ganti ke 'live' saat go-live
const PAYPAL_CLIENT_ID     = 'AYbj0pZ8UAsqBapG-rRCDvtjsKRsfWjX3nT1g5D-qiM6nv2FQ5jDFU65xFTut7z9T_vpfzYxFl_cHmRj';
const PAYPAL_CLIENT_SECRET = 'EBLeatg0L_rIDvtBCTpaaba-lkBt-gR8_8yd0o_0zH2jklQ3vqZIfBtGNGDjQqWqwHS0q9BgEU1RE0vv';
