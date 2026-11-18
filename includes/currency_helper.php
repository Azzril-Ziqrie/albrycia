<?php
/**
 * Mata uang dasar toko: IDR (harga & tagihan selalu rupiah).
 *
 * Mata uang TAMPILAN hanya estimasi untuk pengunjung asing: kurs dari exchangerate-api.com
 * (EXCHANGERATE_API_KEY di config/secrets.php, cache 6 jam) dan negara pengunjung dari IP (ipwho.is).
 */

require_once __DIR__ . '/../config/env.php';

const DD_BASE_CURRENCY = 'IDR';
const DD_RATES_CACHE_SECONDS = 21600;
const DD_ZERO_DECIMAL_CURRENCIES = ['IDR', 'JPY', 'KRW', 'VND', 'CLP', 'HUF', 'ISK', 'UGX', 'XAF', 'XOF', 'PYG', 'RWF', 'KMF', 'DJF', 'GNF', 'VUV', 'XPF'];

/**
 * Kurs IDR→X (1 IDR = berapa X) dari exchangerate-api.com. Gagal → pakai cache lama.
 * @return array<string,float>|null  null kalau belum ada API key / belum pernah berhasil
 */
function ddExchangeRates(): ?array
{
    static $rates = false;
    if ($rates !== false) {
        return $rates;
    }
    $rates = null;

    $apiKey = trim(ddSecret('EXCHANGERATE_API_KEY'));
    if ($apiKey === '') {
        return $rates;
    }

    $cacheFile = sys_get_temp_dir() . '/albrycia-rates-idr-' . md5($apiKey) . '.json';
    $cached    = is_file($cacheFile) ? json_decode((string)@file_get_contents($cacheFile), true) : null;
    $isFresh   = is_array($cached) && $cached && time() - (int)filemtime($cacheFile) < DD_RATES_CACHE_SECONDS;
    if ($isFresh) {
        return $rates = $cached;
    }

    $fetched = ddFetchRates($apiKey);
    if ($fetched) {
        @file_put_contents($cacheFile, json_encode($fetched));
        return $rates = $fetched;
    }
    if (is_array($cached) && $cached) {
        @touch($cacheFile);
        return $rates = $cached;
    }
    return $rates;
}

function ddFetchRates(string $apiKey): ?array
{
    try {
        $ch = curl_init('https://v6.exchangerate-api.com/v6/' . rawurlencode($apiKey) . '/latest/' . DD_BASE_CURRENCY);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = $response !== false && $status === 200 ? json_decode((string)$response, true) : null;
        $isValid = is_array($json) && ($json['result'] ?? '') === 'success' && is_array($json['conversion_rates'] ?? null);
        return $isValid ? $json['conversion_rates'] : null;
    } catch (Throwable $e) {
        error_log('[currency] rates failed: ' . $e->getMessage());
        return null;
    }
}

/** Berapa IDR untuk 1 USD: kurs live, lalu USD_RATE_FROM_IDR dari config, lalu 16000. */
function ddUsdToIdrRate(): float
{
    $usdPerIdr = (float)(ddExchangeRates()['USD'] ?? 0);
    if ($usdPerIdr > 0) {
        return 1 / $usdPerIdr;
    }
    $configured = defined('USD_RATE_FROM_IDR') ? (float)constant('USD_RATE_FROM_IDR') : 0.0;
    return $configured > 0 ? $configured : 16000.0;
}

/** Mata uang tampilan pengunjung; IDR kalau belum memilih / kurs tak tersedia. */
function ddDisplayCurrency(): string
{
    $code = strtoupper((string)($_SESSION['display_currency'] ?? DD_BASE_CURRENCY));
    if ($code === DD_BASE_CURRENCY) {
        return $code;
    }
    $rate = (float)(ddExchangeRates()[$code] ?? 0);
    return $rate > 0 ? $code : DD_BASE_CURRENCY;
}

/** @return array{code:string,rate:float,decimals:int} rate = berapa unit mata uang tampilan untuk 1 IDR */
function ddDisplayInfo(): array
{
    $code = ddDisplayCurrency();
    $rate = $code === DD_BASE_CURRENCY ? 1.0 : (float)(ddExchangeRates()[$code] ?? 1.0);
    return ['code' => $code, 'rate' => $rate, 'decimals' => in_array($code, DD_ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2];
}

function ddFormatIdr(float $amount): string
{
    return 'RP ' . number_format($amount, 0, ',', '.');
}

/** Estimasi harga IDR dalam mata uang tampilan; null kalau tampilan = IDR. */
function ddFormatEstimate(float $amountIdr): ?string
{
    $info = ddDisplayInfo();
    if ($info['code'] === DD_BASE_CURRENCY) {
        return null;
    }
    $value = round($amountIdr * $info['rate'], $info['decimals']);
    return $info['code'] . ' ' . number_format($value, $info['decimals'], '.', ',');
}

/** Snippet JS: window.ddFormatEstimate(idr) → teks atau '' (sama dengan ddFormatEstimate() PHP). */
function ddDisplayJs(): string
{
    return 'window.DD_DISPLAY=' . json_encode(ddDisplayInfo()) . ';'
        . 'window.ddFormatEstimate=function(a){a=parseFloat(a);if(!isFinite(a))a=0;var d=window.DD_DISPLAY;'
        . 'if(d.code==="IDR")return "";var p=Math.pow(10,d.decimals);'
        . 'var v=(Math.round(a*d.rate*p)/p).toFixed(d.decimals).replace(/\B(?=(\d{3})+(?!\d))/g,",");'
        . 'return d.code+" "+v;};';
}

/** IP pengunjung (publik) atau null, mis. localhost / jaringan privat. */
function ddClientIp(): ?string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }
        $ip = trim(explode(',', (string)$_SERVER[$header])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }
    return null;
}

