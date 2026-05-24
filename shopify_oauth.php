<?php
/**
 * TREUDAS Tracker — connessione OAuth Shopify Admin API
 *
 * Pagina di setup/avvio del flusso OAuth verso lo store TREUDAS per
 * ottenere un Admin API token usato dal pannello ordini/statistiche/costi.
 *
 * Flow:
 *   1. utente apre questa pagina
 *   2. inserisce Client Secret (Client ID e shop sono già configurati nel codice)
 *   3. click "Connetti" → redirect a Shopify admin per autorizzazione
 *   4. Shopify reindirizza su oauth_callback.php con il code
 *   5. oauth_callback.php scambia code → access_token e lo salva in settings
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';

tracker_install_schema();

// Costanti app Shopify (lato pubblico)
const SHOPIFY_APP_CLIENT_ID = 'c3bb96eb37e344ce6081d7a5b665edec';
const SHOPIFY_APP_SHOP      = 'jdtzzb-7b.myshopify.com';
const SHOPIFY_APP_SCOPES    = 'read_all_orders,read_customers,read_fulfillments,read_inventory,read_orders,read_products,read_returns,read_shipping';

function trshop_setting_get(string $k): ?string {
    $stmt = tracker_db()->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$k]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (string)$v : null;
}
function trshop_setting_set(string $k, string $v): void {
    $stmt = tracker_db()->prepare("
        INSERT INTO settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value
    ");
    $stmt->execute([$k, $v]);
}

$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_secret') {
        $secret = trim((string)($_POST['client_secret'] ?? ''));
        if ($secret === '' || !str_starts_with($secret, 'shpss_')) {
            $err = 'Il Client Secret deve iniziare con "shpss_".';
        } else {
            trshop_setting_set('shopify_app_client_secret', $secret);
            $msg = '✔ Client Secret salvato. Ora clicca "Connetti TREUDAS".';
        }
    } elseif ($action === 'disconnect') {
        trshop_setting_set('shopify_admin_token', '');
        $msg = '✔ Token rimosso.';
    }
}

$clientSecret = trshop_setting_get('shopify_app_client_secret');
$adminToken   = trshop_setting_get('shopify_admin_token');
$tokenSavedAt = trshop_setting_get('shopify_admin_token_saved_at');

// Costruisci URL OAuth Shopify
$callbackUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/oauth_callback.php';
$state = bin2hex(random_bytes(16));
trshop_setting_set('shopify_oauth_state', $state);

$authUrl = 'https://' . SHOPIFY_APP_SHOP . '/admin/oauth/authorize?' . http_build_query([
    'client_id'    => SHOPIFY_APP_CLIENT_ID,
    'scope'        => SHOPIFY_APP_SCOPES,
    'redirect_uri' => $callbackUrl,
    'state'        => $state,
]);

$tokenMasked = $adminToken ? substr($adminToken, 0, 8) . str_repeat('•', 16) . substr($adminToken, -4) : '(non connesso)';
$secretMasked = $clientSecret ? substr($clientSecret, 0, 8) . str_repeat('•', 12) . substr($clientSecret, -4) : '(non impostato)';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TREUDAS Tracker — Connessione Shopify Admin</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="auth">
<main class="auth-card" style="max-width: 620px;">
    <h1>Connessione Shopify Admin API</h1>
    <p class="muted">Setup OAuth per il pannello ordini/statistiche/costi TREUDAS</p>

    <?php if ($msg): ?><div class="alert alert-ok"><?= tr_h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-err"><?= tr_h($err) ?></div><?php endif; ?>

    <div style="margin-top: 22px; padding: 14px; background: var(--bg-card-2); border-radius: 8px; font-size: 13px; line-height: 1.7;">
        <strong>Stato attuale:</strong><br>
        Shop: <code><?= tr_h(SHOPIFY_APP_SHOP) ?></code><br>
        Client ID: <code style="font-size: 11px;"><?= tr_h(SHOPIFY_APP_CLIENT_ID) ?></code><br>
        Client Secret: <code style="color: var(--accent-2);"><?= tr_h($secretMasked) ?></code><br>
        Admin Token: <code style="color: var(--accent-2);"><?= tr_h($tokenMasked) ?></code>
        <?php if ($tokenSavedAt): ?><br><span class="muted">salvato il <?= tr_h(date('d/m/Y H:i', (int)$tokenSavedAt)) ?></span><?php endif; ?>
    </div>

    <?php if (!$clientSecret): ?>
        <form method="post" autocomplete="off" style="margin-top: 24px;">
            <input type="hidden" name="action" value="save_secret">
            <label>1. Incolla il Client Secret dell'app
                <input type="text" name="client_secret" placeholder="shpss_..." required>
            </label>
            <button type="submit">Salva Client Secret</button>
        </form>
    <?php else: ?>
        <div style="margin-top: 24px;">
            <a href="<?= tr_h($authUrl) ?>" class="btn-primary" style="display: inline-block; padding: 12px 22px; background: linear-gradient(135deg, #ff7849, #ffb86b); color: #1a0a05; border-radius: 8px; text-decoration: none; font-weight: 600;">
                <?= $adminToken ? '↻ Riconnetti TREUDAS' : '→ Connetti TREUDAS' ?>
            </a>
            <?php if ($adminToken): ?>
                <form method="post" style="display: inline; margin-left: 12px;">
                    <input type="hidden" name="action" value="disconnect">
                    <button type="submit" style="background: transparent; color: var(--text-dim); border: 1px solid var(--text-dim);">Disconnetti</button>
                </form>
            <?php endif; ?>
        </div>

        <details style="margin-top: 22px; font-size: 13px;">
            <summary class="muted" style="cursor: pointer;">Cambia Client Secret</summary>
            <form method="post" autocomplete="off" style="margin-top: 12px;">
                <input type="hidden" name="action" value="save_secret">
                <input type="text" name="client_secret" placeholder="shpss_..." required>
                <button type="submit">Aggiorna</button>
            </form>
        </details>
    <?php endif; ?>

    <p style="margin-top: 28px; text-align: center;">
        <a href="/" class="muted">← Torna alla dashboard</a>
    </p>
</main>
</body>
</html>
