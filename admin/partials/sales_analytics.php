<?php
/**
 * Query dan helper analitik penjualan untuk dashboard admin.
 * Semua nilai uang dinormalkan ke IDR (order USD × kurs toko).
 */

const ANALYTICS_RANGES = [7, 30, 90];
const ANALYTICS_DEFAULT_RANGE = 30;
const ANALYTICS_TOP_PRODUCTS = 5;

function analyticsUsdRate(): float
{
    return defined('USD_RATE_FROM_IDR') ? (float)USD_RATE_FROM_IDR : 16000.0;
}

function analyticsRangeFromRequest(): int
{
    $days = (int)($_GET['range'] ?? ANALYTICS_DEFAULT_RANGE);
    return in_array($days, ANALYTICS_RANGES, true) ? $days : ANALYTICS_DEFAULT_RANGE;
}

/** Ekspresi SQL: total order dalam IDR. */
function sqlRevenueIdr(string $alias = 'o'): string
{
    return "(CASE WHEN {$alias}.currency = 'USD' THEN {$alias}.grand_total * :rate ELSE {$alias}.grand_total END)";
}

/** Ekspresi SQL: tanggal order dianggap terjadi (saat dibayar, fallback saat dibuat). */
function sqlSaleDate(string $alias = 'o'): string
{
    return "DATE(COALESCE({$alias}.payment_verified_at, {$alias}.created_at))";
}

/**
 * Rentang [from, to] inklusif, $offsetDays menggeser mundur.
 * "Hari ini" diambil dari database agar sama dengan zona waktu kolom tanggal order.
 * @return array{from: string, to: string}
 */
function analyticsWindow(PDO $pdo, int $days, int $offsetDays = 0): array
{
    $to   = (new DateTimeImmutable((string)$pdo->query('SELECT CURDATE()')->fetchColumn()))->modify('-' . $offsetDays . ' days');
    $from = $to->modify('-' . ($days - 1) . ' days');
    return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
}

/** @return array{revenue: float, orders: int, average: float} */
function fetchSalesSummary(PDO $pdo, array $window): array
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(' . sqlRevenueIdr() . '), 0) AS revenue, COUNT(*) AS orders
         FROM orders o
         WHERE o.payment_status = \'paid\' AND ' . sqlSaleDate() . ' BETWEEN :from AND :to'
    );
    $stmt->execute(['rate' => analyticsUsdRate()] + $window);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $revenue = (float)$row['revenue'];
    $orders  = (int)$row['orders'];
    return ['revenue' => $revenue, 'orders' => $orders, 'average' => $orders > 0 ? $revenue / $orders : 0.0];
}

/** @return array<string, float> tanggal (Y-m-d) → omzet, semua hari terisi (0 jika kosong) */
function fetchDailyRevenue(PDO $pdo, array $window): array
{
    $stmt = $pdo->prepare(
        'SELECT ' . sqlSaleDate() . ' AS day, SUM(' . sqlRevenueIdr() . ') AS revenue
         FROM orders o
         WHERE o.payment_status = \'paid\' AND ' . sqlSaleDate() . ' BETWEEN :from AND :to
         GROUP BY day'
    );
    $stmt->execute(['rate' => analyticsUsdRate()] + $window);
    return fillMissingDays($stmt->fetchAll(PDO::FETCH_KEY_PAIR), $window);
}

function fillMissingDays(array $revenueByDay, array $window): array
{
    $filled = [];
    $day    = new DateTimeImmutable($window['from']);
    $last   = new DateTimeImmutable($window['to']);
    for (; $day <= $last; $day = $day->modify('+1 day')) {
        $key = $day->format('Y-m-d');
        $filled[$key] = (float)($revenueByDay[$key] ?? 0);
    }
    return $filled;
}

/** @return list<array{name: string, quantity: int, revenue: float}> */
function fetchTopProducts(PDO $pdo, array $window): array
{
    $stmt = $pdo->prepare(
        'SELECT oi.product_name AS name, SUM(oi.quantity) AS quantity,
                SUM(CASE WHEN o.currency = \'USD\' THEN oi.line_total * :rate ELSE oi.line_total END) AS revenue
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.payment_status = \'paid\' AND ' . sqlSaleDate() . ' BETWEEN :from AND :to
         GROUP BY oi.product_name
         ORDER BY revenue DESC
         LIMIT ' . ANALYTICS_TOP_PRODUCTS
    );
    $stmt->execute(['rate' => analyticsUsdRate()] + $window);
    return array_map(
        fn($r) => ['name' => (string)$r['name'], 'quantity' => (int)$r['quantity'], 'revenue' => (float)$r['revenue']],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

/** @return array<string, int> payment_status → jumlah order dalam rentang (berdasarkan tanggal dibuat) */
function fetchPaymentBreakdown(PDO $pdo, array $window): array
{
    $stmt = $pdo->prepare(
        'SELECT payment_status, COUNT(*) FROM orders
         WHERE DATE(created_at) BETWEEN :from AND :to GROUP BY payment_status'
    );
    $stmt->execute($window);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Perubahan persen dibanding periode sebelumnya; null kalau pembanding 0. */
function percentChange(float $current, float $previous): ?float
{
    return $previous > 0 ? (($current - $previous) / $previous) * 100 : null;
}

function formatIdr(float $amount): string
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/** Singkat angka besar: 1,2 jt / 3,4 rb. */
function formatIdrCompact(float $amount): string
{
    if ($amount >= 1000000) return 'Rp ' . rtrim(rtrim(number_format($amount / 1000000, 1, ',', ''), '0'), ',') . ' jt';
    if ($amount >= 1000)    return 'Rp ' . rtrim(rtrim(number_format($amount / 1000, 1, ',', ''), '0'), ',') . ' rb';
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/**
 * Path SVG (viewBox 0..100 × 0..40) untuk garis dan area dari deret nilai.
 * @return array{line: string, area: string}
 */
function buildChartPaths(array $values): array
{
    $points = array_values($values);
    $count  = count($points);
    $max    = max($points ?: [0]) ?: 1;
    $height = 40;
    $top    = 4;

    $coords = [];
    foreach ($points as $i => $value) {
        $x = $count > 1 ? $i / ($count - 1) * 100 : 50;
        $y = $height - ($value / $max) * ($height - $top);
        $coords[] = round($x, 2) . ' ' . round($y, 2);
    }
    $line = 'M' . implode(' L', $coords);
    return ['line' => $line, 'area' => $line . " L100 {$height} L0 {$height} Z"];
}
