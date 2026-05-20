<?php
/**
 * TREUDAS Tracker — accesso DB SQLite
 */

function tracker_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = dirname(__DIR__) . '/config.php';

    // Auto-bootstrap: se config.php non esiste, lo creo da example con secret random
    if (!file_exists($path)) {
        $example = dirname(__DIR__) . '/config.example.php';
        if (!file_exists($example)) {
            http_response_code(500);
            die("⚠ Né config.php né config.example.php trovati.");
        }
        $tpl = file_get_contents($example);
        $tpl = str_replace(
            'CAMBIARE_QUESTA_STRINGA_SUBITO_random_32+_chars',
            bin2hex(random_bytes(24)),
            $tpl
        );
        @file_put_contents($path, $tpl);
        @chmod($path, 0640);
    }

    $cfg = require $path;
    return $cfg;
}

function tracker_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = tracker_config();
    $dir = dirname($cfg['db_path']);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . $cfg['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function tracker_install_schema(): void {
    $pdo = tracker_db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            id TEXT PRIMARY KEY,
            created_at INTEGER NOT NULL,
            first_url TEXT,
            referrer TEXT,
            utm_source TEXT,
            utm_medium TEXT,
            utm_campaign TEXT,
            utm_content TEXT,
            utm_term TEXT,
            fbclid TEXT,
            gclid TEXT,
            ttclid TEXT,
            ip TEXT,
            ip_country TEXT,
            ua TEXT,
            device_type TEXT,
            last_seen_at INTEGER
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_created ON sessions(created_at);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_campaign ON sessions(utm_campaign);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_source ON sessions(utm_source);");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT NOT NULL,
            event_type TEXT NOT NULL,
            ts INTEGER NOT NULL,
            client_ts INTEGER,
            url TEXT,
            meta_json TEXT
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_session ON events(session_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_type ON events(event_type);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_session_type ON events(session_id, event_type);");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            shopify_order_id TEXT PRIMARY KEY,
            session_id TEXT,
            order_number TEXT,
            total_price REAL,
            currency TEXT,
            email TEXT,
            financial_status TEXT,
            created_at INTEGER,
            received_at INTEGER NOT NULL,
            utm_source TEXT,
            utm_medium TEXT,
            utm_campaign TEXT,
            raw_json TEXT
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_session ON orders(session_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_campaign ON orders(utm_campaign);");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            last_login_at INTEGER
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webhook_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts INTEGER NOT NULL,
            status_code INTEGER,
            result TEXT,
            hmac_received TEXT,
            hmac_calculated TEXT,
            secret_used_prefix TEXT,
            body_preview TEXT,
            error_msg TEXT
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_webhook_logs_ts ON webhook_logs(ts DESC);");
}
