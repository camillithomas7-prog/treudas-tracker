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
    header('Location: /stores.php');
    exit;
}

$msg = '';

// POST: salva costi logistici
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'logistics') {
        $shipping = (float)str_replace(',', '.', (string)($_POST['shipping'] ?? 0));
        $return   = (float)str_replace(',', '.', (string)($_POST['return']   ?? 0));
        sh_cogs_unit_costs_set($shipping, $return, 0); // stock non usato in drop shipping
        $msg = '✔ Costi logistici aggiornati.';
    } elseif ($action === 'variant_cost') {
        $vid  = (int)($_POST['variant_id'] ?? 0);
        $cost = (float)str_replace(',', '.', (string)($_POST['cost'] ?? 0));
        if ($vid > 0) {
            sh_variant_cost_set($vid, $cost);
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'variant_id' => $vid, 'cost' => $cost]);
                exit;
            }
            $msg = sprintf('✔ Costo bundle #%d aggiornato a %s %s.', $vid, tr_cur_sym(), number_format($cost, 2, ',', '.'));
        }
    } elseif ($action === 'sync_products') {
        $r = sh_sync_products();
        $msg = $r['ok'] ? sprintf('✔ Sincronizzati %d prodotti.', $r['synced']) : '⚠ Errore sync: ' . $r['error'];
    }
}

// Sync prodotti (throttled 10 min) per avere catalogo aggiornato
sh_sync_products_throttled(600);
sh_sync_orders_throttled(60);

$search = trim((string)($_GET['q'] ?? ''));
$variants = sh_variants_catalog($search);

$unitCosts = sh_cogs_unit_costs();

// Periodo per le KPI logistiche (default 30gg)
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
$cogsKpi = sh_cogs_kpi($from, $to, $unitCosts);
$pnl = sh_pnl_period($from, $to, $unitCosts);

$lastProductsSync = (int)(sh_setting_get('shopify_products_last_sync') ?? 0);

$panel_active = 'costi';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestionale TREUDAS — Costi & COGS</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/panel.css?v=<?= @filemtime(__DIR__ . '/assets/panel.css') ?>">
</head>
<body>
<?php include __DIR__ . '/inc/panel_header.php'; ?>

