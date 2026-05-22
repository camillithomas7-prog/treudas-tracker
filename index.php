<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/stats.php';

// Anti-cache aggressivo: il dashboard deve essere SEMPRE fresco
// (Hostinger/LiteSpeed/proxy/browser non devono memorizzare la pagina)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Expires: 0');           // nginx
header('Surrogate-Control: no-store');  // CDN/Varnish/LiteSpeed
header('X-LiteSpeed-Cache-Control: no-cache, no-store, private');

// Dashboard senza autenticazione (URL segreto)
$user = ['email' => 'TREUDAS'];
tracker_install_schema();

date_default_timezone_set(tracker_config()['timezone']);

// ─── Filtri ──────────────────────────────────────────────────────────
$preset = $_GET['range'] ?? '7d';
$now = time();
switch ($preset) {
    case 'today':   $from = strtotime('today'); $to = $now; break;
    case 'yesterday': $from = strtotime('yesterday'); $to = strtotime('today') - 1; break;
    case '7d':      $from = $now - 7 * 86400; $to = $now; break;
    case '30d':     $from = $now - 30 * 86400; $to = $now; break;
    case '90d':     $from = $now - 90 * 86400; $to = $now; break;
    case 'all':     $from = 0; $to = $now; break;
    case 'custom':
        $from = !empty($_GET['from']) ? strtotime($_GET['from']) : ($now - 7 * 86400);
        $to   = !empty($_GET['to'])   ? strtotime($_GET['to'] . ' 23:59:59') : $now;
        break;
    default: $from = $now - 7 * 86400; $to = $now;
}

$filters = [
    'from'         => $from,
    'to'           => $to,
    'utm_source'   => $_GET['utm_source']   ?? '',
    'utm_medium'   => $_GET['utm_medium']   ?? '',
    'utm_campaign' => $_GET['utm_campaign'] ?? '',
    'utm_term'     => $_GET['utm_term']     ?? '',
    'utm_content'  => $_GET['utm_content']  ?? '',
    'device_type'  => $_GET['device_type']  ?? '',
];
foreach ($filters as $k => $v) if ($v === '') unset($filters[$k]);

$funnel    = tr_funnel($filters);
$campaigns = tr_campaign_breakdown($filters);
$creatives = tr_creative_breakdown($filters);
$trend     = tr_daily_trend($filters);
$orders    = tr_recent_purchases(20, $filters);

$topCount    = (int)($funnel[0]['count'] ?? 0);
$topCountDiv = max(1, $topCount);

// Totali calcolati direttamente da orders (così include anche ordini senza session_id)
$ordersQuery = "SELECT COUNT(*) AS n, COALESCE(SUM(total_price),0) AS r FROM orders WHERE created_at BETWEEN :from AND :to";
$stmt = tracker_db()->prepare($ordersQuery);
$stmt->execute([':from' => $from, ':to' => $to]);
$ordersRow = $stmt->fetch();
$totalOrders  = (int)$ordersRow['n'];
$totalRevenue = (float)$ordersRow['r'];
$aov = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
$rps = $topCount > 0 ? $totalRevenue / $topCount : 0;

$sources    = tr_distinct('utm_source');
$mediums    = tr_distinct('utm_medium');
$campaignsD = tr_distinct('utm_campaign');
$adsetsD    = tr_distinct('utm_term');
$creativesD = tr_distinct('utm_content');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<meta http-equiv="refresh" content="60">
<title>TREUDAS Tracker — Dashboard</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<header class="topbar">
    <div class="brand">TREUDAS <span>Tracker</span></div>
    <div class="user-info">
        <span>● LIVE</span>
        <span><?= date('d M H:i') ?></span>
    </div>
</header>

