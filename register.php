<?php
/**
 * Registrazione self-service (multi-utente).
 * Chiunque può creare il proprio account e i propri store isolati.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';

tracker_install_schema();
date_default_timezone_set(tracker_config()['timezone']);

if (tr_auth_user()) { header('Location: /'); exit; }

$error = '';
$name  = $_POST['name']  ?? '';
$email = $_POST['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim((string)$name);
    $email = strtolower(trim((string)$email));
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Inserisci un indirizzo email valido.';
    } elseif (strlen($pass) < 8) {
        $error = 'La password deve avere almeno 8 caratteri.';
    } elseif ($pass !== $pass2) {
        $error = 'Le due password non coincidono.';
    } elseif (tr_user_by_email($email)) {
        $error = 'Esiste già un account con questa email. <a href="/login.php">Accedi</a>.';
    } else {
        $uid = tr_user_create($email, $pass, $name);
        // login automatico dopo la registrazione
        tr_auth_start();
        $_SESSION['uid'] = $uid;
        tracker_db()->prepare("UPDATE users SET last_login_at = ? WHERE id = ?")->execute([time(), $uid]);
        header('Location: /stores.php'); exit;   // primo passo: collega uno store
    }
}
?>
<!doctype html>
<html lang="it"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Crea account · Tracker</title>
<?php require __DIR__ . '/inc/auth_styles.php'; ?>
</head><body>
<div class="authwrap">
  <div class="authcard">
    <div class="authlogo">📊 <span>Tracker</span></div>
    <h1>Crea il tuo account</h1>
    <p class="sub">Registrati per monitorare i tuoi store Shopify. I tuoi dati sono privati e separati da quelli degli altri utenti.</p>
    <?php if ($error): ?><div class="err"><?= $error /* può contenere link fidato */ ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <label>Nome <span style="font-weight:400;opacity:.7">(facoltativo)</span>
        <input type="text" name="name" value="<?= htmlspecialchars($name) ?>">
      </label>
      <label>Email
        <input type="email" name="email" required value="<?= htmlspecialchars($email) ?>">
      </label>
      <label>Password
        <input type="password" name="password" required minlength="8">
      </label>
      <div class="hint">Almeno 8 caratteri.</div>
      <label>Conferma password
        <input type="password" name="password2" required minlength="8">
      </label>
      <button type="submit">Crea account</button>
    </form>
    <p class="alt">Hai già un account? <a href="/login.php">Accedi</a></p>
  </div>
</div>
</body></html>
