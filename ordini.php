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

// AJAX: salva delivery_status
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'set_delivery') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $status  = trim((string)($_POST['status'] ?? ''));
    if ($orderId > 0) {
        sh_set_delivery_status($orderId, $status);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'order_id' => $orderId, 'status' => $status]);
        exit;
    }
}

// Sync incrementale (throttled 30s) all'apertura, oppure full su richiesta
if (isset($_GET['sync'])) {
    $r = $_GET['sync'] === 'full' ? sh_sync_orders(true) : sh_sync_orders(false);
    header('Location: /ordini.php?synced=' . ($r['ok'] ? $r['synced'] : 'err'));
    exit;
}
sh_sync_orders_throttled(30);
$syncedFlag = $_GET['synced'] ?? null;

$filters = [
    'is_cod'          => $_GET['is_cod']          ?? '',
    'fulfillment'     => $_GET['fulfillment']     ?? '',
    'financial'       => $_GET['financial']       ?? '',
    'returned'        => $_GET['returned']        ?? '',
    'cancelled'       => $_GET['cancelled']       ?? '',
    'delivery_status' => $_GET['delivery_status'] ?? '',
    'search'          => trim((string)($_GET['q'] ?? '')),
];

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$total  = sh_count_orders($filters);
$orders = sh_list_orders($filters, $perPage, ($page - 1) * $perPage);
$totalPages = max(1, (int)ceil($total / $perPage));

// Carica line items per ogni ordine in pagina
$orderItemsMap = [];
foreach ($orders as $o) {
    $orderItemsMap[(int)$o['id']] = sh_get_order_items((int)$o['id']);
}

// P&L per riga (margine drop shipping)
$unitCosts = sh_cogs_unit_costs();
$productCostMap = sh_product_cost_map();
$pnlMap = [];
$pageTotals = ['revenue' => 0, 'cost' => 0, 'margin' => 0];
foreach ($orders as $o) {
    $p = sh_order_pnl($o, $orderItemsMap[(int)$o['id']], $unitCosts, $productCostMap);
    $pnlMap[(int)$o['id']] = $p;
    $pageTotals['revenue'] += $p['revenue'];
    $pageTotals['cost']    += $p['cost_total'];
    $pageTotals['margin']  += $p['margin'];
}

$lastSyncAt = (int)(sh_setting_get('shopify_sync_last_run_at') ?? 0);

$deliveryOptions = [
    ''               => '— Da definire —',
    'in_lavorazione' => 'In lavorazione',
    'in_transito'    => 'In transito',
    'consegnato'     => 'Consegnato',
    'rientrato'      => 'Rientrato',
    'cancellato'     => 'Cancellato',
    'problema'       => 'Problema',
];

$panel_active = 'ordini';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestionale TREUDAS — Ordini</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/panel.css?v=<?= @filemtime(__DIR__ . '/assets/panel.css') ?>">
</head>
<body>
<?php include __DIR__ . '/inc/panel_header.php'; ?>

