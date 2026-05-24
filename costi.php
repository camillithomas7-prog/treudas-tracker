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

$msg = '';

// POST: salva costi logistici
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'logistics') {
        $shipping = (float)str_replace(',', '.', (string)($_POST['shipping'] ?? 0));
        $return   = (float)str_replace(',', '.', (string)($_POST['return']   ?? 0));
        $stock    = (float)str_replace(',', '.', (string)($_POST['stock']    ?? 0));
        sh_cogs_unit_costs_set($shipping, $return, $stock);
        $msg = '✔ Costi logistici aggiornati.';
    } elseif ($action === 'product_cost') {
        $pid  = (int)($_POST['product_id'] ?? 0);
        $cost = (float)str_replace(',', '.', (string)($_POST['cost'] ?? 0));
        if ($pid > 0) {
            sh_product_cost_set($pid, $cost);
            // AJAX support: rispondi JSON se richiesto
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'product_id' => $pid, 'cost' => $cost]);
                exit;
            }
            $msg = sprintf('✔ Costo prodotto #%d aggiornato a € %s.', $pid, number_format($cost, 2, ',', '.'));
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
$products = sh_products_catalog($search);

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
        <h1>Costi & COGS</h1>
        <p class="muted">Costo unitario per evasione, rientro, giacenza e catalogo prodotti</p>
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

    <!-- KPI logistici editabili -->
    <form method="post" class="cogs-kpi-row">
        <input type="hidden" name="action" value="logistics">

        <div class="cogs-kpi cogs-kpi-cyan">
            <div class="cogs-kpi-label">● Spedizione</div>
            <input type="text" name="shipping" value="<?= number_format($unitCosts['shipping'], 2, '.', '') ?>" class="cogs-kpi-input">
            <div class="cogs-kpi-sub">€/ordine — Pagata su ogni ordine spedito</div>
            <div class="cogs-kpi-meta">applicato a <?= (int)$cogsKpi['n_spediti'] ?> ordini → € <?= number_format($cogsKpi['cost_shipping'], 2, ',', '.') ?></div>
        </div>

        <div class="cogs-kpi cogs-kpi-orange">
            <div class="cogs-kpi-label">● Rientro</div>
            <input type="text" name="return" value="<?= number_format($unitCosts['return'], 2, '.', '') ?>" class="cogs-kpi-input">
            <div class="cogs-kpi-sub">€/ordine rientrato — Solo per ordini rientrati in magazzino</div>
            <div class="cogs-kpi-meta">applicato a <?= (int)$cogsKpi['n_rientrati'] ?> ordini → € <?= number_format($cogsKpi['cost_return'], 2, ',', '.') ?></div>
        </div>

        <div class="cogs-kpi cogs-kpi-yellow">
            <div class="cogs-kpi-label">● Giacenza</div>
            <input type="text" name="stock" value="<?= number_format($unitCosts['stock'], 2, '.', '') ?>" class="cogs-kpi-input">
            <div class="cogs-kpi-sub">€/ordine in giacenza — Solo per ordini in giacenza</div>
            <div class="cogs-kpi-meta">applicato a <?= (int)$cogsKpi['n_giacenza'] ?> ordini → € <?= number_format($cogsKpi['cost_stock'], 2, ',', '.') ?></div>
        </div>

        <div style="grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap;">
            <div class="muted" style="font-size: 13px;">
                Totale costi logistici nel periodo: <strong style="color: var(--accent-2);">€ <?= number_format($cogsKpi['cost_logistics'], 2, ',', '.') ?></strong>
                · COGS prodotto: <strong style="color: var(--accent-2);">€ <?= number_format($cogsKpi['cogs_prodotto'], 2, ',', '.') ?></strong>
            </div>
            <button type="submit">Salva costi logistici</button>
        </div>
    </form>

    <!-- Nota spese variabili -->
    <div class="info-box">
        <strong style="color: var(--pink);">● Spese variabili (Ads, Team, Influencer, Varie)</strong>
        <p class="muted" style="margin: 8px 0 0 0; font-size: 13px;">
            Le spese che variano mese per mese (pubblicità, team, influencer, spese varie)
            si configurano nella sezione <a href="/bilancio.php" style="color: var(--accent-2);">Bilancio</a>
            mese per mese e vengono usate per il calcolo del margine annuale.
        </p>
    </div>

    <!-- Catalogo prodotti -->
    <section class="panel-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
            <div>
                <h2 style="margin: 0;">● Catalogo prodotti <span class="muted" style="font-weight: 400; font-size: 14px;">— <?= count($products) ?> configurati</span></h2>
                <?php if ($lastProductsSync): ?>
                    <small class="muted">Ultimo sync prodotti: <?= tr_h(date('d/m H:i', $lastProductsSync)) ?></small>
                <?php endif; ?>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <form method="get" style="margin: 0;">
                    <input type="hidden" name="range" value="<?= tr_h($preset) ?>">
                    <input type="text" name="q" value="<?= tr_h($search) ?>" placeholder="Cerca prodotto…" style="background: rgba(20,28,56,0.6); border: 1px solid var(--glass-border); color: var(--text); padding: 8px 14px; border-radius: 8px; font-size: 13px;">
                </form>
                <form method="post" style="margin: 0;">
                    <input type="hidden" name="action" value="sync_products">
                    <button type="submit" class="btn-sm">↻ Sync prodotti</button>
                </form>
            </div>
        </div>

        <div class="panel-table-wrap">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>Prodotto</th>
                        <th class="num">Volume</th>
                        <th class="num">Ordini</th>
                        <th class="num">Costo (€)</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="4" class="muted" style="text-align: center; padding: 30px;">Nessun prodotto. Clicca "↻ Sync prodotti".</td></tr>
                <?php else: foreach ($products as $p): ?>
                    <tr>
                        <td>
                            <?= tr_h($p['title']) ?>
                            <?php if ($p['status'] === 'draft'): ?><span class="badge" style="margin-left: 8px;">Draft</span><?php endif; ?>
                            <?php if ($p['status'] === 'archived'): ?><span class="badge" style="margin-left: 8px;">Archived</span><?php endif; ?>
                        </td>
                        <td class="num" style="color: var(--cyan);"><?= number_format((int)$p['volume'], 0, ',', '.') ?></td>
                        <td class="num"><?= number_format((int)$p['ordini'], 0, ',', '.') ?></td>
                        <td class="num">
                            <form method="post" class="inline-cost-form" data-pid="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="action" value="product_cost">
                                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                                <input type="text" name="cost" value="<?= number_format((float)$p['cost_unit'], 2, '.', '') ?>" class="cost-input-inline">
                            </form>
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
            const fd = new FormData(form);
            const r = await fetch('/costi.php', {
                method: 'POST',
                body: fd,
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
