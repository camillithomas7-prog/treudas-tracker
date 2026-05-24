<?php
/**
 * TREUDAS Tracker — callback OAuth Shopify Admin API
 *
 * Riceve il `code` da Shopify dopo l'autorizzazione, lo scambia per un
 * Admin API access token e lo salva nella tabella settings.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';

tracker_install_schema();

const SHOPIFY_APP_CLIENT_ID = 'c3bb96eb37e344ce6081d7a5b665edec';
const SHOPIFY_APP_SHOP      = 'jdtzzb-7b.myshopify.com';

function trcb_setting_get(string $k): ?string {
    $stmt = tracker_db()->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$k]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (string)$v : null;
}
function trcb_setting_set(string $k, string $v): void {
    $stmt = tracker_db()->prepare("
        INSERT INTO settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value
    ");
    $stmt->execute([$k, $v]);
}

function trcb_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Errore OAuth</title>';
    echo '<body style="font-family: system-ui; background: #0a0a14; color: #eee; padding: 40px; max-width: 640px; margin: 0 auto;">';
    echo '<h1 style="color: #ff5577;">⚠ Errore OAuth Shopify</h1>';
    echo '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="/shopify_oauth.php" style="color: #ffb86b;">← Torna alla pagina di setup</a></p>';
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

// Verifica shop atteso
if ($shop !== SHOPIFY_APP_SHOP) {
    trcb_fail("Shop non corrispondente. Atteso: " . SHOPIFY_APP_SHOP . " — ricevuto: $shop");
}

// Verifica state (anti CSRF)
$savedState = trcb_setting_get('shopify_oauth_state');
if (!$savedState || !hash_equals($savedState, $state)) {
    trcb_fail('State non valido (possibile CSRF). Riprova dalla pagina di setup.');
}

// Verifica HMAC della query
$clientSecret = trcb_setting_get('shopify_app_client_secret');
if (!$clientSecret) {
    trcb_fail('Client Secret non configurato. Torna su /shopify_oauth.php e inseriscilo.');
}

$params = $_GET;
unset($params['hmac'], $params['signature']);
ksort($params);
$msgToSign = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
$calc = hash_hmac('sha256', $msgToSign, $clientSecret);
if (!hash_equals($calc, $hmac)) {
    trcb_fail('HMAC non valido. La richiesta potrebbe essere stata manomessa.');
}

// Scambia code → access_token
$tokenUrl = 'https://' . SHOPIFY_APP_SHOP . '/admin/oauth/access_token';
$payload = json_encode([
    'client_id'     => SHOPIFY_APP_CLIENT_ID,
    'client_secret' => $clientSecret,
    'code'          => $code,
]);

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resp === false || $httpCode !== 200) {
    trcb_fail("Errore scambio token: HTTP $httpCode — " . ($curlErr ?: $resp));
}

$data = json_decode((string)$resp, true);
if (!is_array($data) || empty($data['access_token'])) {
    trcb_fail('Risposta Shopify senza access_token. Body: ' . substr((string)$resp, 0, 300));
}

$accessToken = (string)$data['access_token'];
$grantedScopes = (string)($data['scope'] ?? '');

trcb_setting_set('shopify_admin_token', $accessToken);
trcb_setting_set('shopify_admin_token_scopes', $grantedScopes);
trcb_setting_set('shopify_admin_token_saved_at', (string)time());

// Invalida lo state usato
trcb_setting_set('shopify_oauth_state', '');

// Redirect alla pagina di setup
header('Location: /shopify_oauth.php?ok=1');
exit;
