<?php
/**
 * Avvio OAuth per uno store specifico.
 * /oauth_start.php?store=<id> → redirect alla pagina di autorizzazione Shopify.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/guard.php';
require_once __DIR__ . '/inc/helpers.php';
tracker_install_schema();

$sid   = (int)($_GET['store'] ?? 0);
$store = $sid ? tr_store_get($sid) : null;

// solo uno store DELL'UTENTE loggato può avviare l'OAuth
if (!tr_store_owned($store)) { header('Location: /stores.php'); exit; }
if (empty($store['client_id']) || empty($store['myshopify_domain'])) {
    header('Location: /stores.php?edit=' . $sid . '&err=oauth_missing'); exit;
}

$scopes      = 'read_orders,read_products';
$callbackUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . '/oauth_callback.php';
$state       = bin2hex(random_bytes(16));
tr_sset_set('oauth_state', $state, $sid);

$authUrl = 'https://' . $store['myshopify_domain'] . '/admin/oauth/authorize?' . http_build_query([
    'client_id'    => $store['client_id'],
    'scope'        => $scopes,
    'redirect_uri' => $callbackUrl,
    'state'        => $state,
]);

header('Location: ' . $authUrl);
exit;
