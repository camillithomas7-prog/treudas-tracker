<?php
/**
 * TREUDAS Tracker — gestione multi-store.
 * Contesto store attivo + helper di lettura/scrittura.
 */

declare(strict_types=1);

/**
 * ID dell'utente loggato (0 se nessuna sessione, es. contesto API/webhook).
 * Tutto lo scoping multi-utente passa da qui.
 */
function tr_uid(): int {
    static $uid = null;
    if ($uid !== null) return $uid;
    if (function_exists('tr_auth_start')) tr_auth_start();
    elseif (session_status() === PHP_SESSION_NONE) { @session_start(); }
    $uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
    return $uid;
}

/** Verifica che uno store appartenga all'utente loggato. */
function tr_store_owned(?array $s): bool {
    return $s && (int)($s['user_id'] ?? 0) === tr_uid();
}

/** Tutti gli store DELL'UTENTE LOGGATO (ordinati). */
function tr_stores_all(bool $includeArchived = false): array {
    $sql = "SELECT * FROM stores WHERE user_id = ?"
         . ($includeArchived ? "" : " AND archived = 0")
         . " ORDER BY position ASC, id ASC";
    $st = tracker_db()->prepare($sql);
    $st->execute([tr_uid()]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tr_store_get(int $id): ?array {
    $st = tracker_db()->prepare("SELECT * FROM stores WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function tr_store_by_slug(string $slug): ?array {
    $st = tracker_db()->prepare("SELECT * FROM stores WHERE slug = ?");
    $st->execute([$slug]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Match per dominio myshopify (usato dal webhook per attribuire l'ordine). */
function tr_store_by_domain(string $domain): ?array {
    $domain = strtolower(trim(preg_replace('#^https?://#', '', $domain)));
    $st = tracker_db()->prepare("SELECT * FROM stores WHERE lower(myshopify_domain) = ? OR lower(public_domain) = ?");
    $st->execute([$domain, $domain]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function tr_store_by_track_key(string $key): ?array {
    if ($key === '') return null;
    $st = tracker_db()->prepare("SELECT * FROM stores WHERE track_key = ?");
    $st->execute([$key]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * ID dello store attivo nel pannello.
 * Risoluzione: ?store=<slug|id>  →  cookie tr_store  →  primo store disponibile.
 */
function tr_store_current_id(): int {
    // Override temporaneo (usato dalla Dashboard per calcolare i KPI di ogni store
    // riusando tutto il motore stats). Vedi tr_store_with().
    if (isset($GLOBALS['__store_override'])) return (int)$GLOBALS['__store_override'];

    static $cur = null;
    if ($cur !== null) return $cur;

    // risolve slug/id SOLO tra gli store dell'utente loggato (blocca ?store=<altro utente>)
    $resolve = function($v): ?int {
        $v = (string)$v;
        if ($v === '') return null;
        $s = ctype_digit($v) ? tr_store_get((int)$v) : tr_store_by_slug($v);
        return tr_store_owned($s) ? (int)$s['id'] : null;
    };

    $id = null;
    if (isset($_GET['store']))            $id = $resolve($_GET['store']);
    if ($id === null && isset($_COOKIE['tr_store'])) $id = $resolve($_COOKIE['tr_store']);
    if ($id === null) {
        $all = tr_stores_all();
        $id  = $all ? (int)$all[0]['id'] : 1;
    }

    // persisti la scelta
    if (!headers_sent() && (isset($_GET['store']) || !isset($_COOKIE['tr_store']))) {
        setcookie('tr_store', (string)$id, time() + 86400 * 365, '/');
        $_COOKIE['tr_store'] = (string)$id;
    }

    $cur = $id;
    return $cur;
}

function tr_store_current(): array {
    return tr_store_get(tr_store_current_id()) ?? [
        'id' => 1, 'name' => 'Store', 'slug' => 'store', 'currency' => 'EUR', 'color' => '#f59e0b',
    ];
}

function tr_store_currency(?int $storeId = null): string {
    $s = tr_store_get($storeId ?? tr_store_current_id());
    return $s['currency'] ?? 'EUR';
}

function tr_store_token(?int $storeId = null): ?string {
    $s = tr_store_get($storeId ?? tr_store_current_id());
    return ($s && !empty($s['admin_token'])) ? (string)$s['admin_token'] : null;
}

/* ── Settings per-store (cogs, cursori sync, ecc.) ──────────────────────── */
function tr_sset_get(string $key, ?int $storeId = null): ?string {
    $sid = $storeId ?? tr_store_current_id();
    $st = tracker_db()->prepare("SELECT value FROM store_settings WHERE store_id = ? AND key = ?");
    $st->execute([$sid, $key]);
    $v = $st->fetchColumn();
    return $v !== false ? (string)$v : null;
}

function tr_sset_set(string $key, string $value, ?int $storeId = null): void {
    $sid = $storeId ?? tr_store_current_id();
    $st = tracker_db()->prepare("
        INSERT INTO store_settings (store_id, key, value) VALUES (?, ?, ?)
        ON CONFLICT(store_id, key) DO UPDATE SET value = excluded.value
    ");
    $st->execute([$sid, $key, $value]);
}

/** Crea o aggiorna uno store. Ritorna l'id. */
function tr_store_upsert(array $d): int {
    $db = tracker_db();
    $slug = $d['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string)($d['name'] ?? 'store'))));
    $slug = trim($slug, '-') ?: 'store';
    $fields = [
        'name'             => (string)($d['name'] ?? 'Store'),
        'myshopify_domain' => ($d['myshopify_domain'] ?? '') !== '' ? strtolower(trim(preg_replace('#^https?://#', '', (string)$d['myshopify_domain']))) : null,
        'public_domain'    => ($d['public_domain'] ?? '') !== '' ? strtolower(trim(preg_replace('#^https?://#', '', (string)$d['public_domain']))) : null,
        'admin_token'      => ($d['admin_token'] ?? '') !== '' ? (string)$d['admin_token'] : null,
        'webhook_secret'   => ($d['webhook_secret'] ?? '') !== '' ? (string)$d['webhook_secret'] : null,
        'client_id'        => ($d['client_id'] ?? '') !== '' ? trim((string)$d['client_id']) : null,
        'client_secret'    => ($d['client_secret'] ?? '') !== '' ? trim((string)$d['client_secret']) : null,
        'currency'         => (string)($d['currency'] ?? 'EUR'),
        'color'            => (string)($d['color'] ?? '#f59e0b'),
        'archived'         => (int)($d['archived'] ?? 0),
    ];

    if (!empty($d['id'])) {
        $id = (int)$d['id'];
        // non sovrascrivere token/secret se lasciati vuoti in update
        $set = []; $args = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, ['admin_token','webhook_secret','client_id','client_secret'], true) && $v === null) continue;
            $set[] = "$k = ?"; $args[] = $v;
        }
        $args[] = $id; $args[] = tr_uid();
        $db->prepare("UPDATE stores SET " . implode(', ', $set) . " WHERE id = ? AND user_id = ?")->execute($args);
        return $id;
    }

    // slug univoco
    $base = $slug; $i = 2;
    while (tr_store_by_slug($slug)) { $slug = $base . '-' . $i; $i++; }
    $pos = (int)$db->query("SELECT COALESCE(MAX(position),0)+1 FROM stores")->fetchColumn();
    $db->prepare("INSERT INTO stores (user_id,name,slug,myshopify_domain,public_domain,admin_token,webhook_secret,client_id,client_secret,track_key,currency,color,position,archived,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([tr_uid(),$fields['name'],$slug,$fields['myshopify_domain'],$fields['public_domain'],$fields['admin_token'],$fields['webhook_secret'],
            $fields['client_id'],$fields['client_secret'],bin2hex(random_bytes(8)),$fields['currency'],$fields['color'],$pos,$fields['archived'],time()]);
    return (int)$db->lastInsertId();
}

/** Esegue $fn calcolando tutto come se lo store attivo fosse $storeId. Ripristina dopo. */
function tr_store_with(int $storeId, callable $fn) {
    $prev = $GLOBALS['__store_override'] ?? null;
    $GLOBALS['__store_override'] = $storeId;
    try { return $fn(); }
    finally {
        if ($prev === null) unset($GLOBALS['__store_override']);
        else $GLOBALS['__store_override'] = $prev;
    }
}
