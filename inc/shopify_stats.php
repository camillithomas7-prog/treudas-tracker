<?php
/**
 * TREUDAS Tracker — query aggregate sul pannello Shopify
 * (KPI dashboard ordini/statistiche, opera solo su `shopify_orders`)
 */

declare(strict_types=1);

function sh_kpi(int $from, int $to): array {
    $pdo = tracker_db();
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS n,
            COALESCE(SUM(total_line_items_price), 0) AS lordo,
            COALESCE(SUM(total_discounts), 0) AS sconti,
            COALESCE(SUM(total_price), 0) AS dopo_sconti,
            COALESCE(SUM(current_total_price), 0) AS netto_dopo_resi,
            COALESCE(SUM(total_shipping), 0) AS spedizione,
            COALESCE(SUM(total_tax), 0) AS tasse,
            SUM(CASE WHEN is_cod = 1 THEN 1 ELSE 0 END) AS n_cod,
            SUM(CASE WHEN is_cod = 0 THEN 1 ELSE 0 END) AS n_prepaid,
            SUM(CASE WHEN is_cod = 1 THEN total_price ELSE 0 END) AS fatturato_cod,
            SUM(CASE WHEN is_cod = 0 THEN total_price ELSE 0 END) AS fatturato_prepaid,
            SUM(CASE WHEN is_returned = 1 THEN 1 ELSE 0 END) AS n_rientrati,
            SUM(CASE WHEN cancelled_at IS NOT NULL THEN 1 ELSE 0 END) AS n_cancellati
        FROM shopify_orders
        WHERE created_at BETWEEN :from AND :to
    ");
    $stmt->execute([':from' => $from, ':to' => $to]);
    $row = $stmt->fetch() ?: [];

    // Resi: ordini cancellati nel range (per data cancellazione)
    $stmtR = $pdo->prepare("
        SELECT COUNT(*) AS n, COALESCE(SUM(total_price), 0) AS importo
        FROM shopify_orders
        WHERE cancelled_at BETWEEN :from AND :to
    ");
    $stmtR->execute([':from' => $from, ':to' => $to]);
    $resi = $stmtR->fetch() ?: ['n' => 0, 'importo' => 0];

    $row['resi_n']       = (int)$resi['n'];
    $row['resi_importo'] = (float)$resi['importo'];

    // Tasso consegna COD: COD non cancellati / COD totali
    $cod = (int)$row['n_cod'];
    if ($cod > 0) {
        $stmtC = $pdo->prepare("
            SELECT
                SUM(CASE WHEN cancelled_at IS NULL AND is_returned = 0 THEN 1 ELSE 0 END) AS consegnati,
                SUM(CASE WHEN cancelled_at IS NOT NULL OR is_returned = 1 THEN 1 ELSE 0 END) AS persi
            FROM shopify_orders
            WHERE is_cod = 1 AND created_at BETWEEN :from AND :to
        ");
        $stmtC->execute([':from' => $from, ':to' => $to]);
        $c = $stmtC->fetch() ?: ['consegnati' => 0, 'persi' => 0];
        $row['cod_consegnati'] = (int)$c['consegnati'];
        $row['cod_persi']      = (int)$c['persi'];
        $row['cod_tasso']      = $cod > 0 ? ((int)$c['consegnati'] / $cod) : 0;
    } else {
        $row['cod_consegnati'] = 0;
        $row['cod_persi']      = 0;
        $row['cod_tasso']      = 0;
    }

    return $row;
}

function sh_daily_trend(int $from, int $to): array {
    $pdo = tracker_db();
    $stmt = $pdo->prepare("
        SELECT
            strftime('%Y-%m-%d', datetime(created_at, 'unixepoch')) AS giorno,
            COUNT(*) AS n,
            COALESCE(SUM(total_price), 0) AS fatturato,
            SUM(CASE WHEN is_cod = 1 THEN 1 ELSE 0 END) AS n_cod
        FROM shopify_orders
        WHERE created_at BETWEEN :from AND :to
        GROUP BY giorno
        ORDER BY giorno ASC
    ");
    $stmt->execute([':from' => $from, ':to' => $to]);
    return $stmt->fetchAll() ?: [];
}

function sh_monthly_trend(): array {
    $pdo = tracker_db();
    $stmt = $pdo->query("
        SELECT
            strftime('%Y-%m', datetime(created_at, 'unixepoch')) AS mese,
            COUNT(*) AS n,
            COALESCE(SUM(total_price), 0) AS fatturato,
            COALESCE(SUM(current_total_price), 0) AS netto,
            SUM(CASE WHEN is_cod = 1 THEN 1 ELSE 0 END) AS n_cod,
            SUM(CASE WHEN cancelled_at IS NOT NULL THEN 1 ELSE 0 END) AS n_cancellati
        FROM shopify_orders
        WHERE created_at IS NOT NULL
        GROUP BY mese
        ORDER BY mese ASC
    ");
    return $stmt->fetchAll() ?: [];
}

function sh_top_cities(int $from, int $to, int $limit = 10): array {
    $pdo = tracker_db();
    $stmt = $pdo->prepare("
        SELECT shipping_city AS citta, COUNT(*) AS n, SUM(total_price) AS fatturato
        FROM shopify_orders
        WHERE created_at BETWEEN :from AND :to
          AND shipping_city IS NOT NULL AND shipping_city != ''
        GROUP BY shipping_city
        ORDER BY n DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':from', $from, PDO::PARAM_INT);
    $stmt->bindValue(':to',   $to, PDO::PARAM_INT);
    $stmt->bindValue(':lim',  $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function sh_list_orders(array $filters, int $limit = 50, int $offset = 0): array {
    $pdo = tracker_db();
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['from'])) {
        $where[] = 'created_at >= :from';
        $params[':from'] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $where[] = 'created_at <= :to';
        $params[':to'] = $filters['to'];
    }
    if (isset($filters['is_cod']) && $filters['is_cod'] !== '') {
        $where[] = 'is_cod = :cod';
        $params[':cod'] = (int)$filters['is_cod'];
    }
    if (!empty($filters['fulfillment'])) {
        if ($filters['fulfillment'] === 'unfulfilled') {
            $where[] = "(fulfillment_status IS NULL OR fulfillment_status = '')";
        } else {
            $where[] = 'fulfillment_status = :ful';
            $params[':ful'] = $filters['fulfillment'];
        }
    }
    if (!empty($filters['financial'])) {
        $where[] = 'financial_status = :fin';
        $params[':fin'] = $filters['financial'];
    }
    if (isset($filters['returned']) && $filters['returned'] !== '') {
        $where[] = 'is_returned = :ret';
        $params[':ret'] = (int)$filters['returned'];
    }
    if (isset($filters['cancelled']) && $filters['cancelled'] !== '') {
        if ((int)$filters['cancelled'] === 1) {
            $where[] = 'cancelled_at IS NOT NULL';
        } else {
            $where[] = 'cancelled_at IS NULL';
        }
    }
    if (!empty($filters['search'])) {
        $where[] = '(name LIKE :q OR email LIKE :q OR customer_first_name LIKE :q OR customer_last_name LIKE :q OR shipping_city LIKE :q OR phone LIKE :q)';
        $params[':q'] = '%' . $filters['search'] . '%';
    }

    $sql = "SELECT * FROM shopify_orders WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function sh_count_orders(array $filters): int {
    $pdo = tracker_db();
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['from'])) { $where[] = 'created_at >= :from'; $params[':from'] = $filters['from']; }
    if (!empty($filters['to']))   { $where[] = 'created_at <= :to';   $params[':to']   = $filters['to']; }
    if (isset($filters['is_cod']) && $filters['is_cod'] !== '') { $where[] = 'is_cod = :cod'; $params[':cod'] = (int)$filters['is_cod']; }
    if (!empty($filters['fulfillment'])) {
        if ($filters['fulfillment'] === 'unfulfilled') {
            $where[] = "(fulfillment_status IS NULL OR fulfillment_status = '')";
        } else {
            $where[] = 'fulfillment_status = :ful';
            $params[':ful'] = $filters['fulfillment'];
        }
    }
    if (!empty($filters['financial'])) { $where[] = 'financial_status = :fin'; $params[':fin'] = $filters['financial']; }
    if (isset($filters['returned']) && $filters['returned'] !== '') { $where[] = 'is_returned = :ret'; $params[':ret'] = (int)$filters['returned']; }
    if (isset($filters['cancelled']) && $filters['cancelled'] !== '') {
        $where[] = (int)$filters['cancelled'] === 1 ? 'cancelled_at IS NOT NULL' : 'cancelled_at IS NULL';
    }
    if (!empty($filters['search'])) {
        $where[] = '(name LIKE :q OR email LIKE :q OR customer_first_name LIKE :q OR customer_last_name LIKE :q OR shipping_city LIKE :q OR phone LIKE :q)';
        $params[':q'] = '%' . $filters['search'] . '%';
    }

    $sql = "SELECT COUNT(*) FROM shopify_orders WHERE " . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function sh_costs_for_month(int $year, int $month): array {
    $pdo = tracker_db();
    $stmt = $pdo->prepare("SELECT * FROM shopify_costs WHERE year = :y AND month = :m");
    $stmt->execute([':y' => $year, ':m' => $month]);
    return $stmt->fetch() ?: [
        'year' => $year, 'month' => $month,
        'spese_spedizione' => 0, 'spesa_merce' => 0, 'spesa_ads' => 0,
        'spesa_influencer' => 0, 'spesa_team' => 0, 'spese_varie' => 0,
        'bonifici_brt' => 0, 'note' => '',
    ];
}

function sh_costs_all(): array {
    $pdo = tracker_db();
    return $pdo->query("SELECT * FROM shopify_costs ORDER BY year DESC, month DESC")->fetchAll() ?: [];
}

function sh_costs_upsert(int $year, int $month, array $vals): void {
    $pdo = tracker_db();
    $stmt = $pdo->prepare("
        INSERT INTO shopify_costs (year, month, spese_spedizione, spesa_merce, spesa_ads, spesa_influencer, spesa_team, spese_varie, bonifici_brt, note, updated_at)
        VALUES (:y, :m, :sped, :merce, :ads, :inf, :team, :varie, :brt, :note, :upd)
        ON CONFLICT(year, month) DO UPDATE SET
            spese_spedizione = excluded.spese_spedizione,
            spesa_merce      = excluded.spesa_merce,
            spesa_ads        = excluded.spesa_ads,
            spesa_influencer = excluded.spesa_influencer,
            spesa_team       = excluded.spesa_team,
            spese_varie      = excluded.spese_varie,
            bonifici_brt     = excluded.bonifici_brt,
            note             = excluded.note,
            updated_at       = excluded.updated_at
    ");
    $stmt->execute([
        ':y'     => $year,
        ':m'     => $month,
        ':sped'  => (float)($vals['spese_spedizione'] ?? 0),
        ':merce' => (float)($vals['spesa_merce'] ?? 0),
        ':ads'   => (float)($vals['spesa_ads'] ?? 0),
        ':inf'   => (float)($vals['spesa_influencer'] ?? 0),
        ':team'  => (float)($vals['spesa_team'] ?? 0),
        ':varie' => (float)($vals['spese_varie'] ?? 0),
        ':brt'   => (float)($vals['bonifici_brt'] ?? 0),
        ':note'  => (string)($vals['note'] ?? ''),
        ':upd'   => time(),
    ]);
}

function sh_costs_month_total(array $c): float {
    return (float)($c['spese_spedizione'] ?? 0)
         + (float)($c['spesa_merce'] ?? 0)
         + (float)($c['spesa_ads'] ?? 0)
         + (float)($c['spesa_influencer'] ?? 0)
         + (float)($c['spesa_team'] ?? 0)
         + (float)($c['spese_varie'] ?? 0);
}

/**
 * Catalogo prodotti con aggregato volume/ordini (da line_items) e costo unitario.
 * $search filtra per titolo.
 */
function sh_products_catalog(string $search = ''): array {
    $pdo = tracker_db();
    $sql = "
        SELECT
            p.id,
            p.title,
            p.handle,
            p.status,
            p.image_url,
            p.cost_unit,
            COALESCE(SUM(li.quantity), 0)        AS volume,
            COUNT(DISTINCT li.order_id)          AS ordini
        FROM shopify_products p
        LEFT JOIN shopify_order_items li ON li.product_id = p.id
        WHERE 1=1
    ";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (p.title LIKE :q OR p.handle LIKE :q)";
        $params[':q'] = '%' . $search . '%';
    }
    $sql .= " GROUP BY p.id ORDER BY volume DESC, p.title ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function sh_product_cost_set(int $productId, float $cost): void {
    tracker_db()->prepare("UPDATE shopify_products SET cost_unit = :c WHERE id = :id")
        ->execute([':c' => $cost, ':id' => $productId]);
}

/**
 * KPI logistici COGS: numero ordini per stato e costi totali stimati.
 */
function sh_cogs_kpi(int $from, int $to, array $unitCosts): array {
    $pdo = tracker_db();
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN fulfillment_status = 'fulfilled' AND is_returned = 0 AND cancelled_at IS NULL THEN 1 ELSE 0 END) AS n_spediti,
            SUM(CASE WHEN is_returned = 1 THEN 1 ELSE 0 END) AS n_rientrati,
            SUM(CASE WHEN (fulfillment_status IS NULL OR fulfillment_status = '' OR fulfillment_status = 'partial') AND is_returned = 0 AND cancelled_at IS NULL THEN 1 ELSE 0 END) AS n_giacenza
        FROM shopify_orders
        WHERE created_at BETWEEN :from AND :to
    ");
    $stmt->execute([':from' => $from, ':to' => $to]);
    $row = $stmt->fetch() ?: ['n_spediti' => 0, 'n_rientrati' => 0, 'n_giacenza' => 0];

    $row['cost_shipping']  = (float)$unitCosts['shipping'] * (int)$row['n_spediti'];
    $row['cost_return']    = (float)$unitCosts['return']   * (int)$row['n_rientrati'];
    $row['cost_stock']     = (float)$unitCosts['stock']    * (int)$row['n_giacenza'];
    $row['cost_logistics'] = $row['cost_shipping'] + $row['cost_return'] + $row['cost_stock'];

    // COGS prodotto: somma qty * cost_unit per ordini nel periodo
    $stmt2 = $pdo->prepare("
        SELECT COALESCE(SUM(li.quantity * COALESCE(p.cost_unit, 0)), 0) AS cogs_prodotto
        FROM shopify_order_items li
        JOIN shopify_orders o ON o.id = li.order_id
        LEFT JOIN shopify_products p ON p.id = li.product_id
        WHERE o.created_at BETWEEN :from AND :to
          AND o.cancelled_at IS NULL
    ");
    $stmt2->execute([':from' => $from, ':to' => $to]);
    $row['cogs_prodotto'] = (float)$stmt2->fetchColumn();

    return $row;
}

function sh_cogs_unit_costs(): array {
    return [
        'shipping' => (float)(sh_setting_get('cogs_shipping_per_order') ?? 0),
        'return'   => (float)(sh_setting_get('cogs_return_per_order') ?? 0),
        'stock'    => (float)(sh_setting_get('cogs_stock_per_order') ?? 0),
    ];
}

function sh_cogs_unit_costs_set(float $shipping, float $return, float $stock): void {
    sh_setting_set('cogs_shipping_per_order', (string)$shipping);
    sh_setting_set('cogs_return_per_order',   (string)$return);
    sh_setting_set('cogs_stock_per_order',    (string)$stock);
}

function sh_revenue_for_month(int $year, int $month): array {
    $pdo = tracker_db();
    $from = mktime(0, 0, 0, $month, 1, $year);
    $to   = mktime(0, 0, -1, $month + 1, 1, $year);
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(total_price), 0) AS lordo,
            COALESCE(SUM(current_total_price), 0) AS netto,
            COUNT(*) AS n,
            SUM(CASE WHEN is_cod = 1 THEN 1 ELSE 0 END) AS n_cod
        FROM shopify_orders
        WHERE created_at BETWEEN :from AND :to
    ");
    $stmt->execute([':from' => $from, ':to' => $to]);
    return $stmt->fetch() ?: ['lordo' => 0, 'netto' => 0, 'n' => 0, 'n_cod' => 0];
}
