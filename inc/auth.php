<?php
/**
 * TREUDAS Tracker — autenticazione admin (sessione PHP)
 */

require_once __DIR__ . '/db.php';

function tr_auth_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $cfg = tracker_config();
        session_set_cookie_params([
            'lifetime' => $cfg['admin_session_ttl'],
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('treudas_tracker_sess');
        session_start();
    }
}

function tr_auth_user(): ?array {
    tr_auth_start();
    if (empty($_SESSION['uid'])) return null;
    $stmt = tracker_db()->prepare("SELECT id, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['uid']]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function tr_auth_require(): array {
    $u = tr_auth_user();
    if (!$u) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header("Location: /login.php?next=$next");
        exit;
    }
    return $u;
}

function tr_auth_login(string $email, string $password): bool {
    $stmt = tracker_db()->prepare("SELECT id, password_hash FROM users WHERE email = ?");
    $stmt->execute([strtolower(trim($email))]);
    $u = $stmt->fetch();
    if (!$u) return false;
    if (!password_verify($password, $u['password_hash'])) return false;
    tr_auth_start();
    $_SESSION['uid'] = (int)$u['id'];
    tracker_db()->prepare("UPDATE users SET last_login_at = ? WHERE id = ?")->execute([time(), $u['id']]);
    return true;
}

function tr_auth_logout(): void {
    tr_auth_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function tr_user_by_email(string $email): ?array {
    $stmt = tracker_db()->prepare("SELECT id, email, name FROM users WHERE email = ?");
    $stmt->execute([strtolower(trim($email))]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function tr_user_create(string $email, string $password, string $name = ''): int {
    $email = strtolower(trim($email));
    $hash  = password_hash($password, PASSWORD_DEFAULT);
    $wasFirst = (tr_users_count() === 0);
    $stmt = tracker_db()->prepare("
        INSERT INTO users (email, password_hash, name, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$email, $hash, ($name !== '' ? $name : null), time()]);
    $uid = (int)tracker_db()->lastInsertId();
    // NIENTE eredità automatica: ogni account nasce VUOTO e collega solo i propri store.
    // (I dati legacy con user_id=0 restano orfani/invisibili finché non li si rivendica a mano.)
    return $uid;
}

function tr_users_count(): int {
    return (int)tracker_db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
}
