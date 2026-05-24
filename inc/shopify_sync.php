<?php
/**
 * TREUDAS Tracker — sync ordini Shopify → tabella `shopify_orders`
 *
 * Sync incrementale basato su `updated_at_min` salvato in settings.
 * Tabella separata da `orders` (che è del funnel tracker e NON si tocca).
 */

declare(strict_types=1);

require_once __DIR__ . '/shopify_api.php';

/**
 * Esegue sync ordini. Se $fullRebuild=true ignora il cursore e ricomincia da capo.
 * Restituisce ['ok'=>bool, 'synced'=>int, 'error'=>?string, 'duration_ms'=>int]
 */
function sh_sync_orders(bool $fullRebuild = false): array {
    $start = microtime(true);

    if ($fullRebuild) {
        sh_setting_set('shopify_sync_updated_at_min', '');
    }

    $updatedAtMin = sh_setting_get('shopify_sync_updated_at_min');
    $query = [
        'status' => 'any',
        'limit'  => 250,
        'order'  => 'updated_at asc',
    ];
    if ($updatedAtMin) {
        $query['updated_at_min'] = $updatedAtMin;
    }

    $url = sh_api_url('orders.json', $query);
    $totalSynced = 0;
    $maxUpdatedAt = $updatedAtMin;

    while ($url) {
        $r = sh_get($url);
        if (!$r['ok']) {
            return [
                'ok' => false,
                'synced' => $totalSynced,
                'error' => $r['error'],
                'duration_ms' => (int)((microtime(true) - $start) * 1000),
            ];
        }

        $orders = $r['body']['orders'] ?? [];
        foreach ($orders as $o) {
            sh_upsert_order($o);
            $totalSynced++;
            $upd = $o['updated_at'] ?? null;
            if ($upd && (!$maxUpdatedAt || strtotime($upd) > strtotime($maxUpdatedAt))) {
                $maxUpdatedAt = $upd;
            }
        }

        $url = $r['next_url'];
    }

    if ($maxUpdatedAt) {
        // Salva cursore con +1s per evitare di rileggere l'ultimo ordine ogni volta
        $next = gmdate('c', strtotime($maxUpdatedAt) + 1);
        sh_setting_set('shopify_sync_updated_at_min', $next);
    }
    sh_setting_set('shopify_sync_last_run_at', (string)time());
    sh_setting_set('shopify_sync_last_count', (string)$totalSynced);

    return [
        'ok' => true,
        'synced' => $totalSynced,
        'error' => null,
        'duration_ms' => (int)((microtime(true) - $start) * 1000),
    ];
}