<main class="container">

    <div class="panel-toolbar">
        <div class="panel-toolbar-left">
            <span class="muted">Totale ordini: <strong><?= number_format($total, 0, ',', '.') ?></strong></span>
            <?php if ($lastSyncAt): ?>
                <span class="muted"> · Ultimo sync: <?= tr_h(date('d/m H:i:s', $lastSyncAt)) ?></span>
            <?php endif; ?>
            <?php if ($syncedFlag !== null && $syncedFlag !== 'err'): ?>
                <span class="badge-ok">✔ Sincronizzati <?= (int)$syncedFlag ?> ordini</span>
            <?php elseif ($syncedFlag === 'err'): ?>
                <span class="badge-err">⚠ Errore sync</span>
            <?php endif; ?>
        </div>
        <div class="panel-toolbar-right">
            <a href="/ordini.php?sync=1" class="btn-sm">↻ Sincronizza</a>
            <a href="/ordini.php?sync=full" class="btn-sm" onclick="return confirm('Rifare il sync completo di tutti gli ordini? Può richiedere alcuni minuti.')">↻↻ Full rebuild</a>
        </div>
    </div>

    <form method="get" class="filters">
        <div class="filter-row">
            <label>Pagamento
                <select name="is_cod">
                    <option value="">— Tutti —</option>
                    <option value="1" <?= $filters['is_cod']==='1'?'selected':'' ?>>COD</option>
                    <option value="0" <?= $filters['is_cod']==='0'?'selected':'' ?>>Prepaid</option>
                </select>
            </label>
            <label>Stato consegna
                <select name="delivery_status">
                    <option value="">— Tutti —</option>
                    <option value="_none_" <?= $filters['delivery_status']==='_none_'?'selected':'' ?>>Da definire</option>
                    <?php foreach ($deliveryOptions as $k => $v): if ($k === '') continue; ?>
                        <option value="<?= tr_h($k) ?>" <?= $filters['delivery_status']===$k?'selected':'' ?>><?= tr_h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Stato pagamento
                <select name="financial">
                    <option value="">— Tutti —</option>
                    <?php foreach (['paid','pending','refunded','partially_refunded','voided','authorized'] as $f): ?>
                        <option value="<?= $f ?>" <?= $filters['financial']===$f?'selected':'' ?>><?= $f ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Fulfillment Shopify
                <select name="fulfillment">
                    <option value="">— Tutti —</option>
                    <option value="unfulfilled" <?= $filters['fulfillment']==='unfulfilled'?'selected':'' ?>>Da spedire</option>
                    <option value="fulfilled"   <?= $filters['fulfillment']==='fulfilled'?'selected':'' ?>>Spedito</option>
                    <option value="partial"     <?= $filters['fulfillment']==='partial'?'selected':'' ?>>Parziale</option>
                </select>
            </label>
            <label>Rientrato
                <select name="returned">
                    <option value="">— Tutti —</option>
                    <option value="1" <?= $filters['returned']==='1'?'selected':'' ?>>Sì</option>
                    <option value="0" <?= $filters['returned']==='0'?'selected':'' ?>>No</option>
                </select>
            </label>
            <label>Cancellato
                <select name="cancelled">
                    <option value="">— Tutti —</option>
                    <option value="1" <?= $filters['cancelled']==='1'?'selected':'' ?>>Sì</option>
                    <option value="0" <?= $filters['cancelled']==='0'?'selected':'' ?>>No</option>
                </select>
            </label>
            <label>Ricerca
                <input type="text" name="q" value="<?= tr_h($filters['search']) ?>" placeholder="ordine, email, nome, città, tel">
            </label>
            <button type="submit">Filtra</button>
            <a href="/ordini.php" class="btn-ghost">Reset</a>
        </div>
    </form>

    <section class="panel-table-wrap">
        <table class="panel-table panel-table-orders">
            <thead>
                <tr>
                    <th>Ordine</th>
                    <th>Cliente</th>
                    <th>Città</th>
                    <th class="num">Totale</th>
                    <th class="num">Costo</th>
                    <th class="num">Margine</th>
                    <th>Tipo</th>
                    <th>Stato</th>
                    <th>Tag</th>
                    <th>Data</th>
                    <th>Prodotti</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="11" class="muted" style="text-align:center; padding: 40px;">Nessun ordine trovato.</td></tr>
            <?php else: foreach ($orders as $o):
                $items = $orderItemsMap[(int)$o['id']] ?? [];
                $tagsArr = array_filter(array_map('trim', explode(',', (string)$o['tags'])));
                $tagsArr = array_values(array_filter($tagsArr, fn($t) => stripos($t, 'releasit') === false)); // nascondi tag tecnici
                $delivery = (string)($o['delivery_status'] ?? '');
                $rowClasses = [];
                if ($o['cancelled_at'])         $rowClasses[] = 'row-cancelled';
                if ($o['is_returned'])          $rowClasses[] = 'row-returned';
                if ($delivery === 'consegnato') $rowClasses[] = 'row-delivered';
            ?>
                <tr class="<?= implode(' ', $rowClasses) ?>">
                    <td><a href="https://<?= tr_h(SHOPIFY_SHOP_DOMAIN) ?>/admin/orders/<?= (int)$o['id'] ?>" target="_blank" class="order-link"><?= tr_h($o['name'] ?: '#' . $o['id']) ?></a></td>
                    <td>
                        <strong><?= tr_h(trim($o['customer_first_name'] . ' ' . $o['customer_last_name'])) ?: '—' ?></strong>
                        <?php if ($o['phone']): ?><br><small class="muted"><?= tr_h($o['phone']) ?></small><?php endif; ?>
                    </td>
                    <td><?= tr_h($o['shipping_city']) ?: '—' ?></td>
                    <td class="num" style="color: var(--pos);">€ <?= number_format((float)$o['total_price'], 2, ',', '.') ?></td>
                    <?php $p = $pnlMap[(int)$o['id']]; ?>
                    <td class="num" title="Bundle €<?= number_format($p['cogs_bundle'], 2, ',', '.') ?> + Spedizione €<?= number_format($p['cogs_shipping'], 2, ',', '.') ?><?= $p['cogs_loss'] > 0 ? ' + Perdita €' . number_format($p['cogs_loss'], 2, ',', '.') : '' ?>">
                        € <?= number_format($p['cost_total'], 2, ',', '.') ?>
                    </td>
                    <td class="num" style="color: <?= $p['margin'] >= 0 ? 'var(--pos)' : 'var(--neg)' ?>; font-weight: 700;">
                        € <?= number_format($p['margin'], 2, ',', '.') ?>
                        <?php if ($p['revenue'] > 0): ?>
                            <br><small class="muted"><?= number_format(($p['margin'] / $p['revenue']) * 100, 0, ',', '.') ?>%</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($o['is_cod']): ?>
                            <span class="badge badge-cod">COD</span>
                        <?php else: ?>
                            <span class="badge badge-prepaid">Prepaid</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <select class="delivery-select"
                                data-oid="<?= (int)$o['id'] ?>"
                                data-current="<?= tr_h($delivery) ?>">
                            <?php foreach ($deliveryOptions as $k => $v): ?>
                                <option value="<?= tr_h($k) ?>" <?= $delivery === $k ? 'selected' : '' ?>><?= tr_h($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <?php foreach ($tagsArr as $t): ?>
                            <span class="badge tag"><?= tr_h($t) ?></span>
                        <?php endforeach; ?>
                        <?php if ($o['is_returned']): ?><span class="badge badge-warn">Rientrato</span><?php endif; ?>
                        <?php if ($o['cancelled_at']): ?><span class="badge badge-err">Cancellato</span><?php endif; ?>
                    </td>
                    <td><small><?= $o['created_at'] ? tr_h(date('d/m/Y', (int)$o['created_at'])) : '—' ?></small></td>
                    <td class="cell-products">
                        <?php foreach ($items as $li): ?>
                            <div><span class="qty">×<?= (int)$li['quantity'] ?></span> <?= tr_h($li['title']) ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($orders)): ?>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right;">Totali pagina (<?= count($orders) ?> ordini)</td>
                    <td class="num" style="color: var(--pos);">€ <?= number_format($pageTotals['revenue'], 2, ',', '.') ?></td>
                    <td class="num">€ <?= number_format($pageTotals['cost'], 2, ',', '.') ?></td>
                    <td class="num" style="color: <?= $pageTotals['margin'] >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;">
                        € <?= number_format($pageTotals['margin'], 2, ',', '.') ?>
                    </td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </section>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php $qs = $_GET; unset($qs['p']); $base = '/ordini.php?' . http_build_query($qs); $sep = str_contains($base, '?') && substr($base, -1) !== '?' ? '&' : ''; ?>
            <?php if ($page > 1): ?>
                <a href="<?= tr_h($base . $sep . 'p=' . ($page - 1)) ?>" class="btn-sm">← Prec</a>
            <?php endif; ?>
            <span class="muted">Pagina <?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="<?= tr_h($base . $sep . 'p=' . ($page + 1)) ?>" class="btn-sm">Succ →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</main>

