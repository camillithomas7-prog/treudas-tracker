<?php
/**
 * TREUDAS Tracker — endpoint eventi client
 *
 * Riceve eventi inviati dal tema Shopify via navigator.sendBeacon().
 * Payload JSON atteso:
 *   {
 *     "session_id":   "uuid",
 *     "event_type":   "session_start|advertorial_view|cta_click|product_view|add_to_cart|checkout_start",
 *     "client_ts":    1747800000,
 *     "url":          "https://treudasofficial.com/",
 *     "referrer":     "...",
 *     "utm":          { "utm_source": "...", "utm_medium": "...", ... },
 *     "meta":         { "cta_position": 2, "variant_id": "..." }
 *   }
 *
 * Risponde 204 No Content (sendBeacon non legge la risposta).
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';

tr_send_cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    tracker_install_schema();

    $body = tr_json_in();
    $sid  = trim((string)($body['session_id'] ?? ''));
    $type = trim((string)($body['event_type'] ?? ''));

    if (!$sid || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $sid)) {
        http_response_code(400); exit;
    }

    $allowed_events = [
        'session_start', 'advertorial_view', 'cta_click',
        'product_view', 'add_to_cart', 'checkout_start',
        'thank_you_view',
    ];
    if (!in_array($type, $allowed_events, true)) {
        http_response_code(400); exit;
    }

    $now      = time();
    $url      = substr((string)($body['url'] ?? ''), 0, 1000);
    $referrer = substr((string)($body['referrer'] ?? ''), 0, 1000);
    $clientTs = (int)($body['client_ts'] ?? 0);
    $utm      = is_array($body['utm'] ?? null) ? $body['utm'] : [];
    $meta     = is_array($body['meta'] ?? null) ? $body['meta'] : [];

    $ip     = tr_client_ip();
    $ua     = tr_user_agent();
    $device = tr_device_type($ua);

    $db = tracker_db();
    $db->beginTransaction();

    // Crea sessione se non esiste
    $exists = $db->prepare("SELECT id FROM sessions WHERE id = ?");
    $exists->execute([$sid]);
    if (!$exists->fetchColumn()) {
        $ins = $db->prepare("
            INSERT INTO sessions (
                id, created_at, first_url, referrer,
                utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                fbclid, gclid, ttclid,
                ip, ua, device_type, last_seen_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $sid, $now, $url, $referrer,
            $utm['utm_source']   ?? null,
            $utm['utm_medium']   ?? null,
            $utm['utm_campaign'] ?? null,
            $utm['utm_content']  ?? null,
            $utm['utm_term']     ?? null,
            $utm['fbclid']       ?? null,
            $utm['gclid']        ?? null,
            $utm['ttclid']       ?? null,
            $ip, $ua, $device, $now,
        ]);
    } else {
        // aggiorna last_seen + eventuali UTM nuovi se non già impostati
        $upd = $db->prepare("
            UPDATE sessions SET
                last_seen_at = ?,
                utm_source   = COALESCE(utm_source,   ?),
                utm_medium   = COALESCE(utm_medium,   ?),
                utm_campaign = COALESCE(utm_campaign, ?),
                utm_content  = COALESCE(utm_content,  ?),
                utm_term     = COALESCE(utm_term,     ?),
                fbclid       = COALESCE(fbclid,       ?),
                gclid        = COALESCE(gclid,        ?),
                ttclid       = COALESCE(ttclid,       ?)
            WHERE id = ?
        ");
        $upd->execute([
            $now,
            $utm['utm_source']   ?? null,
            $utm['utm_medium']   ?? null,
            $utm['utm_campaign'] ?? null,
            $utm['utm_content']  ?? null,
            $utm['utm_term']     ?? null,
            $utm['fbclid']       ?? null,
            $utm['gclid']        ?? null,
            $utm['ttclid']       ?? null,
            $sid,
        ]);
    }

    // De-dup: alcuni eventi devono valere 1 per sessione (advertorial_view, product_view, ecc.)
    $singleton = ['advertorial_view', 'product_view', 'add_to_cart', 'checkout_start', 'session_start'];
    if (in_array($type, $singleton, true)) {
        $chk = $db->prepare("SELECT 1 FROM events WHERE session_id = ? AND event_type = ? LIMIT 1");
        $chk->execute([$sid, $type]);
        if ($chk->fetchColumn()) {
            $db->commit();
            http_response_code(204);
            exit;
        }
    }

    $evt = $db->prepare("
        INSERT INTO events (session_id, event_type, ts, client_ts, url, meta_json)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $evt->execute([
        $sid, $type, $now,
        $clientTs ?: null,
        $url,
        $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
    ]);

    $db->commit();
    http_response_code(204);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[treudas-tracker] track.php: ' . $e->getMessage());
    http_response_code(500);
    if (!empty(tracker_config()['debug'])) {
        header('Content-Type: text/plain');
        echo $e->getMessage();
    }
}
