<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';

tracker_install_schema();
$logs = tracker_db()->query("SELECT * FROM track_logs ORDER BY ts DESC LIMIT 100")->fetchAll();
$total = (int)tracker_db()->query("SELECT COUNT(*) FROM track_logs")->fetchColumn();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TREUDAS Tracker — Track Logs</title>
<link rel="stylesheet" href="/assets/style.css">
<style>
    body { padding: 20px; }
    .log { background: var(--bg-card); border: 1px solid var(--border); padding: 10px 14px; border-radius: 8px; margin-bottom: 8px; font-size: 12px; }
    .log .head { display: flex; gap: 12px; align-items: center; margin-bottom: 6px; flex-wrap: wrap; }
    .badge { padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 11px; }
    .b204, .b200 { background: #1f5e2a; color: #6dd47e; }
    .b400, .b401, .b405 { background: #5e1f1f; color: #ff8888; }
    .b500 { background: #5e3f1f; color: #ffb86c; }
    code { background: var(--bg-card-2); padding: 2px 6px; border-radius: 3px; font-size: 11px; word-break: break-all; }
    .row { margin: 3px 0; }
    .row strong { color: var(--text-dim); display: inline-block; min-width: 100px; }
</style>
</head>
<body>
<header class="topbar">
    <div class="brand">📊 TREUDAS <span>Tracker</span> · Track Logs (<?= $total ?> totali)</div>
    <a href="/" class="btn-ghost">← Dashboard</a>
</header>

<main class="container">
    <p class="muted">Ultime 100 chiamate all'endpoint <code>/api/track.php</code>. Se questa pagina è vuota = il browser NON sta inviando eventi al tracker.</p>

    <?php if (!$logs): ?>
        <div class="card">
            <p class="muted">⚠ <strong>Nessuna chiamata ricevuta</strong>. Possibili motivi:</p>
            <ol>
                <li>Il tracker.js non è caricato sul sito (controlla in DevTools Network)</li>
                <li>CORS blocca le richieste (controlla Console DevTools per errori)</li>
                <li>Il tema vecchio è ancora in cache nel browser (riapri incognito)</li>
            </ol>
        </div>
    <?php else: ?>
        <?php foreach ($logs as $l): ?>
            <div class="log">
                <div class="head">
                    <span class="badge b<?= (int)$l['status_code'] ?>"><?= (int)$l['status_code'] ?></span>
                    <strong><?= tr_h($l['result']) ?></strong>
                    <span class="muted"><?= tr_format_dt((int)$l['ts']) ?></span>
                    <?php if ($l['origin']): ?><span class="muted">· <?= tr_h($l['origin']) ?></span><?php endif; ?>
                    <?php if ($l['ip']): ?><span class="muted">· IP <?= tr_h($l['ip']) ?></span><?php endif; ?>
                </div>
                <?php if ($l['error_msg']): ?>
                    <div class="row"><strong>Note:</strong> <?= tr_h($l['error_msg']) ?></div>
                <?php endif; ?>
                <?php if ($l['body_preview']): ?>
                    <pre style="background: var(--bg-card-2); padding: 8px; border-radius: 4px; font-size: 11px; overflow-x: auto; margin: 6px 0; max-height: 100px;"><?= tr_h($l['body_preview']) ?></pre>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