function sh_upsert_order(array $o): void {
    $pdo = tracker_db();

    $tags = (string)($o['tags'] ?? '');
    $tagsArr = array_map('trim', explode(',', strtolower($tags)));
    $isReturned = (int)in_array('rientrato in magazzino', $tagsArr, true);

    $gateways = (array)($o['payment_gateway_names'] ?? []);
    $gatewaysStr = implode(',', $gateways);
    $isCod = (int)(str_contains(strtolower($gatewaysStr), 'cod') || str_contains(strtolower($gatewaysStr), 'contrassegno') || str_contains(strtolower($gatewaysStr), 'cash on delivery'));

    $shipping = $o['shipping_address'] ?? [];
    $customer = $o['customer'] ?? [];

    $stmt = $pdo->prepare("
        INSERT INTO shopify_orders (
            id, order_number, name, email, phone,
            customer_first_name, customer_last_name,
            created_at, cancelled_at, closed_at,
            financial_status, fulfillment_status,
            subtotal_price, total_price, total_line_items_price,
            total_discounts, total_tax, total_shipping, current_total_price,
            currency, payment_gateway_names, is_cod,
            shipping_city, shipping_province, shipping_country, shipping_zip,
            tags, is_returned, line_items_json, raw_json,
            synced_at, shop_updated_at
        ) VALUES (
            :id, :order_number, :name, :email, :phone,
            :cfn, :cln,
            :created_at, :cancelled_at, :closed_at,
            :fin, :ful,
            :sub, :tot, :tli,
            :dsc, :tax, :shp, :ctp,
            :cur, :pgn, :cod,
            :scity, :sprov, :scnt, :szip,
            :tags, :ret, :li_json, :raw,
            :synced, :shop_upd
        )
        ON CONFLICT(id) DO UPDATE SET
            order_number = excluded.order_number,
            name = excluded.name,
            email = excluded.email,
            phone = excluded.phone,
            customer_first_name = excluded.customer_first_name,
            customer_last_name = excluded.customer_last_name,
            cancelled_at = excluded.cancelled_at,
            closed_at = excluded.closed_at,
            financial_status = excluded.financial_status,
            fulfillment_status = excluded.fulfillment_status,
            subtotal_price = excluded.subtotal_price,
            total_price = excluded.total_price,
            total_line_items_price = excluded.total_line_items_price,
            total_discounts = excluded.total_discounts,
            total_tax = excluded.total_tax,
            total_shipping = excluded.total_shipping,
            current_total_price = excluded.current_total_price,
            currency = excluded.currency,
            payment_gateway_names = excluded.payment_gateway_names,
            is_cod = excluded.is_cod,
            shipping_city = excluded.shipping_city,
            shipping_province = excluded.shipping_province,
            shipping_country = excluded.shipping_country,
            shipping_zip = excluded.shipping_zip,
            tags = excluded.tags,
            is_returned = excluded.is_returned,
            line_items_json = excluded.line_items_json,
            raw_json = excluded.raw_json,
            synced_at = excluded.synced_at,
            shop_updated_at = excluded.shop_updated_at
    ");

    $shippingLines = $o['shipping_lines'] ?? [];
    $totalShipping = 0.0;
    foreach ($shippingLines as $sl) {
        $totalShipping += (float)($sl['price'] ?? 0);
    }

    $stmt->execute([
        ':id'           => (int)$o['id'],
        ':order_number' => (string)($o['order_number'] ?? ''),
        ':name'         => (string)($o['name'] ?? ''),
        ':email'        => (string)($o['email'] ?? ''),
        ':phone'        => (string)($o['phone'] ?? ($customer['phone'] ?? '')),
        ':cfn'          => (string)($customer['first_name'] ?? ($shipping['first_name'] ?? '')),
        ':cln'          => (string)($customer['last_name']  ?? ($shipping['last_name']  ?? '')),
        ':created_at'   => isset($o['created_at']) ? strtotime($o['created_at']) : null,
        ':cancelled_at' => !empty($o['cancelled_at']) ? strtotime($o['cancelled_at']) : null,
        ':closed_at'    => !empty($o['closed_at']) ? strtotime($o['closed_at']) : null,
        ':fin'          => (string)($o['financial_status'] ?? ''),
        ':ful'          => (string)($o['fulfillment_status'] ?? ''),
        ':sub'          => (float)($o['subtotal_price'] ?? 0),
        ':tot'          => (float)($o['total_price'] ?? 0),
        ':tli'          => (float)($o['total_line_items_price'] ?? 0),
        ':dsc'          => (float)($o['total_discounts'] ?? 0),
        ':tax'          => (float)($o['total_tax'] ?? 0),
        ':shp'          => $totalShipping,
        ':ctp'          => (float)($o['current_total_price'] ?? $o['total_price'] ?? 0),
        ':cur'          => (string)($o['currency'] ?? 'EUR'),
        ':pgn'          => $gatewaysStr,
        ':cod'          => $isCod,
        ':scity'        => (string)($shipping['city'] ?? ''),
        ':sprov'        => (string)($shipping['province'] ?? ''),
        ':scnt'         => (string)($shipping['country'] ?? ''),
        ':szip'         => (string)($shipping['zip'] ?? ''),
        ':tags'         => $tags,
        ':ret'          => $isReturned,
        ':li_json'      => json_encode($o['line_items'] ?? [], JSON_UNESCAPED_UNICODE),
        ':raw'          => json_encode($o, JSON_UNESCAPED_UNICODE),
        ':synced'       => time(),
        ':shop_upd'     => isset($o['updated_at']) ? strtotime($o['updated_at']) : null,
    ]);
}

/**
 * Sync con throttling: non rifa il sync se l'ultimo è < $minSecondsAgo fa.
 */
function sh_sync_orders_throttled(int $minSecondsAgo = 30): array {
    $last = (int)(sh_setting_get('shopify_sync_last_run_at') ?? 0);
    if ($last > 0 && (time() - $last) < $minSecondsAgo) {
        return ['ok' => true, 'synced' => 0, 'skipped' => true, 'error' => null, 'duration_ms' => 0];
    }
    $r = sh_sync_orders(false);
    $r['skipped'] = false;
    return $r;
}