<main class="container">

    <form method="get" class="filters">
        <div class="filter-pills">
            <?php foreach (['today'=>'Oggi','yesterday'=>'Ieri','7d'=>'7gg','30d'=>'30gg','90d'=>'90gg','all'=>'Tutto'] as $k=>$v): ?>
                <a href="?range=<?= $k ?>" class="pill <?= $preset===$k ? 'active' : '' ?>"><?= $v ?></a>
            <?php endforeach; ?>
        </div>
        <div class="filter-row">
            <label>Sorgente
                <select name="utm_source">
                    <option value="">— Tutte —</option>
                    <?php foreach ($sources as $s): ?>
                        <option value="<?= tr_h($s) ?>" <?= ($_GET['utm_source'] ?? '')===$s?'selected':'' ?>><?= tr_h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Campagna
                <select name="utm_campaign">
                    <option value="">— Tutte —</option>
                    <?php foreach ($campaignsD as $s): ?>
                        <option value="<?= tr_h($s) ?>" <?= ($_GET['utm_campaign'] ?? '')===$s?'selected':'' ?>><?= tr_h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Adset
                <select name="utm_term">
                    <option value="">— Tutti —</option>
                    <?php foreach ($adsetsD as $s): ?>
                        <option value="<?= tr_h($s) ?>" <?= ($_GET['utm_term'] ?? '')===$s?'selected':'' ?>><?= tr_h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Creative
                <select name="utm_content">
                    <option value="">— Tutti —</option>
                    <?php foreach ($creativesD as $s): ?>
                        <option value="<?= tr_h($s) ?>" <?= ($_GET['utm_content'] ?? '')===$s?'selected':'' ?>><?= tr_h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Dispositivo
                <select name="device_type">
                    <option value="">— Tutti —</option>
                    <?php foreach (['mobile','desktop','tablet'] as $d): ?>
                        <option value="<?= $d ?>" <?= ($_GET['device_type']??'')===$d?'selected':'' ?>><?= ucfirst($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <input type="hidden" name="range" value="<?= tr_h($preset) ?>">
            <button type="submit">Applica</button>
            <a href="/" class="btn-ghost">Reset</a>
        </div>
    </form>

    <!-- KPI cards -->
    <section class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Visitatori advertorial</div>
            <div class="kpi-value"><?= number_format($topCount, 0, ',', '.') ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Acquisti</div>
            <div class="kpi-value"><?= number_format($totalOrders, 0, ',', '.') ?></div>
            <div class="kpi-sub"><?= tr_pct($totalOrders, $topCountDiv) ?> tasso conversione</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Fatturato</div>
            <div class="kpi-value"><?= tr_money($totalRevenue) ?></div>
            <div class="kpi-sub">AOV: <?= tr_money($aov) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Ricavo per visita</div>
            <div class="kpi-value"><?= tr_money($rps) ?></div>
        </div>
    </section>

    <!-- Funnel -->
    <section class="card">
        <h2>Imbuto di conversione</h2>
        <div class="funnel">
            <?php foreach ($funnel as $i => $step):
                $w = $topCount > 0 ? max(5, ($step['count'] / $topCountDiv) * 100) : 5;
                $pct = tr_pct($step['count'], $topCountDiv);
            ?>
            <div class="funnel-row">
                <div class="funnel-label"><?= ($i+1).'. '.tr_h($step['label']) ?></div>
                <div class="funnel-bar-wrap">
                    <div class="funnel-bar" style="width: <?= $w ?>%"></div>
                </div>
                <div class="funnel-count">
                    <strong><?= number_format($step['count'], 0, ',', '.') ?></strong>
                    <span class="muted"><?= $pct ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Campaign breakdown -->
    <section class="card">
        <h2>Performance per campagna</h2>
        <?php if (!$campaigns): ?>
            <p class="muted">Ancora nessun dato per questo periodo.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Campagna</th>
                    <th>Sorgente</th>
                    <th class="num">Sessioni</th>
                    <th class="num">→ Prodotto</th>
                    <th class="num">→ Carrello</th>
                    <th class="num">Acquisti</th>
                    <th class="num">Conv.</th>
                    <th class="num">Fatturato</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($campaigns as $c): ?>
                <tr>
                    <td><?= tr_h($c['campagna']) ?></td>
                    <td><?= tr_h($c['source']) ?></td>
                    <td class="num"><?= number_format((int)$c['sessions'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format((int)$c['product_views'], 0, ',', '.') ?>
                        <span class="muted small"><?= tr_pct((int)$c['product_views'], (int)$c['sessions']) ?></span>
                    </td>
                    <td class="num"><?= number_format((int)$c['add_to_carts'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format((int)$c['purchases'], 0, ',', '.') ?></td>
                    <td class="num <?= $c['sessions']>0 && ($c['purchases']/$c['sessions'])>=0.03 ? 'pos' : 'neg' ?>">
                        <?= tr_pct((int)$c['purchases'], (int)$c['sessions']) ?>
                    </td>
                    <td class="num"><?= tr_money((float)$c['revenue']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <!-- Creative/Ad breakdown -->
    <section class="card">
        <h2>Performance per ad (campagna → adset → creative)</h2>
        <?php if (!$creatives): ?>
            <p class="muted">Ancora nessun dato per questo periodo. Quando le ads saranno live e i clienti cliccheranno gli annunci con UTM, qui vedrai la performance di ogni singolo creative.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Campagna</th>
                    <th>Adset</th>
                    <th>Creative</th>
                    <th class="num">Sessioni</th>
                    <th class="num">→ Prodotto</th>
                    <th class="num">→ Carrello</th>
                    <th class="num">Checkout</th>
                    <th class="num">Acquisti</th>
                    <th class="num">Conv.</th>
                    <th class="num">Fatturato</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($creatives as $c): ?>
                <tr>
                    <td><?= tr_h($c['campagna']) ?></td>
                    <td><?= tr_h($c['adset']) ?></td>
                    <td><?= tr_h($c['creative']) ?></td>
                    <td class="num"><?= number_format((int)$c['sessions'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format((int)$c['product_views'], 0, ',', '.') ?>
                        <span class="muted small"><?= tr_pct((int)$c['product_views'], (int)$c['sessions']) ?></span>
                    </td>
                    <td class="num"><?= number_format((int)$c['add_to_carts'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format((int)$c['checkouts'], 0, ',', '.') ?></td>
                    <td class="num"><?= number_format((int)$c['purchases'], 0, ',', '.') ?></td>
                    <td class="num <?= $c['sessions']>0 && ($c['purchases']/$c['sessions'])>=0.03 ? 'pos' : 'neg' ?>">
                        <?= tr_pct((int)$c['purchases'], (int)$c['sessions']) ?>
                    </td>
                    <td class="num"><?= tr_money((float)$c['revenue']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <!-- Trend giornaliero -->
    <section class="card">
        <h2>Andamento giornaliero</h2>
        <?php if (!$trend): ?>
            <p class="muted">Ancora nessun dato.</p>
        <?php else:
            $maxS = max(array_map(fn($r) => (int)$r['sessions'], $trend));
        ?>
        <div class="trend">
            <?php foreach ($trend as $r):
                $h = $maxS > 0 ? max(2, ((int)$r['sessions']/$maxS) * 100) : 2;
            ?>
            <div class="trend-bar" title="<?= tr_h($r['day']) ?>: <?= $r['sessions'] ?> sessioni, <?= $r['purchases'] ?> acquisti">
                <div class="trend-fill" style="height: <?= $h ?>%"></div>
                <div class="trend-label"><?= substr($r['day'], 5) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- Ultimi acquisti -->
    <section class="card">
        <h2>Ultimi acquisti</h2>
        <?php if (!$orders): ?>
            <p class="muted">Nessun acquisto registrato.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Ordine</th><th>Data</th><th>Email</th><th>Campagna</th><th class="num">Totale</th></tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td>#<?= tr_h($o['order_number']) ?></td>
                    <td><?= tr_format_dt((int)$o['created_at']) ?></td>
                    <td><?= tr_h($o['email']) ?></td>
                    <td><?= tr_h($o['utm_campaign'] ?: '(nessuna)') ?></td>
                    <td class="num"><?= tr_money((float)$o['total_price'], $o['currency'] ?: 'EUR') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <footer class="footer">
        TREUDAS Tracker · dati dal <?= date('d/m/Y', $from) ?> al <?= date('d/m/Y', $to) ?>
    </footer>
</main>
</body>
</html>
