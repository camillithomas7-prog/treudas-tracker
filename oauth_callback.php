<?php
/**
 * Callback OAuth Shopify — multi-store.
 * Identifica lo store dal dominio `shop`, verifica state + HMAC con il
 * client_secret di quello store, scambia il code per il token e lo salva
 * nella riga dello store.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
tracker_install_schema();

function trcb_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Errore OAuth</title>';
    echo '<body style="font-family: system-ui; background: #0a0a14; color: #eee; padding: 40px; max-width: 640px; margin: 0 auto;">';
    echo '<h1 style="color: #ff5577;">⚠ Errore OAuth Shopify</h1>';
    echo '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="/stores.php" style="color: #ffb86b;">← Torna alla gestione store</a></p>';
    echo '</body>';
    exit;
}

$code  = $_GET['code']  ?? '';
$shop  = $_GET['shop']  ?? '';
$state = $_GET['state'] ?? '';
$hmac  = $_GET['hmac']  ?? '';

if ($code === '' || $shop === '' || $state === '' || $hmac === '') {
    trcb_fail('Parametri mancanti nella callback (code/shop/state/hmac).');
}

// Identifica lo store dal dominio
$store = tr_store_by_domain($shop);
if (!$store) {
    trcb_fail("Nessuno store configurato per il dominio «$shop». Aggiungilo prima su /stores.php col dominio myshopify corretto.");
}
$sid = (int)$store['id'];

// State anti-CSRF (salvato per quello store)
$savedState = tr_sset_get('oauth_state', $sid);
if (!$savedState || !hash_equals($savedState, $state)) {
    trcb_fail('State non valido (possibile CSRF). Riavvia la connessione da /stores.php.');
}

$clientId     = (string)($store['client_id'] ?? '');
$clientSecret = (string)($store['client_secret'] ?? '');
if ($clientId === '' || $clientSecret === '') {
    trcb_fail('Client ID/Secret non configurati per questo store.');
}

// Verifica HMAC della query
$params = $_GET;
unset($params['hmac'], $params['signature']);
ksort($params);
$msgToSign = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
$calc = hash_hmac('sha256', $msgToSign, $clientSecret);
if (!hash_equals($calc, $hmac)) {
    trcb_fail('HMAC non valido. La richiesta potrebbe essere stata manomessa.');
}

// Scambia code → access_token
$tokenUrl = 'https://' . $shop . '/admin/oauth/access_token';
$payload = json_encode([
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'code'          => $code,
]);

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resp === false || $httpCode !== 200) {
    trcb_fail("Errore scambio token: HTTP $httpCode — " . ($curlErr ?: substr((string)$resp, 0, 300)));
}

$data = json_decode((string)$resp, true);
if (!is_array($data) || empty($data['access_token'])) {
    trcb_fail('Risposta Shopify senza access_token. Body: ' . substr((string)$resp, 0, 300));
}

// Salva il token nella riga dello store
tracker_db()->prepare("UPDATE stores SET admin_token = ? WHERE id = ?")
    ->execute([(string)$data['access_token'], $sid]);
tr_sset_set('shopify_admin_token_scopes', (string)($data['scope'] ?? ''), $sid);
tr_sset_set('shopify_admin_token_saved_at', (string)time(), $sid);
tr_sset_set('oauth_state', '', $sid);

header('Location: /stores.php?ok=1&store=' . urlencode((string)$store['slug']));
exit;
