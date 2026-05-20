<?php
/**
 * TREUDAS Tracker — helper generici
 */

function tr_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}

function tr_user_agent(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
}

function tr_device_type(string $ua): string {
    if (preg_match('/mobile|android|iphone|ipod/i', $ua) && !preg_match('/ipad|tablet/i', $ua)) return 'mobile';
    if (preg_match('/ipad|tablet/i', $ua)) return 'tablet';
    return 'desktop';
}

function tr_origin_allowed(): ?string {
    $cfg = tracker_config();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$origin) return null;
    foreach ($cfg['allowed_origins'] as $a) {
        if (strcasecmp($a, $origin) === 0) return $origin;
    }
    return null;
}

function tr_send_cors(): void {
    $origin = tr_origin_allowed();
    if ($origin) {
        header("Access-Control-Allow-Origin: $origin");
        header("Vary: Origin");
        header("Access-Control-Allow-Methods: POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type");
        header("Access-Control-Max-Age: 86400");
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function tr_json_in(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function tr_money(float $v, string $cur = 'EUR'): string {
    $sym = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'][$cur] ?? '';
    return $sym . number_format($v, 2, ',', '.');
}

function tr_pct(int $num, int $den): string {
    if ($den <= 0) return '0%';
    return number_format(($num / $den) * 100, 1, ',', '.') . '%';
}

function tr_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tr_format_dt(int $ts, ?string $tz = null): string {
    $tz = $tz ?: tracker_config()['timezone'];
    $d = new DateTimeImmutable('@' . $ts);
    $d = $d->setTimezone(new DateTimeZone($tz));
    return $d->format('d/m/Y H:i');
}
