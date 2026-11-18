<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/partials/sales_analytics.php';

$activePage = 'dashboard.php';

/* ── analitik penjualan ──────────────────────────────── */
$rangeDays      = analyticsRangeFromRequest();
$currentWindow  = analyticsWindow($pdo, $rangeDays);
$previousWindow = analyticsWindow($pdo, $rangeDays, $rangeDays);

$current  = fetchSalesSummary($pdo, $currentWindow);
$previous = fetchSalesSummary($pdo, $previousWindow);
$daily    = fetchDailyRevenue($pdo, $currentWindow);
$topProducts      = fetchTopProducts($pdo, $currentWindow);
$paymentBreakdown = fetchPaymentBreakdown($pdo, $currentWindow);

$chartPaths = buildChartPaths($daily);
$chartData  = array_map(
    fn($day, $revenue) => ['d' => date('j M', strtotime($day)), 'v' => $revenue],
    array_keys($daily),
    array_values($daily)
);
$peakRevenue = max($daily ?: [0]);

/* ── angka ringkas toko ──────────────────────────────── */
$totalProducts       = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$totalActiveProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
$awaitingPayment     = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'pending'")->fetchColumn();

$recentPaidOrders = $pdo->query(
    "SELECT id, order_number, full_name, currency, grand_total, fulfillment_status,
            COALESCE(payment_verified_at, created_at) AS paid_at
     FROM orders WHERE payment_status = 'paid'
     ORDER BY paid_at DESC, id DESC LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

$recentPendingOrders = $pdo->query(
    "SELECT id, order_number, full_name, currency, grand_total, payment_status, created_at
     FROM orders WHERE payment_status = 'pending'
     ORDER BY id DESC LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

/* ── helper tampilan ─────────────────────────────────── */
function formatOrderMoney(string $currency, float $amount): string
{
    return strtoupper($currency) === 'USD'
        ? '$' . number_format($amount, 2, '.', ',')
        : formatIdr($amount);
}

function paymentBadgeClass(string $status): string
{
    return match (strtolower(trim($status))) {
        'paid'                            => 'paid',
        'failed', 'expired', 'cancelled'  => 'failed',
        default                           => 'pending',
    };
}

function renderDelta(?float $percent): string
{
    if ($percent === null) {
        return '<span class="delta delta--flat">New</span>';
    }
    $direction = $percent > 0 ? 'up' : ($percent < 0 ? 'down' : 'flat');
    $arrow     = ['up' => '↑', 'down' => '↓', 'flat' => '–'][$direction];
    return '<span class="delta delta--' . $direction . '">' . $arrow . ' ' . number_format(abs($percent), 1, ',', '.') . '%</span>';
}

$kpis = [
    ['Revenue',       formatIdr($current['revenue']),  percentChange($current['revenue'], $previous['revenue'])],
    ['Paid orders',   (string)$current['orders'],      percentChange($current['orders'], $previous['orders'])],
    ['Avg. order',    formatIdr($current['average']),  percentChange($current['average'], $previous['average'])],
];

$statusSegments = [
    'paid'      => ['Paid', '#111'],
    'pending'   => ['Pending', '#a8a59c'],
    'expired'   => ['Expired', '#d6d2c7'],
    'failed'    => ['Failed', '#e2b7b0'],
    'cancelled' => ['Cancelled', '#efc9c3'],
];
$breakdownTotal = array_sum($paymentBreakdown) ?: 1;
$topRevenueMax  = max(array_column($topProducts, 'revenue') ?: [0]) ?: 1;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>albrycia — Admin Dashboard</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, Arial, sans-serif;
      background: #fff; color: var(--ink);
      -webkit-font-smoothing: antialiased;
    }
    a { color: inherit; text-decoration: none; }

    .main { max-width: 1280px; margin: 0 auto; padding: 32px 36px 64px; }

    /* ── header ── */
    .page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 26px; }
    .eyebrow { font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: var(--muted); margin-bottom: 8px; }
    h1 { margin: 0; font-size: 34px; line-height: 1.05; letter-spacing: -.022em; font-weight: 600; }
    .segmented { display: inline-flex; padding: 3px; border-radius: 11px; background: #f1efe9; }
    .segmented a {
      padding: 7px 16px; border-radius: 8px; font-size: 13px; font-weight: 500; color: #55534d;
      transition: background .25s var(--spring), color .2s ease, box-shadow .25s ease;
    }
    .segmented a.is-active { background: #fff; color: var(--ink); box-shadow: 0 1px 3px rgba(0,0,0,.12); }

    /* ── kartu ── */
    .card { border: 1px solid var(--line); border-radius: 16px; background: #fff; }
    .card__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px 0; }
    .card__title { font-size: 15px; font-weight: 600; letter-spacing: -.01em; }
    .card__body { padding: 18px 22px 22px; }
    .link { font-size: 12.5px; color: var(--muted); transition: color .15s; }
    .link:hover { color: var(--ink); }

    /* ── KPI ── */
    .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 14px; }
    .kpi { padding: 20px 22px; }
    .kpi__label { font-size: 12.5px; color: var(--muted); margin-bottom: 14px; }
    .kpi__value { font-size: 28px; line-height: 1; font-weight: 600; letter-spacing: -.025em; font-variant-numeric: tabular-nums; }
    .kpi__foot { margin-top: 12px; font-size: 12px; color: var(--muted); display: flex; gap: 8px; align-items: center; }
    .delta { font-weight: 600; font-variant-numeric: tabular-nums; }
    .delta--up { color: #1f7a45; } .delta--down { color: #b3261e; } .delta--flat { color: var(--muted); }

    /* ── chart ── */
    .chart-card { margin-bottom: 14px; }
    .chart { position: relative; height: 260px; margin-top: 8px; touch-action: pan-y; cursor: crosshair; }
    .chart svg { width: 100%; height: 100%; display: block; overflow: visible; }
    .chart__area { fill: url(#areaFill); }
    .chart__line { fill: none; stroke: var(--ink); stroke-width: 2; vector-effect: non-scaling-stroke; stroke-linejoin: round; stroke-linecap: round; }
    .chart__grid { stroke: #efede7; stroke-width: 1; vector-effect: non-scaling-stroke; }
    .chart__cursor { position: absolute; top: 0; bottom: 0; width: 1px; background: rgba(17,17,17,.25); pointer-events: none; opacity: 0; transition: opacity .15s; }
    .chart__dot { position: absolute; width: 10px; height: 10px; margin: -5px 0 0 -5px; border-radius: 50%; background: var(--ink); border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.3); pointer-events: none; opacity: 0; transition: opacity .15s; }
    .chart.is-active .chart__cursor, .chart.is-active .chart__dot { opacity: 1; }
    .chart__tip {
      position: absolute; top: -6px; transform: translate(-50%, -100%);
      padding: 7px 11px; border-radius: 10px; font-size: 12px; white-space: nowrap; pointer-events: none;
      background: rgba(17,17,17,.88); color: #fff; opacity: 0; transition: opacity .15s;
      -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
    }
    .chart.is-active .chart__tip { opacity: 1; }
    .chart__tip b { display: block; font-size: 13px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .chart__axis { display: flex; justify-content: space-between; margin-top: 10px; font-size: 11.5px; color: var(--muted); }
    .chart__peak { font-size: 12px; color: var(--muted); }
    .chart__line, .chart__area { animation: draw .9s var(--spring) both; }
    @keyframes draw { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

    /* ── grid dua kolom ── */
    .grid-2 { display: grid; grid-template-columns: 1.4fr 1fr; gap: 14px; margin-bottom: 14px; }
    .grid-2--even { grid-template-columns: 1fr 1fr; }

    .rank { display: grid; gap: 16px; }
    .rank__row { display: grid; gap: 7px; }
    .rank__top { display: flex; justify-content: space-between; gap: 12px; font-size: 13.5px; }
    .rank__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rank__meta { color: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .bar { height: 6px; border-radius: 99px; background: #f1efe9; overflow: hidden; }
    .bar > i { display: block; height: 100%; border-radius: 99px; background: var(--ink); transform-origin: left; animation: grow .8s var(--spring) both; }
    @keyframes grow { from { transform: scaleX(0); } }

    .stack { display: flex; height: 10px; border-radius: 99px; overflow: hidden; gap: 2px; margin: 4px 0 20px; }
    .stack > i { display: block; height: 100%; }
    .legend { display: grid; gap: 10px; }
    .legend__row { display: flex; align-items: center; justify-content: space-between; font-size: 13.5px; }
    .legend__row span:first-child { display: flex; align-items: center; gap: 9px; }
    .legend__dot { width: 9px; height: 9px; border-radius: 50%; }
    .legend__row b { font-variant-numeric: tabular-nums; font-weight: 600; }

    .empty { padding: 26px 0; text-align: center; font-size: 13px; color: var(--muted); }

    /* ── tabel ── */
    .table-wrap { overflow-x: auto; margin: 0 -22px -22px; }
    table { width: 100%; border-collapse: collapse; min-width: 480px; }
    th, td { padding: 13px 22px; text-align: left; font-size: 13px; border-top: 1px solid #f0eee8; vertical-align: middle; }
    th { font-size: 11px; font-weight: 600; letter-spacing: .07em; text-transform: uppercase; color: var(--muted); border-top: 0; }
    td small { display: block; color: var(--muted); font-size: 12px; margin-top: 2px; }
    td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
    tbody tr { transition: background .15s; }
    tbody tr:hover { background: #faf9f5; }
    .status { display: inline-block; padding: 4px 10px; border-radius: 99px; font-size: 11.5px; font-weight: 500; background: #f1efe9; color: #55534d; text-transform: capitalize; }
    .status.paid { background: #e3f4ea; color: #1f7a45; }
    .status.waiting { background: #fff1d6; color: #8a5a00; }
    .status.failed { background: #fbe4e1; color: #b3261e; }

    /* ── responsive ── */
    @media (max-width: 1100px) { .kpis { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 900px) {
      .main { padding: 72px 18px 48px; }
      .grid-2, .grid-2--even { grid-template-columns: 1fr; }
      h1 { font-size: 28px; }
    }
    @media (max-width: 520px) { .kpis { grid-template-columns: 1fr; } .chart { height: 200px; } }
    @media (prefers-reduced-motion: reduce) {
      .chart__line, .chart__area, .bar > i { animation: none; }
      .segmented a, tbody tr { transition: none; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/admin-sidebar.css?v=<?= @filemtime(__DIR__ . '/../assets/css/admin-sidebar.css') ?>">
</head>
<body class="has-sidebar">

  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <main class="main">
    <header class="page-head">
      <div>
        <div class="eyebrow">Sales analytics</div>
        <h1>Overview</h1>
      </div>
      <nav class="segmented" aria-label="Date range">
        <?php foreach (ANALYTICS_RANGES as $days): ?>
          <a href="?range=<?= $days ?>" class="<?= $days === $rangeDays ? 'is-active' : '' ?>"<?= $days === $rangeDays ? ' aria-current="true"' : '' ?>><?= $days ?> days</a>
        <?php endforeach; ?>
      </nav>
    </header>

    <!-- KPI -->
    <section class="kpis" aria-label="Key figures">
      <?php foreach ($kpis as [$label, $value, $change]): ?>
        <article class="card kpi">
          <div class="kpi__label"><?= htmlspecialchars($label) ?></div>
          <div class="kpi__value"><?= htmlspecialchars($value) ?></div>
          <div class="kpi__foot"><?= renderDelta($change) ?> <span>vs previous <?= $rangeDays ?> days</span></div>
        </article>
      <?php endforeach; ?>
      <a href="dashboard-orders.php?status=pending" class="card kpi">
        <div class="kpi__label">Awaiting payment</div>
        <div class="kpi__value"><?= $awaitingPayment ?></div>
        <div class="kpi__foot"><span><?= $totalActiveProducts ?> of <?= $totalProducts ?> products live</span></div>
      </a>
    </section>

    <!-- Grafik -->
    <section class="card chart-card" aria-label="Daily revenue">
      <div class="card__head">
        <span class="card__title">Daily revenue</span>
        <span class="chart__peak">Peak <?= htmlspecialchars(formatIdrCompact($peakRevenue)) ?></span>
      </div>
      <div class="card__body">
        <div class="chart" id="chart">
          <svg viewBox="0 0 100 40" preserveAspectRatio="none" role="img" aria-label="Revenue per day for the last <?= $rangeDays ?> days">
            <defs>
              <linearGradient id="areaFill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#111" stop-opacity=".14"/>
                <stop offset="1" stop-color="#111" stop-opacity="0"/>
              </linearGradient>
            </defs>
            <?php foreach ([10, 20, 30] as $y): ?><line class="chart__grid" x1="0" x2="100" y1="<?= $y ?>" y2="<?= $y ?>"/><?php endforeach; ?>
            <path class="chart__area" d="<?= $chartPaths['area'] ?>"/>
            <path class="chart__line" d="<?= $chartPaths['line'] ?>"/>
          </svg>
          <div class="chart__cursor"></div>
          <div class="chart__dot"></div>
          <div class="chart__tip"><span></span><b></b></div>
        </div>
        <div class="chart__axis">
          <span><?= htmlspecialchars($chartData[0]['d'] ?? '') ?></span>
          <span><?= htmlspecialchars($chartData[count($chartData) - 1]['d'] ?? '') ?></span>
        </div>
      </div>
    </section>

    <!-- Produk terlaris + status pembayaran -->
    <section class="grid-2">
      <div class="card">
        <div class="card__head"><span class="card__title">Top products</span></div>
        <div class="card__body">
          <?php if (!$topProducts): ?>
            <div class="empty">No sales in this period yet.</div>
          <?php else: ?>
            <div class="rank">
              <?php foreach ($topProducts as $product): ?>
                <div class="rank__row">
                  <div class="rank__top">
                    <span class="rank__name"><?= htmlspecialchars($product['name']) ?></span>
                    <span class="rank__meta"><?= $product['quantity'] ?> sold · <?= htmlspecialchars(formatIdrCompact($product['revenue'])) ?></span>
                  </div>
                  <div class="bar"><i style="width: <?= round($product['revenue'] / $topRevenueMax * 100, 1) ?>%"></i></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><span class="card__title">Payment status</span></div>
        <div class="card__body">
          <div class="stack" role="img" aria-label="Order payment status breakdown">
            <?php foreach ($statusSegments as $key => [$label, $color]): ?>
              <?php if (!empty($paymentBreakdown[$key])): ?>
                <i style="width: <?= round($paymentBreakdown[$key] / $breakdownTotal * 100, 2) ?>%; background: <?= $color ?>"></i>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <div class="legend">
            <?php foreach ($statusSegments as $key => [$label, $color]): ?>
              <div class="legend__row">
                <span><i class="legend__dot" style="background: <?= $color ?>"></i><?= $label ?></span>
                <b><?= (int)($paymentBreakdown[$key] ?? 0) ?></b>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- Order terbaru -->
    <section class="grid-2 grid-2--even">
      <div class="card">
        <div class="card__head">
          <span class="card__title">Awaiting payment</span>
          <a href="dashboard-orders.php" class="link">View all</a>
        </div>
        <div class="card__body">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Order</th><th class="num">Total</th><th>Status</th></tr></thead>
              <tbody>
                <?php if (!$recentPendingOrders): ?>
                  <tr><td colspan="3" class="empty">Nothing waiting. All caught up.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentPendingOrders as $order): ?>
                  <tr>
                    <td>
                      <a href="order-detail.php?id=<?= (int)$order['id'] ?>"><?= htmlspecialchars($order['order_number']) ?></a>
                      <small><?= htmlspecialchars($order['full_name']) ?></small>
                    </td>
                    <td class="num"><?= htmlspecialchars(formatOrderMoney($order['currency'], (float)$order['grand_total'])) ?></td>
                    <td><span class="status <?= paymentBadgeClass($order['payment_status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $order['payment_status'])) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <span class="card__title">Recent paid orders</span>
          <a href="dashboard-orders.php?status=paid" class="link">View all</a>
        </div>
        <div class="card__body">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Order</th><th class="num">Total</th><th>Fulfillment</th></tr></thead>
              <tbody>
                <?php if (!$recentPaidOrders): ?>
                  <tr><td colspan="3" class="empty">No paid orders yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentPaidOrders as $order): ?>
                  <tr>
                    <td>
                      <a href="order-detail.php?id=<?= (int)$order['id'] ?>"><?= htmlspecialchars($order['order_number']) ?></a>
                      <small><?= htmlspecialchars($order['full_name']) ?></small>
                    </td>
                    <td class="num"><?= htmlspecialchars(formatOrderMoney($order['currency'], (float)$order['grand_total'])) ?></td>
                    <td><span class="status"><?= htmlspecialchars($order['fulfillment_status']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  </main>

  <script>
  (function () {
    'use strict';
    const POINTS = <?= json_encode($chartData) ?>;
    const chart  = document.getElementById('chart');
    const cursor = chart.querySelector('.chart__cursor');
    const dot    = chart.querySelector('.chart__dot');
    const tip    = chart.querySelector('.chart__tip');
    const max    = Math.max(...POINTS.map(p => p.v), 1);
    const money  = v => 'Rp ' + Math.round(v).toLocaleString('id-ID');

    /* kursor mengikuti pointer 1:1, tanpa delay */
    function track(event) {
      const box   = chart.getBoundingClientRect();
      const ratio = Math.min(Math.max((event.clientX - box.left) / box.width, 0), 1);
      const index = Math.round(ratio * (POINTS.length - 1));
      const point = POINTS[index];
      const x = POINTS.length > 1 ? index / (POINTS.length - 1) * box.width : box.width / 2;
      const y = box.height - (point.v / max) * (box.height * .9);

      cursor.style.left = x + 'px';
      dot.style.left = x + 'px';
      dot.style.top = y + 'px';
      tip.style.left = Math.min(Math.max(x, 70), box.width - 70) + 'px';
      tip.firstElementChild.textContent = point.d;
      tip.lastElementChild.textContent = money(point.v);
      chart.classList.add('is-active');
    }

    chart.addEventListener('pointerdown', e => { chart.setPointerCapture(e.pointerId); track(e); });
    chart.addEventListener('pointermove', track);
    chart.addEventListener('pointerleave', () => chart.classList.remove('is-active'));
    chart.addEventListener('pointerup', e => { if (e.pointerType !== 'mouse') chart.classList.remove('is-active'); });
  })();
  </script>
</body>
</html>
