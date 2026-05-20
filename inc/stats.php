<?php
/**
 * TREUDAS Tracker — query analytics
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Funnel: numero di sessioni DISTINTE che hanno triggerato ciascun evento.
 * @param array $filters from/to (unix), utm_source/medium/campaign/content (string)
 */
function tr_funnel(array $filters = []): array {
    $db = tracker_db();

    [$where, $params] = tr_session_filters($filters);

    $steps = [
        'advertorial_view'  => 'Hanno letto l\'advertorial',
        'cta_click'         => 'Cliccato CTA "Acquista"',
        'product_view'      => 'Arrivati alla pagina prodotto',
        'add_to_cart'       => 'Aggiunto al carrello',
        'checkout_start'    => 'Iniziato il checkout',
        'purchase'          => 'Acquisto completato',
    ];

    $out = [];
    foreach ($steps as $type => $label) {
        $sql = "
            SELECT COUNT(DISTINCT e.session_id)
              FROM events e
              JOIN sessions s ON s.id = e.session_id
             WHERE e.event_type = :t
               $where
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [':t' => $type]));
        $out[] = [
            'event' => $type,
            'label' => $label,
            'count' => (int)$stmt->fetchColumn(),
        ];
    }

    return $out;
}

/**
 * Breakdown per campagna UTM
 */
function tr_campaign_breakdown(array $filters = []): array {
    $db = tracker_db();
    [$where, $params] = tr_session_filters($filters, 's');

    $sql = "
        SELECT
            COALESCE(NULLIF(s.utm_campaign, ''), '(nessuna)') AS campagna,
            COALESCE(NULLIF(s.utm_source, ''),   '(diretto)') AS source,
            COUNT(DISTINCT s.id) AS sessions,
            COUNT(DISTINCT CASE WHEN e.event_type = 'product_view'    THEN s.id END) AS product_views,
            COUNT(DISTINCT CASE WHEN e.event_type = 'add_to_cart'     THEN s.id END) AS add_to_carts,
            COUNT(DISTINCT CASE WHEN e.event_type = 'purchase'        THEN s.id END) AS purchases,
            COALESCE(SUM(o.total_price), 0) AS revenue
        FROM sessions s
        LEFT JOIN events e ON e.session_id = s.id
        LEFT JOIN orders o ON o.session_id = s.id
        WHERE 1=1 $where
        GROUP BY campagna, source
        ORDER BY revenue DESC, sessions DESC
        LIMIT 50
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Andamento giornaliero
 */
function tr_daily_trend(array $filters = []): array {
    $db = tracker_db();
    $tz = tracker_config()['timezone'];
    [$where, $params] = tr_session_filters($filters, 's');

    // SQLite: convertiamo unix ts in data ISO usando datetime + offset
    $sql = "
        SELECT
            strftime('%Y-%m-%d', datetime(s.created_at, 'unixepoch', 'localtime')) AS day,
            COUNT(DISTINCT s.id) AS sessions,
            COUNT(DISTINCT CASE WHEN e.event_type = 'product_view' THEN s.id END) AS product_views,
            COUNT(DISTINCT CASE WHEN e.event_type = 'purchase'     THEN s.id END) AS purchases,
            COALESCE(SUM(o.total_price), 0) AS revenue
        FROM sessions s
        LEFT JOIN events e ON e.session_id = s.id
        LEFT JOIN orders o ON o.session_id = s.id
        WHERE 1=1 $where
        GROUP BY day
        ORDER BY day ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Ultimi acquisti
 */
function tr_recent_purchases(int $limit = 20, array $filters = []): array {
    $db = tracker_db();

    $w = []; $p = [];
    if (!empty($filters['from'])) { $w[] = "o.created_at >= :from"; $p[':from'] = (int)$filters['from']; }
    if (!empty($filters['to']))   { $w[] = "o.created_at <= :to";   $p[':to']   = (int)$filters['to']; }
    foreach (['utm_source','utm_medium','utm_campaign'] as $k) {
        if (!empty($filters[$k])) { $w[] = "o.$k = :$k"; $p[":$k"] = $filters[$k]; }
    }
    $whereSql = $w ? 'WHERE ' . implode(' AND ', $w) : '';

    $stmt = $db->prepare("
        SELECT shopify_order_id, order_number, total_price, currency, email,
               created_at, utm_source, utm_campaign, session_id
          FROM orders o
          $whereSql
         ORDER BY created_at DESC
         LIMIT $limit
    ");
    $stmt->execute($p);
    return $stmt->fetchAll();
}

/**
 * Helper: costruisce WHERE comune per filtri sessione.
 * Usa alias `s` per la tabella sessions (devono essere joinate).
 */
function tr_session_filters(array $f, string $alias = 's'): array {
    $w = []; $p = [];
    if (!empty($f['from'])) { $w[] = "$alias.created_at >= :from"; $p[':from'] = (int)$f['from']; }
    if (!empty($f['to']))   { $w[] = "$alias.created_at <= :to";   $p[':to']   = (int)$f['to']; }
    foreach (['utm_source','utm_medium','utm_campaign','utm_content'] as $k) {
        if (!empty($f[$k])) { $w[] = "$alias.$k = :$k"; $p[":$k"] = $f[$k]; }
    }
    if (!empty($f['device_type'])) {
        $w[] = "$alias.device_type = :device_type";
        $p[':device_type'] = $f['device_type'];
    }
    return [$w ? ' AND ' . implode(' AND ', $w) : '', $p];
}

/**
 * Distinct values per dropdown filtri
 */
function tr_distinct(string $col, int $limit = 100): array {
    $allowed = ['utm_source','utm_medium','utm_campaign','utm_content'];
    if (!in_array($col, $allowed, true)) return [];
    $stmt = tracker_db()->query("
        SELECT DISTINCT $col
          FROM sessions
         WHERE $col IS NOT NULL AND $col != ''
         ORDER BY $col ASC
         LIMIT $limit
    ");
    return array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
}
