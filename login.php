<?php
/**
 * Login utente (multi-utente).
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';

tracker_install_schema();
date_default_timezone_set(tracker_config()['timezone']);

// già loggato → dashboard
if (tr_auth_user()) { header('Location: /'); exit; }

$next  = $_GET['next'] ?? '/';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string)($_POST['email'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    if (tr_auth_login($email, $pass)) {
        $dest = (string)($_POST['next'] ?? '/');
        if ($dest === '' || $dest[0] !== '/') $dest = '/';   // solo redirect interni
        header('Location: ' . $dest); exit;
    }
    $error = 'Email o password non corretti.';
}
?>
<!doctype html>
<html lang="it"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accedi · Tracker</title>
<?php require __DIR__ . '/inc/auth_styles.php'; ?>
</head><body>
<div class="authwrap">
  <div class="authcard">
    <div class="authlogo">📊 <span>Tracker</span></div>
    <h1>Accedi</h1>
    <p class="sub">Entra nel tuo account per vedere i tuoi store.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
      <label>Email
        <input type="email" name="email" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <button type="submit">Accedi</button>
    </form>
    <p class="alt">Non hai un account? <a href="/register.php">Registrati</a></p>
  </div>
</div>
</body></html>
