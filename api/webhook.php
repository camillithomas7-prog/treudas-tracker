<?php
/**
 * TREUDAS Tracker — webhook Shopify `orders/paid`
 * Con logging completo per debug.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';

function tr_webhook_log(int $status, string $result, string $hmacR = '', string $hmacC = '', string $secretPrefix = '', string $bodyPreview = '', string $err = ''): void {
    try {
        tracker_install_schema();
        $stmt = tracker_db()->prepare("
            INSERT INTO webhook_logs (ts, status_code, result, hmac_received, hmac_calculated, secret_used_prefix, body_preview, error_msg)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([time(), $status, $result, $hmacR, $hmacC, $secretPrefix, substr($bodyPreview, 0, 500), $err]);
    } catch (Throwable $e) {
        error_log('[treudas-tracker] webhook log failed: ' . $e->getMessage());
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tr_webhook_log(405, 'not_post');
    http_response_code(405); exit;
}

$raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) {
    tr_webhook_log(400, 'empty_body');
    http_response_code(400); exit;
}

$cfg = tracker_config();

// Cerca prima nelle settings DB
tracker_install_schema();
$dbSecret = tracker_db()->query("SELECT value FROM settings WHERE key = 'shopify_webhook_secret'")->fetchColumn();
$secret   = $dbSecret ?: ($cfg['shopify_webhook_secret'] ?? '');
$hmac     = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';

$secretPrefix = $secret ? substr($secret, 0, 6) . '...' . substr($secret, -4) : '(none)';
$bodyPreview  = substr($raw, 0, 300);

if (!$secret || $secret === 'INSERISCI_SECRET_SHOPIFY_QUI') {
    tr_webhook_log(503, 'no_secret', $hmac, '', $secretPrefix, $bodyPreview, 'secret non configurato');
    http_response_code(503); exit;
}

$calc = base64_encode(hash_hmac('sha256', $raw, $secret, true));

if (!hash_equals($calc, $hmac)) {
    tr_webhook_log(401, 'hmac_mismatch', $hmac, $calc, $secretPrefix, $bodyPreview, 'HMAC non corrisponde');
    http_response_code(401); exit;
}

try {
    $order = json_decode($raw, true);
    if (!is_array($order)) {
        tr_webhook_log(400, 'invalid_json', $hmac, $calc, $secretPrefix, $bodyPreview, 'JSON parse failed');
        http_response_code(400); exit;
    }

    $orderId    = (string)($order['id'] ?? '');
    if (!$orderId) {
        tr_webhook_log(400, 'no_order_id', $hmac, $calc, $secretPrefix, $bodyPreview, 'missing order id');
        http_response_code(400); exit;
    }

    $orderNum   = (string)($order['order_number'] ?? $order['name'] ?? '');
    $total      = (float)($order['total_price'] ?? $order['current_total_price'] ?? 0);
    $currency   = (string)($order['currency'] ?? 'EUR');
    $email      = (string)($order['email'] ?? '');
    $finStatus  = (string)($order['financial_status'] ?? '');
    $createdAt  = !empty($order['created_at']) ? strtotime((string)$order['created_at']) : time();

    $sessionId = null;
    $utm = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null];

    $attrs = $order['note_attributes'] ?? [];
    if (is_array($attrs)) {
        foreach ($attrs as $a) {
            $n = $a['name'] ?? ''; $v = $a['value'] ?? '';
            if ($n === '_session_id')   $sessionId = $v;
            if ($n === '_utm_source')   $utm['utm_source']   = $v;
            if ($n === '_utm_medium')   $utm['utm_medium']   = $v;
            if ($n === '_utm_campaign') $utm['utm_campaign'] = $v;
        }
    }

    if (!$sessionId && !empty($order['line_items']) && is_array($order['line_items'])) {
        foreach ($order['line_items'] as $li) {
            $props = $li['properties'] ?? [];
            if (!is_array($props)) continue;
            foreach ($props as $p) {
                $n = $p['name'] ?? ''; $v = $p['value'] ?? '';
                if ($n === '_session_id' && !$sessionId)   $sessionId = $v;
                if ($n === '_utm_source'   && !$utm['utm_source'])   $utm['utm_source']   = $v;
                if ($n === '_utm_medium'   && !$utm['utm_medium'])   $utm['utm_medium']   = $v;
                if ($n === '_utm_campaign' && !$utm['utm_campaign']) $utm['utm_campaign'] = $v;
            }
            if ($sessionId) break;
        }
    }

    $db = tracker_db();
    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO orders (
            shopify_order_id, session_id, order_number, total_price, currency,
            email, financial_status, created_at, received_at,
            utm_source, utm_medium, utm_campaign, raw_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(shopify_order_id) DO UPDATE SET
            session_id       = COALESCE(session_id, excluded.session_id),
            total_price      = excluded.total_price,
            financial_status = excluded.financial_status,
            utm_source       = COALESCE(utm_source,   excluded.utm_source),
            utm_medium       = COALESCE(utm_medium,   excluded.utm_medium),
            utm_campaign     = COALESCE(utm_campaign, excluded.utm_campaign),
            raw_json         = excluded.raw_json
    ");
    $stmt->execute([
        $orderId, $sessionId, $orderNum, $total, $currency,
        $email, $finStatus, $createdAt, time(),
        $utm['utm_source'], $utm['utm_medium'], $utm['utm_campaign'],
        $raw,
    ]);

    if ($sessionId) {
        $chk = $db->prepare("SELECT 1 FROM events WHERE session_id = ? AND event_type = 'purchase' AND meta_json LIKE ?");
        $chk->execute([$sessionId, '%"order_id":"' . $orderId . '"%']);
        if (!$chk->fetchColumn()) {
            $meta = json_encode([
                'order_id'    => $orderId,
                'order_num'   => $orderNum,
                'total'       => $total,
                'currency'    => $currency,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ev = $db->prepare("
                INSERT INTO events (session_id, event_type, ts, client_ts, url, meta_json)
                VALUES (?, 'purchase', ?, ?, ?, ?)
            ");
            $ev->execute([$sessionId, time(), $createdAt, '/checkout/thank_you', $meta]);
        }
    }

    $db->commit();
    tr_webhook_log(200, 'ok', $hmac, $calc, $secretPrefix, $bodyPreview, "order_id=$orderId session_id=" . ($sessionId ?: 'null'));
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'OK';

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    tr_webhook_log(500, 'exception', $hmac, $calc ?? '', $secretPrefix, $bodyPreview, $e->getMessage());
    http_response_code(500);
}
