<?php
/**
 * TREUDAS Tracker — pagina impostazioni
 * Permette di salvare il webhook secret Shopify senza editare file.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';

tracker_install_schema();
$db = tracker_db();

function tr_setting_get(string $k): ?string {
    $stmt = tracker_db()->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$k]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (string)$v : null;
}
function tr_setting_set(string $k, string $v): void {
    $stmt = tracker_db()->prepare("
        INSERT INTO settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value
    ");
    $stmt->execute([$k, $v]);
}

$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $secret = trim((string)($_POST['shopify_webhook_secret'] ?? ''));
    if ($secret === '') {
        $err = 'Il secret non può essere vuoto.';
    } else {
        tr_setting_set('shopify_webhook_secret', $secret);
        $msg = '✔ Secret Shopify salvato.';
    }
}

$currentSecret = tr_setting_get('shopify_webhook_secret');
$secretMasked  = $currentSecret ? substr($currentSecret, 0, 6) . str_repeat('•', max(0, strlen($currentSecret) - 10)) . substr($currentSecret, -4) : '(non impostato)';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TREUDAS Tracker — Impostazioni</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="auth">
<main class="auth-card" style="max-width: 520px;">
    <h1>Impostazioni</h1>
    <p class="muted">Configurazione webhook Shopify</p>

    <?php if ($msg): ?><div class="alert alert-ok"><?= tr_h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-err"><?= tr_h($err) ?></div><?php endif; ?>

    <div style="margin-top: 20px; padding: 14px; background: var(--bg-card-2); border-radius: 8px; font-size: 13px;">
        <strong>Secret attualmente salvato:</strong><br>
        <code style="color: var(--accent-2); font-size: 12px;"><?= tr_h($secretMasked) ?></code>
    </div>

    <form method="post" autocomplete="off" style="margin-top: 24px;">
        <label>Shopify webhook secret
            <input type="text" name="shopify_webhook_secret" placeholder="incolla qui il secret di Shopify" required>
        </label>
        <button type="submit">Salva</button>
    </form>

    <div style="margin-top: 28px; font-size: 13px; color: var(--text-dim); line-height: 1.6;">
        <strong style="color: var(--text);">Dove trovarlo:</strong><br>
        1. Shopify Admin → <strong>Settings</strong> → <strong>Notifications</strong><br>
        2. Scorri fino a <strong>Webhooks</strong> in fondo<br>
        3. Click <strong>"Create webhook"</strong><br>
        4. Event: <code>Order payment</code> · Format: <code>JSON</code><br>
        5. URL: <code style="color: var(--accent-2); font-size: 11px;">https://lemonchiffon-lion-484144.hostingersite.com/api/webhook.php</code><br>
        6. Save → Shopify mostra il secret una sola volta → copialo e incollalo sopra
    </div>

    <p style="margin-top: 22px; text-align: center;">
        <a href="/" class="muted">← Torna alla dashboard</a>
    </p>
</main>
</body>
</html>
