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

    // Migrazione: aggiungi colonna checkout_token se non esiste (per dedup checkout_start)
    $cols = $pdo->query("PRAGMA table_info(events)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('checkout_token', $cols)) {
        $pdo->exec("ALTER TABLE events ADD COLUMN checkout_token TEXT");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_checkout_token ON events(checkout_token)");
    }

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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS track_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts INTEGER NOT NULL,
            status_code INTEGER,
            result TEXT,
            origin TEXT,
            user_agent TEXT,
            ip TEXT,
            body_preview TEXT,
            error_msg TEXT
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_track_logs_ts ON track_logs(ts DESC);");

    // ─── Pannello gestionale Shopify (separato dal funnel tracker) ────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shopify_orders (
            id INTEGER PRIMARY KEY,
            order_number TEXT,
            name TEXT,
            email TEXT,
            phone TEXT,
            customer_first_name TEXT,
            customer_last_name TEXT,
            created_at INTEGER,
            cancelled_at INTEGER,
            closed_at INTEGER,
            financial_status TEXT,
            fulfillment_status TEXT,
            subtotal_price REAL,
            total_price REAL,
            total_line_items_price REAL,
            total_discounts REAL,
            total_tax REAL,
            total_shipping REAL,
            current_total_price REAL,
            currency TEXT,
            payment_gateway_names TEXT,
            is_cod INTEGER DEFAULT 0,
            shipping_city TEXT,
            shipping_province TEXT,
            shipping_country TEXT,
            shipping_zip TEXT,
            tags TEXT,
            is_returned INTEGER DEFAULT 0,
            line_items_json TEXT,
            raw_json TEXT,
            synced_at INTEGER NOT NULL,
            shop_updated_at INTEGER
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_so_created ON shopify_orders(created_at);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_so_cod ON shopify_orders(is_cod);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_so_financial ON shopify_orders(financial_status);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_so_fulfillment ON shopify_orders(fulfillment_status);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_so_cancelled ON shopify_orders(cancelled_at);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_so_returned ON shopify_orders(is_returned);");

    // Migrazione: aggiungi delivery_status custom (gestito da noi, non da Shopify)
    $soCols = $pdo->query("PRAGMA table_info(shopify_orders)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('delivery_status', $soCols)) {
        $pdo->exec("ALTER TABLE shopify_orders ADD COLUMN delivery_status TEXT");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_so_delivery ON shopify_orders(delivery_status)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shopify_costs (
            year INTEGER NOT NULL,
            month INTEGER NOT NULL,
            spese_spedizione REAL DEFAULT 0,
            spesa_merce REAL DEFAULT 0,
            spesa_ads REAL DEFAULT 0,
            spesa_influencer REAL DEFAULT 0,
            spesa_team REAL DEFAULT 0,
            spese_varie REAL DEFAULT 0,
            bonifici_brt REAL DEFAULT 0,
            note TEXT,
            updated_at INTEGER,
            PRIMARY KEY (year, month)
        );
    ");

    // Costi giornalieri (entry granulare per data)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shopify_costs_daily (
            date TEXT PRIMARY KEY,
            spese_spedizione REAL DEFAULT 0,
            spesa_merce REAL DEFAULT 0,
            spesa_ads REAL DEFAULT 0,
            spesa_influencer REAL DEFAULT 0,
            spesa_team REAL DEFAULT 0,
            spese_varie REAL DEFAULT 0,
            bonifici_brt REAL DEFAULT 0,
            note TEXT,
            updated_at INTEGER
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_scd_date ON shopify_costs_daily(date);");

    // Migrazione one-shot: copia mensile → giorno 1 del mese (solo se daily è vuota)
    $hasDaily = (int)$pdo->query("SELECT COUNT(*) FROM shopify_costs_daily")->fetchColumn();
    $hasMonthly = (int)$pdo->query("SELECT COUNT(*) FROM shopify_costs")->fetchColumn();
    if ($hasDaily === 0 && $hasMonthly > 0) {
        $rows = $pdo->query("SELECT * FROM shopify_costs WHERE
            spese_spedizione+spesa_merce+spesa_ads+spesa_influencer+spesa_team+spese_varie+bonifici_brt > 0
            OR (note IS NOT NULL AND note != '')")->fetchAll();
        $ins = $pdo->prepare("
            INSERT INTO shopify_costs_daily
            (date, spese_spedizione, spesa_merce, spesa_ads, spesa_influencer, spesa_team, spese_varie, bonifici_brt, note, updated_at)
            VALUES (:d, :sp, :me, :ad, :inf, :tm, :va, :br, :no, :upd)
        ");
        foreach ($rows as $r) {
            $d = sprintf('%04d-%02d-01', (int)$r['year'], (int)$r['month']);
            $ins->execute([
                ':d' => $d,
                ':sp' => (float)$r['spese_spedizione'],
                ':me' => (float)$r['spesa_merce'],
                ':ad' => (float)$r['spesa_ads'],
                ':inf' => (float)$r['spesa_influencer'],
                ':tm' => (float)$r['spesa_team'],
                ':va' => (float)$r['spese_varie'],
                ':br' => (float)$r['bonifici_brt'],
                ':no' => (string)($r['note'] ?? ''),
                ':upd' => time(),
            ]);
        }
    }

    // Catalogo prodotti Shopify (per COGS unit cost)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shopify_products (
            id INTEGER PRIMARY KEY,
            title TEXT,
            handle TEXT,
            status TEXT,
            product_type TEXT,
            image_url TEXT,
            cost_unit REAL DEFAULT 0,
            synced_at INTEGER,
            shop_updated_at INTEGER
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sp_status ON shopify_products(status);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sp_title ON shopify_products(title);");

    // Varianti / bundle (TREUDAS ha 1 prodotto con varie varianti = i bundle)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shopify_variants (
            id INTEGER PRIMARY KEY,
            product_id INTEGER NOT NULL,
            title TEXT,
            sku TEXT,
            price REAL,
            position INTEGER,
            cost_unit REAL DEFAULT 0,
            synced_at INTEGER
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sv_product ON shopify_variants(product_id);");

    // Line items per ordine (per aggregare volume/ordini per prodotto e COGS per ordine)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shopify_order_items (
            order_id INTEGER NOT NULL,
            line_id INTEGER NOT NULL,
            product_id INTEGER,
            variant_id INTEGER,
            title TEXT,
            variant_title TEXT,
            quantity INTEGER,
            price REAL,
            PRIMARY KEY (order_id, line_id)
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_soi_product ON shopify_order_items(product_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_soi_order ON shopify_order_items(order_id);");

    // ═══════════════════════════════════════════════════════════════════
    //  MULTI-STORE — tabella stores + store_settings + colonne store_id
    //  Migrazione non distruttiva: tutto il dato esistente → store #1.
    // ═══════════════════════════════════════════════════════════════════
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            myshopify_domain TEXT,
            public_domain TEXT,
            admin_token TEXT,
            webhook_secret TEXT,
            track_key TEXT,
            currency TEXT NOT NULL DEFAULT 'EUR',
            color TEXT NOT NULL DEFAULT '#f59e0b',
            position INTEGER NOT NULL DEFAULT 0,
            archived INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER
        );
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS store_settings (
            store_id INTEGER NOT NULL,
            key TEXT NOT NULL,
            value TEXT,
            PRIMARY KEY (store_id, key)
        );
    ");

    $hasCol = function(string $t, string $c) use ($pdo): bool {
        foreach ($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (($r['name'] ?? '') === $c) return true;
        }
        return false;
    };

    // Credenziali OAuth per-store (client_id + client_secret dell'app Dev Dashboard)
    foreach (['client_id', 'client_secret'] as $c) {
        if (!$hasCol('stores', $c)) $pdo->exec("ALTER TABLE stores ADD COLUMN $c TEXT");
    }

    // store_id su tutte le tabelle dati (default 1 = store storico)
    foreach (['shopify_orders','shopify_products','shopify_variants','shopify_order_items','sessions','events','orders'] as $t) {
        if (!$hasCol($t, 'store_id')) {
            $pdo->exec("ALTER TABLE $t ADD COLUMN store_id INTEGER NOT NULL DEFAULT 1");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$t}_store ON $t(store_id)");
        }
    }

    // shopify_costs: rebuild con PK (store_id, year, month)
    if (!$hasCol('shopify_costs', 'store_id')) {
        $pdo->exec("ALTER TABLE shopify_costs RENAME TO shopify_costs_old");
        $pdo->exec("CREATE TABLE shopify_costs (
            store_id INTEGER NOT NULL DEFAULT 1, year INTEGER NOT NULL, month INTEGER NOT NULL,
            spese_spedizione REAL DEFAULT 0, spesa_merce REAL DEFAULT 0, spesa_ads REAL DEFAULT 0,
            spesa_influencer REAL DEFAULT 0, spesa_team REAL DEFAULT 0, spese_varie REAL DEFAULT 0,
            bonifici_brt REAL DEFAULT 0, note TEXT, updated_at INTEGER,
            PRIMARY KEY (store_id, year, month)
        )");
        $pdo->exec("INSERT INTO shopify_costs (store_id,year,month,spese_spedizione,spesa_merce,spesa_ads,spesa_influencer,spesa_team,spese_varie,bonifici_brt,note,updated_at)
            SELECT 1,year,month,spese_spedizione,spesa_merce,spesa_ads,spesa_influencer,spesa_team,spese_varie,bonifici_brt,note,updated_at FROM shopify_costs_old");
        $pdo->exec("DROP TABLE shopify_costs_old");
    }

    // shopify_costs_daily: rebuild con PK (store_id, date)
    if (!$hasCol('shopify_costs_daily', 'store_id')) {
        $pdo->exec("ALTER TABLE shopify_costs_daily RENAME TO shopify_costs_daily_old");
        $pdo->exec("CREATE TABLE shopify_costs_daily (
            store_id INTEGER NOT NULL DEFAULT 1, date TEXT NOT NULL,
            spese_spedizione REAL DEFAULT 0, spesa_merce REAL DEFAULT 0, spesa_ads REAL DEFAULT 0,
            spesa_influencer REAL DEFAULT 0, spesa_team REAL DEFAULT 0, spese_varie REAL DEFAULT 0,
            bonifici_brt REAL DEFAULT 0, note TEXT, updated_at INTEGER,
            PRIMARY KEY (store_id, date)
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_scd_date ON shopify_costs_daily(date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_scd_store ON shopify_costs_daily(store_id)");
        $pdo->exec("INSERT INTO shopify_costs_daily (store_id,date,spese_spedizione,spesa_merce,spesa_ads,spesa_influencer,spesa_team,spese_varie,bonifici_brt,note,updated_at)
            SELECT 1,date,spese_spedizione,spesa_merce,spesa_ads,spesa_influencer,spesa_team,spese_varie,bonifici_brt,note,updated_at FROM shopify_costs_daily_old");
        $pdo->exec("DROP TABLE shopify_costs_daily_old");
    }

    // ── MULTI-UTENTE: ogni store appartiene a un utente ──────────────────
    // user_id=0 = "orfano" (dato pre-esistente non ancora rivendicato); il primo
    // utente registrato eredita tutti gli store orfani (vedi tr_user_create()).
    if (!$hasCol('stores', 'user_id')) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stores_user ON stores(user_id)");
        $firstUid = (int)($pdo->query("SELECT MIN(id) FROM users")->fetchColumn() ?: 0);
        if ($firstUid > 0) $pdo->exec("UPDATE stores SET user_id = $firstUid WHERE user_id = 0");
    }
    if (!$hasCol('users', 'name')) $pdo->exec("ALTER TABLE users ADD COLUMN name TEXT");

    // Seed store #1 (TREUDAS storico) + migra chiavi per-store da settings → store_settings
    $nStores = (int)$pdo->query("SELECT COUNT(*) FROM stores")->fetchColumn();
    if ($nStores === 0) {
        $cfg = tracker_config();
        $mysh = ''; $pub = '';
        foreach (($cfg['allowed_origins'] ?? []) as $o) {
            $h = preg_replace('#^https?://#', '', (string)$o);
            if (stripos($h, 'myshopify.com') !== false) { if (!$mysh) $mysh = $h; }
            elseif (stripos($h, 'www.') !== 0)          { if (!$pub)  $pub  = $h; }
        }
        $tok = $pdo->query("SELECT value FROM settings WHERE key='shopify_admin_token'")->fetchColumn() ?: null;
        $sec = $pdo->query("SELECT value FROM settings WHERE key='shopify_webhook_secret'")->fetchColumn();
        if (!$sec) $sec = $cfg['shopify_webhook_secret'] ?? null;
        $pdo->prepare("INSERT INTO stores (id,name,slug,myshopify_domain,public_domain,admin_token,webhook_secret,track_key,currency,color,position,created_at)
            VALUES (1,'TREUDAS','treudas',?,?,?,?,?,'EUR','#f59e0b',0,?)")
            ->execute([$mysh ?: null, $pub ?: null, $tok, ($sec ?: null), bin2hex(random_bytes(8)), time()]);

        $perStoreKeys = ['cogs_shipping_per_order','cogs_return_per_order','cogs_stock_per_order',
            'shopify_sync_updated_at_min','shopify_sync_last_run_at','shopify_sync_last_count',
            'shopify_products_last_sync','shopify_products_last_count','shopify_variants_last_count','shopify_admin_token'];
        $sel  = $pdo->prepare("SELECT value FROM settings WHERE key=?");
        $insS = $pdo->prepare("INSERT OR IGNORE INTO store_settings (store_id,key,value) VALUES (1,?,?)");
        foreach ($perStoreKeys as $k) {
            $sel->execute([$k]); $v = $sel->fetchColumn();
            if ($v !== false) $insS->execute([$k, (string)$v]);
        }
    }
}

// Helper multi-store (sempre disponibili dove è incluso db.php)
require_once __DIR__ . "/stores.php";
