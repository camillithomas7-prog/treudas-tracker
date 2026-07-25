<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/guard.php';
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

    // Valuta REALE rilevata dagli ordini (auto-corregge il campo store).
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
    if (!isset($byCur[$cur])) $byCur[$cur] = ['rows' => [], 'orders' => 0, 'revenue' => 0, 'ads' => 0, 'net' => 0];
    $byCur[$cur]['rows'][]   = $r;
    $byCur[$cur]['orders']  += $r['orders'];
    $byCur[$cur]['revenue'] += $r['revenue'];
    $byCur[$cur]['ads']     += $r['ads'];
    $byCur[$cur]['net']     += $r['net'];
}
// ordina: valute per ricavo desc, e store per ricavo desc dentro la valuta
uasort($byCur, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
foreach ($byCur as &$g) { usort($g['rows'], fn($a, $b) => $b['revenue'] <=> $a['revenue']); } unset($g);

$rangeLabels = ['today'=>'Oggi','yesterday'=>'Ieri','7d'=>'7gg','30d'=>'30gg','90d'=>'90gg','all'=>'Tutto'];
$nConnessi = count(array_filter($rows, fn($r) => $r['has_token']));
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
:root{--pos:#34d399;--neg:#f87171;--amber:#fbbf24;--blue:#60a5fa;}
.dwrap{max-width:1340px;margin:0 auto;padding:0 24px 70px;}
.dhead{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;flex-wrap:wrap;margin:30px 0 22px;}
.dhead h1{font-size:25px;margin:0 0 3px;letter-spacing:-.01em;}
.dhead .sub{color:var(--text-dim,#9aa6bf);font-size:13px;}
.pills{display:flex;gap:7px;flex-wrap:wrap;}
.pills a{padding:8px 15px;border-radius:999px;font-size:13px;font-weight:700;text-decoration:none;color:var(--text-dim,#9aa6bf);background:var(--bg-card-2,#161b2e);border:1px solid var(--border,#26304a);transition:.12s;}
.pills a:hover{color:#fff;border-color:#3a4760;}
.pills a.on{color:#10131c;background:linear-gradient(135deg,#ffb347,#f59e0b);border-color:transparent;box-shadow:0 4px 16px rgba(245,158,11,.3);}
/* sezione valuta */
.curblk{margin-top:30px;}
.curbar{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;
  background:linear-gradient(100deg,rgba(255,255,255,.04),rgba(255,255,255,0));border:1px solid var(--border,#26304a);
  border-radius:14px;padding:14px 20px;margin-bottom:16px;}
.curbar .cl{display:flex;align-items:baseline;gap:10px;}
.curbar .cbadge{font-size:15px;font-weight:800;letter-spacing:.06em;color:#fff;background:rgba(255,255,255,.06);border:1px solid var(--border,#26304a);padding:4px 11px;border-radius:8px;}
.curbar .cmeta{color:var(--text-dim,#9aa6bf);font-size:13px;}
.curbar .ctot{display:flex;gap:26px;flex-wrap:wrap;}
.curbar .ct{display:flex;flex-direction:column;}
.curbar .ctl{font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--text-dim,#9aa6bf);}
.curbar .ctv{font-size:19px;font-weight:800;margin-top:2px;}
/* griglia store */
.sgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;}
.scard{position:relative;display:block;text-decoration:none;color:inherit;overflow:hidden;
  background:radial-gradient(120% 120% at 0% 0%,color-mix(in srgb,var(--c) 9%,transparent),transparent 55%),var(--bg-card,#10131f);
  border:1px solid var(--border,#26304a);border-radius:18px;padding:18px 20px 16px;transition:transform .14s,border-color .14s,box-shadow .14s;}
.scard:hover{transform:translateY(-3px);border-color:color-mix(in srgb,var(--c) 60%,var(--border,#26304a));box-shadow:0 12px 30px rgba(0,0,0,.35);}
.scard .bar{position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--c);}
.sc-top{display:flex;align-items:center;gap:10px;margin-bottom:14px;}
.sc-dot{width:10px;height:10px;border-radius:50%;background:var(--c);box-shadow:0 0 10px var(--c);flex:0 0 auto;}
.sc-name{font-size:16px;font-weight:800;}
.sc-dom{font-size:11px;color:var(--text-dim,#9aa6bf);}
.sc-warn{margin-left:auto;font-size:10px;font-weight:700;color:var(--amber);background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.3);border-radius:6px;padding:2px 7px;}
.sc-rev{display:flex;align-items:baseline;gap:9px;margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--border,#26304a);}
.sc-rev .rl{font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--text-dim,#9aa6bf);}
.sc-rev .rv{font-size:27px;font-weight:800;color:var(--pos);letter-spacing:-.01em;margin-left:auto;}
.sc-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
.sc-grid .l{display:block;font-size:9.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim,#9aa6bf);margin-bottom:3px;}
.sc-grid .v{font-size:15px;font-weight:800;}
.v.green{color:var(--pos);} .v.red{color:var(--neg);} .v.amber{color:var(--amber);} .v.blue{color:var(--blue);} .v.dim{color:var(--text-dim,#9aa6bf);}
.addbtn{display:inline-flex;align-items:center;gap:8px;margin-top:26px;padding:12px 20px;border-radius:12px;
  background:var(--bg-card-2,#161b2e);border:1px dashed var(--border,#26304a);color:var(--text,#e7ecf5);text-decoration:none;font-weight:700;transition:.12s;}
.addbtn:hover{border-color:var(--accent,#f59e0b);color:#fff;}
.empty{padding:60px 20px;text-align:center;color:var(--text-dim,#9aa6bf);}
@media(max-width:560px){.sc-grid{grid-template-columns:repeat(2,1fr);gap:12px;}.curbar .ctot{gap:18px;}}
</style>
</head>
<body>
<?php include __DIR__ . '/inc/panel_header.php'; ?>

<div class="dwrap">
    <div class="dhead">
        <div>
            <h1>📊 Tutti i miei store</h1>
            <div class="sub"><?= count($rows) ?> store · <?= $nConnessi ?> connessi · aggiornato <?= date('H:i') ?></div>
        </div>
        <div class="pills">
            <?php foreach ($rangeLabels as $k => $lbl): ?>
                <a href="?range=<?= $k ?>" class="<?= $preset === $k ? 'on' : '' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($stores)): ?>
        <div class="empty">Nessuno store configurato.<br><a class="addbtn" href="/stores.php">+ Aggiungi il primo store</a></div>
    <?php else: ?>
        <?php foreach ($byCur as $cur => $g): ?>
        <div class="curblk">
            <div class="curbar">
                <div class="cl"><span class="cbadge"><?= tr_h($cur) ?></span><span class="cmeta"><?= count($g['rows']) ?> store</span></div>
                <div class="ctot">
                    <div class="ct"><span class="ctl">Ricavo totale</span><span class="ctv" style="color:var(--pos)"><?= tr_money($g['revenue'], $cur) ?></span></div>
                    <div class="ct"><span class="ctl">Ordini</span><span class="ctv"><?= number_format($g['orders'], 0, ',', '.') ?></span></div>
                    <div class="ct"><span class="ctl">Spesa Ads</span><span class="ctv" style="color:var(--amber)"><?= tr_money($g['ads'], $cur) ?></span></div>
                    <div class="ct"><span class="ctl">Profitto netto</span><span class="ctv" style="color:<?= $g['net'] >= 0 ? 'var(--pos)' : 'var(--neg)' ?>"><?= tr_money($g['net'], $cur) ?></span></div>
                </div>
            </div>
            <div class="sgrid">
                <?php foreach ($g['rows'] as $r): $s = $r['store']; $col = $s['color'] ?: '#f59e0b'; ?>
                <a class="scard" style="--c:<?= tr_h($col) ?>" href="/statistiche.php?store=<?= tr_h($s['slug']) ?>">
                    <span class="bar"></span>
                    <div class="sc-top">
                        <span class="sc-dot"></span>
                        <div>
                            <div class="sc-name"><?= tr_h($s['name']) ?></div>
                            <div class="sc-dom"><?= tr_h($s['myshopify_domain'] ?: ($s['public_domain'] ?: '—')) ?></div>
                        </div>
                        <?php if (!$r['has_token']): ?><span class="sc-warn">no token</span><?php endif; ?>
                    </div>
                    <div class="sc-rev"><span class="rl">Ricavo</span><span class="rv"><?= tr_money($r['revenue'], $cur) ?></span></div>
                    <div class="sc-grid">
                        <div><span class="l">Ordini</span><span class="v"><?= number_format($r['orders'], 0, ',', '.') ?></span></div>
                        <div><span class="l">Profitto</span><span class="v <?= $r['net'] >= 0 ? 'green' : 'red' ?>"><?= tr_money($r['net'], $cur) ?></span></div>
                        <div><span class="l">ROAS</span><span class="v <?= $r['roas'] >= 1.5 ? 'green' : ($r['roas'] > 0 ? 'amber' : 'dim') ?>"><?= $r['ads'] > 0 ? number_format($r['roas'], 2, ',', '.') . 'x' : '—' ?></span></div>
                        <div><span class="l">AOV</span><span class="v blue"><?= tr_money($r['aov'], $cur) ?></span></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <a class="addbtn" href="/stores.php">+ Aggiungi / gestisci store</a>
    <?php endif; ?>
</div>
</body>
</html>
