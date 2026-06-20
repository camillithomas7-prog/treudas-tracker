<?php
/**
 * TREUDAS Tracker — helper client REST Shopify Admin API
 *
 * Usa il token salvato in `settings.shopify_admin_token` (vedi shopify_oauth.php).
 * Gestisce paginazione cursor-based (Link header), rate limit (Retry-After).
 */

declare(strict_types=1);

const SHOPIFY_API_VERSION = '2024-10';
const SHOPIFY_SHOP_DOMAIN = 'jdtzzb-7b.myshopify.com';

// Token Admin dello store attivo (per-store, salvato in stores.admin_token).
function sh_get_token(): ?string {
    return function_exists('tr_store_token') ? tr_store_token() : null;
}

// Dominio myshopify dello store attivo (fallback alla costante storica).
function sh_shop_domain(): string {
    if (function_exists('tr_store_current')) {
        $s = tr_store_current();
        if (!empty($s['myshopify_domain'])) return (string)$s['myshopify_domain'];
    }
    return SHOPIFY_SHOP_DOMAIN;
}

// Settings per-store (cogs, cursori sync, ecc.) → tabella store_settings.
function sh_setting_get(string $k): ?string {
    return function_exists('tr_sset_get') ? tr_sset_get($k) : null;
}

function sh_setting_set(string $k, string $v): void {
    if (function_exists('tr_sset_set')) tr_sset_set($k, $v);
}

/**
 * GET a una risorsa Shopify. Restituisce ['ok'=>bool, 'status'=>int, 'body'=>array, 'next_url'=>?string, 'error'=>?string]
 */
function sh_get(string $url): array {
    $token = sh_get_token();
    if (!$token) {
        return ['ok' => false, 'status' => 0, 'body' => [], 'next_url' => null, 'error' => 'Admin token non configurato (apri /shopify_oauth.php).'];
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'X-Shopify-Access-Token: ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'next_url' => null, 'error' => 'cURL error: ' . $err];
        }

        $rawHeaders = substr($resp, 0, $headerSize);
        $rawBody    = substr($resp, $headerSize);

        // Rate limit → backoff
        if ($status === 429) {
            $retryAfter = 1;
            if (preg_match('/Retry-After:\s*(\d+)/i', $rawHeaders, $m)) {
                $retryAfter = max(1, (int)$m[1]);
            }
            sleep(min($retryAfter, 5));
            continue;
        }

        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'body' => [], 'next_url' => null, 'error' => 'HTTP ' . $status . ': ' . substr($rawBody, 0, 500)];
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            return ['ok' => false, 'status' => $status, 'body' => [], 'next_url' => null, 'error' => 'JSON non valido'];
        }

        // Parse Link header per paginazione
        $nextUrl = null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $rawHeaders, $m)) {
            $nextUrl = $m[1];
        }

        return ['ok' => true, 'status' => $status, 'body' => $body, 'next_url' => $nextUrl, 'error' => null];
    }

    return ['ok' => false, 'status' => 429, 'body' => [], 'next_url' => null, 'error' => 'Rate limit dopo 5 tentativi'];
}

/**
 * Costruisce URL base Shopify Admin REST per una risorsa.
 */
function sh_api_url(string $resource, array $query = []): string {
    $base = 'https://' . sh_shop_domain() . '/admin/api/' . SHOPIFY_API_VERSION . '/' . ltrim($resource, '/');
    if (!empty($query)) {
        $base .= (str_contains($base, '?') ? '&' : '?') . http_build_query($query);
    }
    return $base;
}
