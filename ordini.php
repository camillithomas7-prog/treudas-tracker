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

// Sync incrementale (throttled a 30s) all'apertura, oppure full su richiesta
$syncResult = null;
if (isset($_GET['sync'])) {
    $syncResult = $_GET['sync'] === 'full' ? sh_sync_orders(true) : sh_sync_orders(false);
    // PRG: pulisci la query
    header('Location: /ordini.php?synced=' . ($syncResult['ok'] ? $syncResult['synced'] : 'err'));
    exit;
}
$syncResult = sh_sync_orders_throttled(30);
$syncedFlag = $_GET['synced'] ?? null;

// Filtri
$filters = [
    'is_cod'      => $_GET['is_cod']      ?? '',
    'fulfillment' => $_GET['fulfillment'] ?? '',
    'financial'   => $_GET['financial']   ?? '',
    'returned'    => $_GET['returned']    ?? '',
    'cancelled'   => $_GET['cancelled']   ?? '',
    'search'      => trim((string)($_GET['q'] ?? '')),
];

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$total = sh_count_orders($filters);
$orders = sh_list_orders($filters, $perPage, ($page - 1) * $perPage);
$totalPages = max(1, (int)ceil($total / $perPage));

$lastSyncAt = (int)(sh_setting_get('shopify_sync_last_run_at') ?? 0);

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
                    <option value="1" <?= $filters['is_cod']==='1'?'selected':'' ?>>COD (contrassegno)</option>
                    <option value="0" <?= $filters['is_cod']==='0'?'selected':'' ?>>Prepagato</option>
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
            <label>Stato consegna
                <select name="fulfillment">
                    <option value="">— Tutti —</option>
                    <option value="unfulfilled" <?= $filters['fulfillment']==='unfulfilled'?'selected':'' ?>>Da spedire</option>
                    <option value="fulfilled"   <?= $filters['fulfillment']==='fulfilled'?'selected':'' ?>>Spedito</option>
                    <option value="partial"     <?= $filters['fulfillment']==='partial'?'selected':'' ?>>Parziale</option>
                </select>
            </label>
            <label>Rientrato magazzino
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
        <table class="panel-table">
            <thead>
                <tr>
                    <th>Ordine</th>
                    <th>Data</th>
                    <th>Cliente</th>
                    <th>Città</th>
                    <th>Pagamento</th>
                    <th>Consegna</th>
                    <th>Totale</th>
                    <th>Stato</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="8" class="muted" style="text-align:center; padding: 40px;">Nessun ordine trovato con i filtri attuali.</td></tr>
            <?php else: ?>
                <?php foreach ($orders as $o): ?>
                    <tr class="<?= $o['cancelled_at'] ? 'row-cancelled' : '' ?> <?= $o['is_returned'] ? 'row-returned' : '' ?>">
                        <td><strong><?= tr_h($o['name']) ?: '#' . $o['id'] ?></strong></td>
                        <td><?= $o['created_at'] ? tr_h(date('d/m/Y H:i', (int)$o['created_at'])) : '—' ?></td>
                        <td>
                            <?= tr_h(trim($o['customer_first_name'] . ' ' . $o['customer_last_name'])) ?: tr_h($o['email']) ?>
                            <?php if ($o['email']): ?><br><small class="muted"><?= tr_h($o['email']) ?></small><?php endif; ?>
                        </td>
                        <td><?= tr_h($o['shipping_city']) ?><br><small class="muted"><?= tr_h($o['shipping_province']) ?></small></td>
                        <td>
                            <?php if ($o['is_cod']): ?>
                                <span class="badge badge-cod">COD</span>
                            <?php else: ?>
                                <span class="badge badge-prepaid">Prepaid</span>
                            <?php endif; ?>
                            <br><small class="muted"><?= tr_h($o['financial_status']) ?></small>
                        </td>
                        <td>
                            <?php $f = $o['fulfillment_status']; ?>
                            <?php if (!$f): ?>
                                <span class="badge badge-pending">Da spedire</span>
                            <?php elseif ($f === 'fulfilled'): ?>
                                <span class="badge badge-ok">Spedito</span>
                            <?php else: ?>
                                <span class="badge"><?= tr_h($f) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="num">€ <?= number_format((float)$o['total_price'], 2, ',', '.') ?></td>
                        <td>
                            <?php if ($o['cancelled_at']): ?>
                                <span class="badge badge-err">Cancellato</span>
                            <?php endif; ?>
                            <?php if ($o['is_returned']): ?>
                                <span class="badge badge-warn">Rientrato</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $qs = $_GET; unset($qs['p']);
            $base = '/ordini.php?' . http_build_query($qs);
            $sep = str_contains($base, '?') && substr($base, -1) !== '?' ? '&' : '';
            ?>
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
</body>
</html>
