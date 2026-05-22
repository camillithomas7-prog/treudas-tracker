<?php
/**
 * Diagnostica: confronta cosa c'è nel DB con cosa mostra la dashboard.
 * Solo lettura, niente modifiche.
 *
 * Aprire: https://lemonchiffon-lion-484144.hostingersite.com/diag_funnel.php
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';

date_default_timezone_set(tracker_config()['timezone']);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$db = tracker_db();
$now = time();
$today_start = strtotime('today');
$yesterday_start = strtotime('yesterday');

echo "=== DIAGNOSTICA FUNNEL ===\n";
echo "Ora corrente: " . date('Y-m-d H:i:s') . " (timestamp $now)\n";
echo "Inizio 'oggi':     " . date('Y-m-d H:i:s', $today_start) . " (timestamp $today_start)\n";
echo "Inizio 'ieri':     " . date('Y-m-d H:i:s', $yesterday_start) . " (timestamp $yesterday_start)\n";
echo "Timezone PHP:      " . date_default_timezone_get() . "\n\n";

// 1) Conta righe nelle tabelle principali
echo "=== TOTALI TABELLE ===\n";
$total_sessions = (int)$db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
$total_events   = (int)$db->query("SELECT COUNT(*) FROM events")->fetchColumn();
$total_logs     = (int)$db->query("SELECT COUNT(*) FROM track_logs")->fetchColumn();
echo "sessions:   $total_sessions righe\n";
echo "events:     $total_events righe\n";
echo "track_logs: $total_logs righe\n\n";

// 2) Eventi advertorial_view di OGGI (per ts dell'evento)
echo "=== ADVERTORIAL_VIEW OGGI ===\n";
$st = $db->prepare("SELECT COUNT(*) FROM events WHERE event_type = 'advertorial_view' AND ts >= ?");
$st->execute([$today_start]);
$adv_today_by_event_ts = (int)$st->fetchColumn();
echo "Eventi advertorial_view oggi (filtrando su events.ts): $adv_today_by_event_ts\n";

// 3) Sessioni create OGGI
$st = $db->prepare("SELECT COUNT(*) FROM sessions WHERE created_at >= ?");
$st->execute([$today_start]);
$sessions_today = (int)$st->fetchColumn();
echo "Sessioni create oggi (sessions.created_at): $sessions_today\n";

// 4) Sessioni create oggi CHE HANNO advertorial_view (query del funnel attuale)
$st = $db->prepare("
    SELECT COUNT(DISTINCT e.session_id)
      FROM events e
      JOIN sessions s ON s.id = e.session_id
     WHERE e.event_type = 'advertorial_view'
       AND s.created_at >= ?
       AND s.created_at <= ?
");
$st->execute([$today_start, $now]);
$funnel_today = (int)$st->fetchColumn();
echo "Sessioni create oggi con advertorial_view (query funnel): $funnel_today\n\n";

// 5) Confronto: c'è discrepanza? Cerchiamo eventi advertorial_view oggi LA CUI SESSIONE è creata PRIMA di oggi
$st = $db->prepare("
    SELECT COUNT(*) FROM events e
    JOIN sessions s ON s.id = e.session_id
    WHERE e.event_type = 'advertorial_view'
      AND e.ts >= ?
      AND s.created_at < ?
");
$st->execute([$today_start, $today_start]);
$orphan = (int)$st->fetchColumn();
echo "=== POSSIBILE CAUSA: SESSIONI VECCHIE CON EVENTI NUOVI ===\n";
echo "Advertorial_view di oggi SU SESSIONI create prima di oggi (non contate nel funnel!): $orphan\n\n";

// 6) Ultimi 10 advertorial_view di oggi con dettagli
echo "=== ULTIMI 10 ADVERTORIAL_VIEW DI OGGI ===\n";
$st = $db->prepare("
    SELECT e.session_id, e.ts AS event_ts, s.created_at AS session_created_at, s.utm_source, s.utm_campaign
      FROM events e
      JOIN sessions s ON s.id = e.session_id
     WHERE e.event_type = 'advertorial_view'
       AND e.ts >= ?
     ORDER BY e.ts DESC
     LIMIT 10
");
$st->execute([$today_start]);
foreach ($st->fetchAll() as $r) {
    $event_when = date('H:i:s', (int)$r['event_ts']);
    $session_when = date('Y-m-d H:i', (int)$r['session_created_at']);
    $oggi = ((int)$r['session_created_at'] >= $today_start) ? 'OGGI' : 'PRIMA-DI-OGGI';
    echo "  evento {$event_when} | sid " . substr($r['session_id'], 0, 12) . "... | session creata {$session_when} [$oggi] | utm={$r['utm_source']}/{$r['utm_campaign']}\n";
}
echo "\n";

// 7) Stessa query del funnel ma SEMPLIFICATA su events (test alternativo)
echo "=== TEST: SE IL FUNNEL FILTRASSE SU events.ts INVECE CHE su sessions.created_at ===\n";
$st = $db->prepare("
    SELECT COUNT(DISTINCT e.session_id)
      FROM events e
     WHERE e.event_type = 'advertorial_view'
       AND e.ts >= ?
       AND e.ts <= ?
");
$st->execute([$today_start, $now]);
$alt = (int)$st->fetchColumn();
echo "Conteggio se filtrassimo su events.ts: $alt\n";
echo "(differenza con funnel attuale: " . ($alt - $funnel_today) . ")\n\n";

echo "=== FINE DIAGNOSTICA ===\n";