<main class="container">

    <div class="page-title">
        <h1>Costi & COGS — Drop Shipping</h1>
        <p class="muted">Costo spedizione, perdita su rientro e costo bundle per ogni offerta del catalogo</p>
    </div>

    <?php if ($msg): ?><div class="alert alert-ok"><?= tr_h($msg) ?></div><?php endif; ?>

    <!-- Filtri periodo -->
    <form method="get" class="filters" style="margin-top: 18px;">
        <div class="filter-pills">
            <?php foreach (['today'=>'Oggi','yesterday'=>'Ieri','7d'=>'7gg','30d'=>'30gg','90d'=>'90gg','all'=>'Tutto'] as $k=>$v): ?>
                <a href="?range=<?= $k ?>&q=<?= urlencode($search) ?>" class="pill <?= $preset===$k ? 'active' : '' ?>"><?= $v ?></a>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- P&L riepilogo periodo -->
    <section class="pnl-summary">
        <div class="pnl-cell">
            <div class="pnl-label">Ricavo</div>
            <div class="pnl-value" style="color: var(--pos);"><?= tr_cur_sym() ?> <?= number_format($pnl['revenue'], 2, ',', '.') ?></div>
        </div>
        <div class="pnl-cell">
            <div class="pnl-label">Costo bundle (fornitore)</div>
            <div class="pnl-value"><?= tr_cur_sym() ?> <?= number_format($pnl['cogs_bundle'], 2, ',', '.') ?></div>
        </div>
        <div class="pnl-cell">
            <div class="pnl-label">Costo spedizione</div>
            <div class="pnl-value"><?= tr_cur_sym() ?> <?= number_format($pnl['cogs_shipping'], 2, ',', '.') ?></div>
        </div>
        <div class="pnl-cell">
            <div class="pnl-label">Perdita rientri</div>
            <div class="pnl-value" style="color: var(--warn);"><?= tr_cur_sym() ?> <?= number_format($pnl['cogs_loss'], 2, ',', '.') ?></div>
        </div>
        <div class="pnl-cell pnl-margin">
            <div class="pnl-label">Margine lordo</div>
            <div class="pnl-value" style="color: <?= $pnl['margin'] >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;"><?= tr_cur_sym() ?> <?= number_format($pnl['margin'], 2, ',', '.') ?></div>
            <div class="pnl-sub"><?= (int)$pnl['n'] ?> ordini · <?= $pnl['revenue'] > 0 ? number_format(($pnl['margin'] / $pnl['revenue']) * 100, 1, ',', '.') : '0' ?>%</div>
        </div>
    </section>

    <!-- KPI logistici editabili (2 in drop shipping: spedizione + perdita rientro) -->
    <form method="post" class="cogs-kpi-row cogs-kpi-row-2">
        <input type="hidden" name="action" value="logistics">

        <div class="cogs-kpi cogs-kpi-cyan">
            <div class="cogs-kpi-label">● Spedizione</div>
            <input type="text" name="shipping" value="<?= number_format($unitCosts['shipping'], 2, '.', '') ?>" class="cogs-kpi-input">
            <div class="cogs-kpi-sub"><?= tr_cur_sym() ?>/ordine — Pagata al fornitore su ogni ordine spedito al cliente</div>
            <div class="cogs-kpi-meta">applicato a <?= (int)$cogsKpi['n_spediti'] ?> ordini consegnati → <?= tr_cur_sym() ?> <?= number_format($cogsKpi['cost_shipping'], 2, ',', '.') ?></div>
        </div>

        <div class="cogs-kpi cogs-kpi-orange">
            <div class="cogs-kpi-label">● Perdita rientro</div>
            <input type="text" name="return" value="<?= number_format($unitCosts['return'], 2, '.', '') ?>" class="cogs-kpi-input">
            <div class="cogs-kpi-sub"><?= tr_cur_sym() ?>/ordine rientrato — Costo perso quando il cliente non ritira (es. quota di spedizione non rimborsata dal fornitore)</div>
            <div class="cogs-kpi-meta">applicato a <?= (int)$cogsKpi['n_rientrati'] ?> ordini rientrati → <?= tr_cur_sym() ?> <?= number_format($cogsKpi['cost_return'], 2, ',', '.') ?></div>
        </div>

        <div style="grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 14px; flex-wrap: wrap;">
            <button type="submit">Salva costi logistici</button>
        </div>
    </form>

    <!-- Nota spese variabili -->
    <div class="info-box">
        <strong style="color: var(--pink);">● Come funziona il calcolo (Drop Shipping)</strong>
        <p class="muted" style="margin: 8px 0 0 0; font-size: 13px; line-height: 1.7;">
            Per ogni ordine il sistema calcola:<br>
            <strong style="color: var(--text);">Margine = Ricavo − Costo bundle (somma qty × costo prodotto Shopify) − Costo spedizione − Perdita rientro</strong><br>
            Ordini cancellati hanno ricavo 0 e costi 0. Ordini rientrati hanno ricavo 0, costo bundle 0 (rimborsato dal fornitore), ma spedizione + perdita restano. Le spese variabili mensili (Ads, Team, Influencer, ecc.) si gestiscono nella sezione <a href="/bilancio.php" style="color: var(--accent-2);">Bilancio</a> e non rientrano in questo calcolo per-ordine.
        </p>
    </div>

    <!-- Catalogo bundle / varianti -->
    <section class="panel-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
            <div>
                <h2 style="margin: 0;">● Catalogo bundle / offerte <span class="muted" style="font-weight: 400; font-size: 14px;">— <?= count($variants) ?> configurati</span></h2>
                <?php if ($lastProductsSync): ?>
                    <small class="muted">Ultimo sync: <?= tr_h(date('d/m H:i', $lastProductsSync)) ?> · ogni variante Shopify = un bundle separato</small>
                <?php endif; ?>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <form method="get" style="margin: 0;">
                    <input type="hidden" name="range" value="<?= tr_h($preset) ?>">
                    <input type="text" name="q" value="<?= tr_h($search) ?>" placeholder="Cerca bundle / SKU…" style="background: rgba(20,28,56,0.6); border: 1px solid var(--glass-border); color: var(--text); padding: 8px 14px; border-radius: 8px; font-size: 13px;">
                </form>
                <form method="post" style="margin: 0;">
                    <input type="hidden" name="action" value="sync_products">
                    <button type="submit" class="btn-sm">↻ Sync catalogo</button>
                </form>
            </div>
        </div>

        <div class="panel-table-wrap">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>Prodotto / Bundle</th>
                        <th>SKU</th>
                        <th class="num">Prezzo (<?= tr_cur_sym() ?>)</th>
                        <th class="num">Volume (pz)</th>
                        <th class="num">Ordini</th>
                        <th class="num">Costo nostro (<?= tr_cur_sym() ?>)</th>
                        <th class="num">Margine unitario</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($variants)): ?>
                    <tr><td colspan="7" class="muted" style="text-align: center; padding: 30px;">Nessun bundle. Clicca "↻ Sync catalogo".</td></tr>
                <?php else:
                    $lastProductId = null;
                    foreach ($variants as $v):
                        $marginUnit = (float)$v['price'] - (float)$v['cost_unit'];
                        $rowIsFirstOfProduct = $lastProductId !== (int)$v['product_id'];
                        $lastProductId = (int)$v['product_id'];
                ?>
                    <tr class="<?= $rowIsFirstOfProduct ? 'row-product-start' : '' ?>">
                        <td>
                            <?php if ($rowIsFirstOfProduct): ?>
                                <strong><?= tr_h($v['product_title']) ?></strong>
                                <?php if ($v['status'] === 'draft'): ?><span class="badge" style="margin-left: 8px;">Draft</span><?php endif; ?>
                                <br>
                            <?php endif; ?>
                            <span class="muted" style="font-family: 'JetBrains Mono', monospace; font-size: 12px;">└ <?= tr_h($v['variant_title']) ?: 'Default' ?></span>
                        </td>
                        <td><small class="muted"><?= tr_h($v['sku']) ?: '—' ?></small></td>
                        <td class="num"><?= tr_cur_sym() ?> <?= number_format((float)$v['price'], 2, ',', '.') ?></td>
                        <td class="num" style="color: var(--cyan);"><?= number_format((int)$v['volume'], 0, ',', '.') ?></td>
                        <td class="num"><?= number_format((int)$v['ordini'], 0, ',', '.') ?></td>
                        <td class="num">
                            <form method="post" class="inline-cost-form" data-vid="<?= (int)$v['variant_id'] ?>">
                                <input type="hidden" name="action" value="variant_cost">
                                <input type="hidden" name="variant_id" value="<?= (int)$v['variant_id'] ?>">
                                <input type="text" name="cost" value="<?= number_format((float)$v['cost_unit'], 2, '.', '') ?>" class="cost-input-inline">
                            </form>
                        </td>
                        <td class="num" style="color: <?= $marginUnit >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;">
                            <?= tr_cur_sym() ?> <?= number_format($marginUnit, 2, ',', '.') ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<script>
// Auto-save inline costo prodotto on blur/Enter
document.querySelectorAll('.inline-cost-form').forEach(form => {
    const input = form.querySelector('.cost-input-inline');
    let lastValue = input.value;

    const save = async () => {
        if (input.value === lastValue) return;
        lastValue = input.value;
        input.classList.add('saving');
        try {
            const r = await fetch('/costi.php', {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const j = await r.json();
            if (j.ok) {
                input.classList.remove('saving');
                input.classList.add('saved');
                setTimeout(() => input.classList.remove('saved'), 1200);
            } else {
                input.classList.remove('saving');
                input.classList.add('error');
            }
        } catch (e) {
            input.classList.remove('saving');
            input.classList.add('error');
        }
    };

    input.addEventListener('blur', save);
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
    });
});
</script>

</body>
</html>
