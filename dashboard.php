<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/shopify_api.php';
require_once __DIR__ . '/inc/shopify_sync.php';
require_once __DIR__ . '/inc/shopify_stats.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
tracker_install_schema();
date_default_timezone_set(tracker_config()['timezone']);

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

$stores = tr_stores_all();

// KPI per ogni store, riusando il motore stats via override del contesto store.
$rows = [];
foreach ($stores as $s) {
    $sid = (int)$s['id'];
    $m = tr_store_with($sid, function () use ($from, $to) {
        // Auto-sync throttled: tira giù gli ordini dello store (no-op se sync recente)
        if (sh_get_token()) { try { sh_sync_orders_throttled(300); } catch (Throwable $e) {} }
        $unit = sh_cogs_unit_costs();
        $pnl  = sh_pnl_period($from, $to, $unit);
        $mkt  = sh_marketing_costs_period($from, $to);
        $rev  = (float)$pnl['revenue'];
        $ads  = (float)$mkt['ads'];
        $net  = (float)$pnl['margin'] - (float)$mkt['tot_op'];
        return [
            'orders'  => (int)$pnl['n'],
            'revenue' => $rev,
            'cogs'    => (float)$pnl['cost_total'],
            'margin'  => (float)$pnl['margin'],
            'ads'     => $ads,
            'op'      => (float)$mkt['tot_op'],
            'net'     => $net,
            'roas'    => $ads > 0 ? $rev / $ads : 0,
            'aov'     => (int)$pnl['n'] > 0 ? $rev / (int)$pnl['n'] : 0,
        ];
    });
    $m['store'] = $s;
    $m['has_token'] = !empty($s['admin_token']);

    // Valuta REALE rilevata dagli ordini Shopify (fallback a quella configurata).
    // Auto-corregge la valuta dello store così il raggruppamento è sempre giusto.
    $rcStmt = tracker_db()->prepare("SELECT currency FROM shopify_orders WHERE store_id = ? AND currency IS NOT NULL AND currency != '' GROUP BY currency ORDER BY COUNT(*) DESC LIMIT 1");
    $rcStmt->execute([$sid]);
    $realCur = $rcStmt->fetchColumn();
    if ($realCur) {
        $m['store']['currency'] = $realCur;
        if ($realCur !== ($s['currency'] ?? '')) {
            tracker_db()->prepare("UPDATE stores SET currency = ? WHERE id = ?")->execute([$realCur, $sid]);
        }
    }

    $rows[] = $m;
}

// Raggruppa per valuta (niente totale combinato cross-valuta).
$byCur = [];
foreach ($rows as $r) {
    $cur = $r['store']['currency'] ?: 'EUR';
    if (!isset($byCur[$cur])) $byCur[$cur] = ['rows' => [], 'orders' => 0, 'revenue' => 0, 'ads' => 0, 'net' => 0, 'cogs' => 0];
    $byCur[$cur]['rows'][]     = $r;
    $byCur[$cur]['orders']    += $r['orders'];
    $byCur[$cur]['revenue']   += $r['revenue'];
    $byCur[$cur]['ads']       += $r['ads'];
    $byCur[$cur]['net']       += $r['net'];
    $byCur[$cur]['cogs']      += $r['cogs'];
}