<script>
// Auto-save delivery status on change
document.querySelectorAll('.delivery-select').forEach(sel => {
    sel.addEventListener('change', async () => {
        const oid = sel.dataset.oid;
        const status = sel.value;
        sel.classList.add('saving');
        try {
            const fd = new FormData();
            fd.append('action', 'set_delivery');
            fd.append('order_id', oid);
            fd.append('status', status);
            const r = await fetch('/ordini.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (j.ok) {
                sel.classList.remove('saving');
                sel.classList.add('saved');
                setTimeout(() => sel.classList.remove('saved'), 1000);
                // Aggiorna colore in base allo stato
                sel.dataset.current = status;
                applyDeliveryColor(sel);
            }
        } catch (e) {
            sel.classList.remove('saving');
            sel.classList.add('error');
        }
    });
    applyDeliveryColor(sel);
});

function applyDeliveryColor(sel) {
    sel.classList.remove('ds-consegnato', 'ds-rientrato', 'ds-cancellato', 'ds-transito', 'ds-problema', 'ds-lavorazione');
    const map = {
        consegnato:     'ds-consegnato',
        in_transito:    'ds-transito',
        rientrato:      'ds-rientrato',
        cancellato:     'ds-cancellato',
        problema:       'ds-problema',
        in_lavorazione: 'ds-lavorazione',
    };
    if (map[sel.value]) sel.classList.add(map[sel.value]);
}
</script>

</body>
</html>
