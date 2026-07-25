<?php
/**
 * TREUDAS Tracker — visualizzazione log webhook
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/guard.php';
require_once __DIR__ . '/inc/helpers.php';

tracker_install_schema();
$logs = tracker_db()->query("SELECT * FROM webhook_logs ORDER BY ts DESC LIMIT 50")->fetchAll();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TREUDAS Tracker — Webhook Logs</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
<style>
    body { padding: 20px; }
    .log { background: var(--bg-card); border: 1px solid var(--border); padding: 14px; border-radius: 8px; margin-bottom: 10px; font-size: 12px; }
    .log .head { display: flex; gap: 14px; align-items: center; margin-bottom: 8px; flex-wrap: wrap; }
    .badge { padding: 3px 10px; border-radius: 4px; font-weight: 600; font-size: 11px; }
    .b200 { background: #1f5e2a; color: #6dd47e; }
    .b401, .b400, .b503 { background: #5e1f1f; color: #ff8888; }
    .b500 { background: #5e3f1f; color: #ffb86c; }
    code { background: var(--bg-card-2); padding: 2px 6px; border-radius: 3px; font-size: 11px; word-break: break-all; }
    .row { margin: 4px 0; }
    .row strong { color: var(--text-dim); display: inline-block; min-width: 130px; }
</style>
</head>
<body>
<header class="topbar">
    <div class="brand">📊 TREUDAS <span>Tracker</span> · Webhook Logs</div>
    <a href="/" class="btn-ghost">← Dashboard</a>
</header>

<main class="container">
    <p class="muted">Ultimi 50 tentativi di chiamata al webhook Shopify <code>/api/webhook.php</code>.</p>

    <?php if (!$logs): ?>
        <div class="card">
            <p class="muted">Nessun log presente. Nessuna chiamata al webhook ricevuta finora.</p>
            <p>Vai su Shopify Admin → Settings → Notifications → Webhooks → tre puntini → <strong>Send test notification</strong> per inviare un test.</p>
        </div>
    <?php else: ?>
        <?php foreach ($logs as $l): ?>
            <div class="log">
                <div class="head">
                    <span class="badge b<?= (int)$l['status_code'] ?>"><?= (int)$l['status_code'] ?></span>
                    <strong><?= tr_h($l['result']) ?></strong>
                    <span class="muted"><?= tr_format_dt((int)$l['ts']) ?></span>
                </div>

                <?php if ($l['error_msg']): ?>
                    <div class="row"><strong>Note:</strong> <?= tr_h($l['error_msg']) ?></div>
                <?php endif; ?>

                <?php if ($l['secret_used_prefix']): ?>
                    <div class="row"><strong>Secret usato:</strong> <code><?= tr_h($l['secret_used_prefix']) ?></code></div>
                <?php endif; ?>

                <?php if ($l['hmac_received']): ?>
                    <div class="row"><strong>HMAC ricevuto:</strong> <code><?= tr_h($l['hmac_received']) ?></code></div>
                <?php endif; ?>

                <?php if ($l['hmac_calculated']): ?>
                    <div class="row"><strong>HMAC calcolato:</strong> <code><?= tr_h($l['hmac_calculated']) ?></code></div>
                <?php endif; ?>

                <?php if ($l['body_preview']): ?>
                    <div class="row"><strong>Body (300 char):</strong></div>
                    <pre style="background: var(--bg-card-2); padding: 8px; border-radius: 4px; font-size: 11px; overflow-x: auto; max-height: 120px;"><?= tr_h($l['body_preview']) ?></pre>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <p style="margin-top: 30px; text-align: center;">
        <a href="/" class="btn-ghost">← Torna alla dashboard</a>
    </p>
</main>
</body>
</html>