$rangeLabels = ['today'=>'Oggi','yesterday'=>'Ieri','7d'=>'7gg','30d'=>'30gg','90d'=>'90gg','all'=>'Tutto'];
$panel_active = 'dashboard';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestionale — Dashboard globale</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/panel.css?v=<?= @filemtime(__DIR__ . '/assets/panel.css') ?>">
<style>
.dash-wrap{max-width:1320px;margin:0 auto;padding:0 22px 60px;}
.dash-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:26px 0 10px;}
.dash-head h1{font-size:24px;margin:0;}
.range-pills{display:flex;gap:8px;flex-wrap:wrap;}
.range-pills a{padding:8px 16px;border-radius:999px;font-size:13px;font-weight:700;text-decoration:none;color:var(--text-dim,#9aa6bf);background:var(--bg-card-2,#161b2e);border:1px solid var(--border,#26304a);}
.range-pills a.on{color:#10131c;background:linear-gradient(135deg,#ffb347,#f59e0b);border-color:transparent;}
.cur-block{margin-top:26px;}
.cur-title{display:flex;align-items:baseline;gap:10px;margin-bottom:14px;}
.cur-title b{font-size:15px;letter-spacing:.04em;color:var(--text-dim,#9aa6bf);text-transform:uppercase;}
.cur-tot{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px;}
.tot-card{background:linear-gradient(160deg,rgba(255,179,71,.10),rgba(0,0,0,0));border:1px solid var(--border,#26304a);border-radius:16px;padding:16px 18px;}
.tot-card .lbl{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--text-dim,#9aa6bf);}
.tot-card .val{font-size:26px;font-weight:800;margin-top:6px;}
.store-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px;}
.store-card{position:relative;background:var(--bg-card,#10131f);border:1px solid var(--border,#26304a);border-radius:18px;padding:18px 18px 16px;text-decoration:none;color:inherit;transition:transform .12s,border-color .12s;overflow:hidden;}
.store-card:hover{transform:translateY(-2px);border-color:var(--accent,#f59e0b);}
.store-card .bar{position:absolute;left:0;top:0;bottom:0;width:4px;}
.store-card h3{margin:0 0 2px;font-size:17px;display:flex;align-items:center;gap:8px;}
.store-card .dot{width:9px;height:9px;border-radius:50%;}
.store-card .dom{font-size:11px;color:var(--text-dim,#9aa6bf);margin-bottom:14px;}
.metrics{display:grid;grid-template-columns:1fr 1fr;gap:12px 14px;}
.metric .k{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim,#9aa6bf);}
.metric .v{font-size:19px;font-weight:800;margin-top:2px;}
.v.green{color:#34d399;} .v.red{color:#f87171;} .v.blue{color:#60a5fa;} .v.amber{color:#fbbf24;}
.badge-warn{display:inline-block;font-size:10px;font-weight:700;color:#fbbf24;background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.3);border-radius:6px;padding:2px 7px;margin-left:6px;}
.empty{padding:50px 20px;text-align:center;color:var(--text-dim,#9aa6bf);}
.addstore{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:12px;background:var(--bg-card-2,#161b2e);border:1px dashed var(--border,#26304a);color:var(--text,#e7ecf5);text-decoration:none;font-weight:700;margin-top:18px;}
@media(max-width:720px){.cur-tot{grid-template-columns:repeat(2,1fr);}}
</style>
</head>
<body>
<?php include __DIR__ . '/inc/panel_header.php'; ?>

<div class="dash-wrap">
    <div class="dash-head">
        <h1>📊 Tutti i miei store</h1>
        <div class="range-pills">
            <?php foreach ($rangeLabels as $k => $lbl): ?>
                <a href="?range=<?= $k ?>" class="<?= $preset === $k ? 'on' : '' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($stores)): ?>
        <div class="empty">Nessuno store configurato.<br><a class="addstore" href="/stores.php">+ Aggiungi il primo store</a></div>
    <?php else: ?>
        <?php foreach ($byCur as $cur => $g):
            $roasTot = $g['ads'] > 0 ? $g['revenue'] / $g['ads'] : 0; ?>
        <div class="cur-block">
            <div class="cur-title"><b>Valuta <?= tr_h($cur) ?></b><span style="color:var(--text-dim,#9aa6bf);font-size:13px;"><?= count($g['rows']) ?> store</span></div>
            <div class="cur-tot">
                <div class="tot-card"><div class="lbl">Ricavo totale</div><div class="val" style="color:#34d399;"><?= tr_money($g['revenue'], $cur) ?></div></div>
                <div class="tot-card"><div class="lbl">Ordini</div><div class="val"><?= number_format($g['orders'], 0, ',', '.') ?></div></div>
                <div class="tot-card"><div class="lbl">Spesa Ads</div><div class="val" style="color:#fbbf24;"><?= tr_money($g['ads'], $cur) ?></div></div>
                <div class="tot-card"><div class="lbl">Profitto netto</div><div class="val" style="color:<?= $g['net'] >= 0 ? '#34d399' : '#f87171' ?>;"><?= tr_money($g['net'], $cur) ?></div></div>
            </div>
            <div class="store-grid">
                <?php foreach ($g['rows'] as $r): $s = $r['store']; $col = $s['color'] ?: '#f59e0b'; ?>
                <a class="store-card" href="/statistiche.php?store=<?= tr_h($s['slug']) ?>">
                    <span class="bar" style="background:<?= tr_h($col) ?>;"></span>
                    <h3><span class="dot" style="background:<?= tr_h($col) ?>;"></span><?= tr_h($s['name']) ?>
                        <?php if (!$r['has_token']): ?><span class="badge-warn">no token</span><?php endif; ?>
                    </h3>
                    <div class="dom"><?= tr_h($s['myshopify_domain'] ?: ($s['public_domain'] ?: '—')) ?></div>
                    <div class="metrics">
                        <div class="metric"><div class="k">Ricavo</div><div class="v green"><?= tr_money($r['revenue'], $cur) ?></div></div>
                        <div class="metric"><div class="k">Ordini</div><div class="v"><?= number_format($r['orders'], 0, ',', '.') ?></div></div>
                        <div class="metric"><div class="k">Profitto netto</div><div class="v <?= $r['net'] >= 0 ? 'green' : 'red' ?>"><?= tr_money($r['net'], $cur) ?></div></div>
                        <div class="metric"><div class="k">ROAS</div><div class="v <?= $r['roas'] >= 1.5 ? 'green' : ($r['roas'] > 0 ? 'amber' : '') ?>"><?= $r['ads'] > 0 ? number_format($r['roas'], 2, ',', '.') . 'x' : '—' ?></div></div>
                        <div class="metric"><div class="k">Spesa Ads</div><div class="v amber"><?= tr_money($r['ads'], $cur) ?></div></div>
                        <div class="metric"><div class="k">AOV</div><div class="v blue"><?= tr_money($r['aov'], $cur) ?></div></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <a class="addstore" href="/stores.php">+ Aggiungi / gestisci store</a>
    <?php endif; ?>
</div>
</body>
</html>