/** Kode negara (ISO-2) → mata uang lokal, untuk negara yang umum. */
function ddCountryCurrencyMap(): array
{
    $map = [
        'ID'=>'IDR','MY'=>'MYR','SG'=>'SGD','TH'=>'THB','VN'=>'VND','PH'=>'PHP','BN'=>'BND','KH'=>'KHR','LA'=>'LAK','MM'=>'MMK',
        'JP'=>'JPY','KR'=>'KRW','CN'=>'CNY','HK'=>'HKD','TW'=>'TWD','IN'=>'INR','PK'=>'PKR','BD'=>'BDT','LK'=>'LKR','NP'=>'NPR',
        'AU'=>'AUD','NZ'=>'NZD','GB'=>'GBP','CH'=>'CHF','NO'=>'NOK','SE'=>'SEK','DK'=>'DKK','PL'=>'PLN','CZ'=>'CZK','HU'=>'HUF',
        'RO'=>'RON','TR'=>'TRY','RU'=>'RUB','UA'=>'UAH','IL'=>'ILS','SA'=>'SAR','AE'=>'AED','QA'=>'QAR','KW'=>'KWD','EG'=>'EGP',
        'ZA'=>'ZAR','NG'=>'NGN','KE'=>'KES','MA'=>'MAD','CA'=>'CAD','MX'=>'MXN','BR'=>'BRL','AR'=>'ARS','CL'=>'CLP','CO'=>'COP',
        'PE'=>'PEN','US'=>'USD',
    ];
    foreach (['AT','BE','CY','DE','EE','ES','FI','FR','GR','HR','IE','IT','LT','LU','LV','MT','NL','PT','SI','SK'] as $country) {
        $map[$country] = 'EUR';
    }
    return $map;
}

/**
 * Negara & mata uang lokal pengunjung dari IP (ipwho.is), dicari sekali per sesi.
 * @return array{country:string,country_code:string,currency:string}|null
 */
function ddDetectVisitorCurrency(): ?array
{
    if (isset($_SESSION['dd_geo'])) {
        return $_SESSION['dd_geo'] ?: null;
    }

    $geo = null;
    $ip  = ddClientIp();
    if ($ip !== null) {
        $geo = ddLookupCountry($ip);
    }
    $_SESSION['dd_geo'] = $geo ?: [];
    return $geo;
}

function ddLookupCountry(string $ip): ?array
{
    try {
        $ch = curl_init('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country,country_code');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
        $response = curl_exec($ch);
        curl_close($ch);

        $json = $response !== false ? json_decode((string)$response, true) : null;
        if (!is_array($json) || empty($json['success']) || empty($json['country_code'])) {
            return null;
        }
        $countryCode = strtoupper((string)$json['country_code']);
        $currency    = ddCountryCurrencyMap()[$countryCode] ?? '';
        return $currency === '' ? null : ['country' => (string)($json['country'] ?? $countryCode), 'country_code' => $countryCode, 'currency' => $currency];
    } catch (Throwable $e) {
        error_log('[currency] geo lookup failed: ' . $e->getMessage());
        return null;
    }
}

/** Data pop-up mata uang: saran berdasarkan IP + daftar mata uang lain. */
function currencyGateModel(): array
{
    $rates   = ddExchangeRates();
    $geo     = ddDetectVisitorCurrency();
    $suggest = null;
    if ($geo && $geo['currency'] !== DD_BASE_CURRENCY && !empty($rates[$geo['currency']])) {
        $suggest = $geo + ['rate' => (float)$rates[$geo['currency']]];
    }

    $options = [];
    foreach (array_unique(array_values(ddCountryCurrencyMap())) as $code) {
        $isOther = $code !== DD_BASE_CURRENCY && (!$suggest || $code !== $suggest['currency']);
        if ($isOther && !empty($rates[$code])) {
            $options[] = $code;
        }
    }
    sort($options);
    return ['suggest' => $suggest, 'options' => $options];
}

/** Pop-up tampil kalau diminta lewat ?choose_currency, atau untuk pengunjung asing yang belum memilih. */
function ddShouldShowCurrencyGate(array $gate): bool
{
    if (isset($_GET['choose_currency'])) {
        return true;
    }
    return empty($_SESSION['currency_prompt_done']) && $gate['suggest'] !== null;
}
