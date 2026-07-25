<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/guard.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/shopify_api.php';
require_once __DIR__ . '/inc/shopify_sync.php';
require_once __DIR__ . '/inc/shopify_stats.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');

tracker_install_schema();
date_default_timezone_set(tracker_config()['timezone']);

if (!sh_get_token()) {
    header('Location: /stores.php');
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

$unitCosts = sh_cogs_unit_costs();
$pnl       = sh_pnl_period($from, $to, $unitCosts);
$mktg      = sh_marketing_costs_period($from, $to);
$kpiBase   = sh_kpi($from, $to);
$dailyP    = sh_daily_profit_trend($from, $to, $unitCosts);
$topBundles = sh_top_variants_period($from, $to, 15);
$topCit    = sh_top_cities($from, $to, 10);
$monthly   = sh_monthly_trend();

// KPI calcolati
$n            = (int)$pnl['n'];
$revenue      = (float)$pnl['revenue'];
$cogsBundle   = (float)$pnl['cogs_bundle'];
$cogsShipping = (float)$pnl['cogs_shipping'];
$cogsLoss     = (float)$pnl['cogs_loss'];
$grossMargin  = (float)$pnl['margin'];           // dopo COGS

$adsSpend     = (float)$mktg['ads'];
$opSpend      = (float)$mktg['tot_op'];          // ads + team + influencer + varie
$netProfit    = $grossMargin - $opSpend;          // profitto netto post-marketing

$aov          = $n > 0 ? ($revenue / $n) : 0;
$cpa          = $n > 0 && $adsSpend > 0 ? ($adsSpend / $n) : 0;
$roas         = $adsSpend > 0 ? ($revenue / $adsSpend) : 0;
$marginPct    = $revenue > 0 ? ($grossMargin / $revenue) * 100 : 0;
$netPct       = $revenue > 0 ? ($netProfit / $revenue) * 100 : 0;
$cogsPct      = $revenue > 0 ? (($cogsBundle + $cogsShipping + $cogsLoss) / $revenue) * 100 : 0;

$maxRev = 0; foreach ($dailyP as $r) $maxRev = max($maxRev, (float)$r['revenue']);

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

    <!-- ROW 1 — KPI principali profitto -->
    <section class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Ordini generati</div>
            <div class="kpi-value"><?= number_format($n, 0, ',', '.') ?></div>
            <div class="kpi-sub">AOV <?= tr_cur_sym() ?> <?= number_format($aov, 2, ',', '.') ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Ricavo (post resi)</div>
            <div class="kpi-value" style="color: var(--pos);"><?= tr_cur_sym() ?> <?= number_format($revenue, 2, ',', '.') ?></div>
            <div class="kpi-sub">lordo: <?= tr_cur_sym() ?> <?= number_format((float)$kpiBase['lordo'], 2, ',', '.') ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">COGS totali</div>
            <div class="kpi-value"><?= tr_cur_sym() ?> <?= number_format($cogsBundle + $cogsShipping + $cogsLoss, 2, ',', '.') ?></div>
            <div class="kpi-sub"><?= number_format($cogsPct, 1, ',', '.') ?>% del ricavo</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Margine lordo</div>
            <div class="kpi-value" style="color: <?= $grossMargin >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;"><?= tr_cur_sym() ?> <?= number_format($grossMargin, 2, ',', '.') ?></div>
            <div class="kpi-sub"><?= number_format($marginPct, 1, ',', '.') ?>% (post-COGS)</div>
        </div>
    </section>

    <!-- ROW 2 — KPI marketing -->
    <section class="kpi-grid" style="margin-top: 16px;">
        <div class="kpi">
            <div class="kpi-label">Spesa Ads</div>
            <div class="kpi-value" style="color: var(--pink);"><?= tr_cur_sym() ?> <?= number_format($adsSpend, 2, ',', '.') ?></div>
            <div class="kpi-sub">pro-rata dal <a href="/bilancio.php" style="color: var(--accent-2);">Bilancio</a></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">CPA <small class="muted">(costo per ordine)</small></div>
            <div class="kpi-value" style="color: <?= ($cpa > 0 && $cpa < $aov) ? 'var(--pos)' : 'var(--warn)' ?>;">
                <?= $cpa > 0 ? tr_cur_sym() . ' ' . number_format($cpa, 2, ',', '.') : '—' ?>
            </div>
            <div class="kpi-sub"><?= $cpa > 0 ? number_format(($cpa / max(1, $aov)) * 100, 1, ',', '.') . '% AOV' : 'imposta Ads in Bilancio' ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">ROAS</div>
            <div class="kpi-value" style="color: <?= $roas >= 2 ? 'var(--pos)' : ($roas >= 1 ? 'var(--warn)' : 'var(--neg)') ?>;">
                <?= $roas > 0 ? number_format($roas, 2, ',', '.') . 'x' : '—' ?>
            </div>
            <div class="kpi-sub"><?= $roas > 0 ? 'Ricavo / Ads' : 'imposta Ads in Bilancio' ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Profitto NETTO</div>
            <div class="kpi-value" style="color: <?= $netProfit >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;"><?= tr_cur_sym() ?> <?= number_format($netProfit, 2, ',', '.') ?></div>
            <div class="kpi-sub"><?= number_format($netPct, 1, ',', '.') ?>% (post-Ads &amp; opex)</div>
        </div>
    </section>

    <!-- ROW 3 — breakdown COGS -->
    <section class="kpi-grid" style="margin-top: 16px;">
        <div class="kpi">
            <div class="kpi-label">COGS bundle (fornitore)</div>
            <div class="kpi-value"><?= tr_cur_sym() ?> <?= number_format($cogsBundle, 2, ',', '.') ?></div>
            <div class="kpi-sub">somma costo varianti vendute</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Costo spedizioni</div>
            <div class="kpi-value"><?= tr_cur_sym() ?> <?= number_format($cogsShipping, 2, ',', '.') ?></div>
            <div class="kpi-sub"><?= tr_cur_sym() ?> <?= number_format($unitCosts['shipping'], 2, ',', '.') ?>/ordine spedito</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Perdite rientri</div>
            <div class="kpi-value" style="color: var(--warn);"><?= tr_cur_sym() ?> <?= number_format($cogsLoss, 2, ',', '.') ?></div>
            <div class="kpi-sub"><?= (int)$kpiBase['n_rientrati'] ?> ordini rientrati</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Cancellati / Rientri</div>
            <div class="kpi-value"><?= (int)$kpiBase['n_cancellati'] ?> · <?= (int)$kpiBase['n_rientrati'] ?></div>
            <div class="kpi-sub">cancellati · rientrati</div>
        </div>
    </section>

    <!-- ROW 4 — Andamento profitto giornaliero -->
    <section class="panel-section">
        <h2>Andamento giornaliero — Ricavo &amp; Margine</h2>
        <div class="bar-chart">
            <?php foreach ($dailyP as $r): ?>
                <?php $pct = $maxRev > 0 ? ((float)$r['revenue'] / $maxRev) * 100 : 0; ?>
                <div class="bar-row">
                    <div class="bar-label"><?= tr_h(date('d/m', strtotime($r['giorno']))) ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?= round($pct, 2) ?>%;"></div>
                    </div>
                    <div class="bar-value">
                        <?= tr_cur_sym() ?> <?= number_format((float)$r['revenue'], 0, ',', '.') ?>
                        · <span style="color: <?= $r['margin'] >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;"><?= tr_cur_sym() ?> <?= number_format((float)$r['margin'], 0, ',', '.') ?></span>
                        <span class="muted"><?= (int)$r['n'] ?> ord.</span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($dailyP)): ?>
                <p class="muted">Nessun dato nel periodo.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ROW 5 — Top bundle venduti -->
    <section class="panel-section">
        <h2>Top bundle venduti</h2>
        <div class="panel-table-wrap">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>Prodotto / Bundle</th>
                        <th class="num">Pezzi</th>
                        <th class="num">Ordini</th>
                        <th class="num">Prezzo</th>
                        <th class="num">Costo</th>
                        <th class="num">Ricavo</th>
                        <th class="num">COGS</th>
                        <th class="num">Margine</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topBundles as $b):
                    $margin = (float)$b['ricavo'] - (float)$b['cogs'];
                    $vt = trim((string)$b['variant_title']);
                    $isDefault = $vt === '' || strtolower($vt) === 'default title';
                ?>
                    <tr>
                        <td>
                            <strong><?= tr_h($b['product_title']) ?></strong>
                            <?php if (!$isDefault): ?><br><small class="muted">└ <?= tr_h($vt) ?></small><?php endif; ?>
                        </td>
                        <td class="num" style="color: var(--cyan);"><?= (int)$b['pezzi'] ?></td>
                        <td class="num"><?= (int)$b['ordini'] ?></td>
                        <td class="num"><?= tr_cur_sym() ?> <?= number_format((float)$b['price'], 2, ',', '.') ?></td>
                        <td class="num"><?= tr_cur_sym() ?> <?= number_format((float)$b['cost_unit'], 2, ',', '.') ?></td>
                        <td class="num"><?= tr_cur_sym() ?> <?= number_format((float)$b['ricavo'], 2, ',', '.') ?></td>
                        <td class="num"><?= tr_cur_sym() ?> <?= number_format((float)$b['cogs'], 2, ',', '.') ?></td>
                        <td class="num" style="color: <?= $margin >= 0 ? 'var(--pos)' : 'var(--neg)' ?>; font-weight: 700;">
                            <?= tr_cur_sym() ?> <?= number_format($margin, 2, ',', '.') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($topBundles)): ?>
                    <tr><td colspan="8" class="muted" style="text-align:center; padding: 30px;">Nessun bundle venduto nel periodo.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="panel-grid-2">
        <section class="panel-section">
            <h2>Top città</h2>
            <table class="panel-table">
                <thead><tr><th>Città</th><th class="num">Ordini</th><th class="num">Ricavo</th></tr></thead>
                <tbody>
                <?php foreach ($topCit as $c): ?>
                    <tr>
                        <td><?= tr_h($c['citta']) ?></td>
                        <td class="num"><?= (int)$c['n'] ?></td>
                        <td class="num"><?= tr_cur_sym() ?> <?= number_format((float)$c['fatturato'], 2, ',', '.') ?></td>
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
                <thead><tr><th>Mese</th><th class="num">Ordini</th><th class="num">Ricavo</th><th class="num">Netto resi</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($monthly) as $m): ?>
                    <tr>
                        <td><?= tr_h($m['mese']) ?></td>
                        <td class="num"><?= (int)$m['n'] ?></td>
                        <td class="num"><?= tr_cur_sym() ?> <?= number_format((float)$m['fatturato'], 2, ',', '.') ?></td>
                        <td class="num"><?= tr_cur_sym() ?> <?= number_format((float)$m['netto'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($monthly)): ?>
                    <tr><td colspan="4" class="muted">Nessun dato.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

</main>
</body>
</html>
