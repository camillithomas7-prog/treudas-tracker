<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/shopify_api.php';
require_once __DIR__ . '/inc/shopify_sync.php';
require_once __DIR__ . '/inc/shopify_stats.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');

tracker_install_schema();
date_default_timezone_set(tracker_config()['timezone']);

if (!sh_get_token()) {
    header('Location: /shopify_oauth.php?need_token=1');
    exit;
}

sh_sync_orders_throttled(60);

$preset = $_GET['range'] ?? '30d';
$now = time();
switch ($preset) {
    case 'today':     $from = strtotime('today'); $to = $now; break;
    case 'yesterday': $from = strtotime('yesterday'); $to = strtotime('today') - 1; break;
    case '7d':        $from = $now - 7 * 86400; $to = $now; break;
    case '30d':       $from = $now - 30 * 86400; $to = $now; break;
    case '90d':       $from = $now - 90 * 86400; $to = $now; break;
    case 'all':       $from = 0; $to = $now; break;
    default:          $from = $now - 30 * 86400; $to = $now;
}

$kpi      = sh_kpi($from, $to);
$trend    = sh_daily_trend($from, $to);
$monthly  = sh_monthly_trend();
$topCit   = sh_top_cities($from, $to, 10);

$maxFatturatoTrend = 0;
foreach ($trend as $r) $maxFatturatoTrend = max($maxFatturatoTrend, (float)$r['fatturato']);
$maxOrdiniTrend = 0;
foreach ($trend as $r) $maxOrdiniTrend = max($maxOrdiniTrend, (int)$r['n']);

$panel_active = 'statistiche';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestionale TREUDAS — Statistiche</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/panel.css?v=<?= @filemtime(__DIR__ . '/assets/panel.css') ?>">
</head>
<body>
<?php include __DIR__ . '/inc/panel_header.php'; ?>

<main class="container">

    <form method="get" class="filters">
        <div class="filter-pills">
            <?php foreach (['today'=>'Oggi','yesterday'=>'Ieri','7d'=>'7gg','30d'=>'30gg','90d'=>'90gg','all'=>'Tutto'] as $k=>$v): ?>
                <a href="?range=<?= $k ?>" class="pill <?= $preset===$k ? 'active' : '' ?>"><?= $v ?></a>
            <?php endforeach; ?>
        </div>
    </form>

    <section class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Ordini</div>
            <div class="kpi-value"><?= number_format((int)$kpi['n'], 0, ',', '.') ?></div>
            <div class="kpi-sub"><?= number_format((int)$kpi['n_cod'], 0, ',', '.') ?> COD · <?= number_format((int)$kpi['n_prepaid'], 0, ',', '.') ?> Prepaid</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Fatturato lordo</div>
            <div class="kpi-value">€ <?= number_format((float)$kpi['lordo'], 2, ',', '.') ?></div>
            <div class="kpi-sub">- € <?= number_format((float)$kpi['sconti'], 2, ',', '.') ?> sconti</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Fatturato netto (post resi)</div>
            <div class="kpi-value" style="color: var(--pos);">€ <?= number_format((float)$kpi['netto_dopo_resi'], 2, ',', '.') ?></div>
            <div class="kpi-sub">resi: € <?= number_format((float)$kpi['resi_importo'], 2, ',', '.') ?> (<?= (int)$kpi['resi_n'] ?> ordini)</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Tasso consegna COD</div>
            <div class="kpi-value" style="color: var(--cyan);"><?= number_format(((float)$kpi['cod_tasso']) * 100, 1, ',', '.') ?>%</div>
            <div class="kpi-sub"><?= (int)$kpi['cod_consegnati'] ?> consegnati · <?= (int)$kpi['cod_persi'] ?> persi su <?= (int)$kpi['n_cod'] ?></div>
        </div>
    </section>

    <section class="kpi-grid" style="margin-top: 16px;">
        <div class="kpi">
            <div class="kpi-label">Fatturato COD</div>
            <div class="kpi-value">€ <?= number_format((float)$kpi['fatturato_cod'], 2, ',', '.') ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Fatturato Prepaid</div>
            <div class="kpi-value">€ <?= number_format((float)$kpi['fatturato_prepaid'], 2, ',', '.') ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Rientrati in magazzino</div>
            <div class="kpi-value" style="color: var(--warn);"><?= (int)$kpi['n_rientrati'] ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Cancellati</div>
            <div class="kpi-value" style="color: var(--neg);"><?= (int)$kpi['n_cancellati'] ?></div>
        </div>
    </section>

    <section class="panel-section">
        <h2>Andamento giornaliero</h2>
        <div class="bar-chart">
            <?php foreach ($trend as $r): ?>
                <?php
                $pctFatt = $maxFatturatoTrend > 0 ? ((float)$r['fatturato'] / $maxFatturatoTrend) * 100 : 0;
                $pctOrd  = $maxOrdiniTrend > 0 ? ((int)$r['n'] / $maxOrdiniTrend) * 100 : 0;
                ?>
                <div class="bar-row">
                    <div class="bar-label"><?= tr_h(date('d/m', strtotime($r['giorno']))) ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?= round($pctFatt, 2) ?>%;"></div>
                    </div>
                    <div class="bar-value">
                        € <?= number_format((float)$r['fatturato'], 0, ',', '.') ?>
                        <span class="muted"><?= (int)$r['n'] ?> ord.</span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($trend)): ?>
                <p class="muted">Nessun dato nel periodo selezionato.</p>
            <?php endif; ?>
        </div>
    </section>

    <div class="panel-grid-2">
        <section class="panel-section">
            <h2>Top città</h2>
            <table class="panel-table">
                <thead><tr><th>Città</th><th class="num">Ordini</th><th class="num">Fatturato</th></tr></thead>
                <tbody>
                <?php foreach ($topCit as $c): ?>
                    <tr>
                        <td><?= tr_h($c['citta']) ?></td>
                        <td class="num"><?= (int)$c['n'] ?></td>
                        <td class="num">€ <?= number_format((float)$c['fatturato'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($topCit)): ?>
                    <tr><td colspan="3" class="muted">Nessun dato.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel-section">
            <h2>Andamento mensile</h2>
            <table class="panel-table">
                <thead><tr><th>Mese</th><th class="num">Ordini</th><th class="num">Fatturato</th><th class="num">Netto</th><th class="num">COD</th><th class="num">Cancellati</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($monthly) as $m): ?>
                    <tr>
                        <td><?= tr_h($m['mese']) ?></td>
                        <td class="num"><?= (int)$m['n'] ?></td>
                        <td class="num">€ <?= number_format((float)$m['fatturato'], 2, ',', '.') ?></td>
                        <td class="num">€ <?= number_format((float)$m['netto'], 2, ',', '.') ?></td>
                        <td class="num"><?= (int)$m['n_cod'] ?></td>
                        <td class="num"><?= (int)$m['n_cancellati'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($monthly)): ?>
                    <tr><td colspan="6" class="muted">Nessun dato.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

</main>
</body>
</html>
