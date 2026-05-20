<?php
/**
 * TREUDAS Tracker — installer
 *
 * Da aprire una sola volta dopo il deploy:
 *   1. Inizializza lo schema SQLite
 *   2. Crea il primo utente admin
 *   3. Si auto-disabilita se esistono già utenti (per evitare riapertura)
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/helpers.php';

tracker_install_schema();
$alreadyInstalled = tr_users_count() > 0;

$err = '';
$ok  = '';

if (!$alreadyInstalled && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = (string)($_POST['email'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Email non valida.';
    } elseif (strlen($pass) < 8) {
        $err = 'La password deve avere almeno 8 caratteri.';
    } elseif ($pass !== $pass2) {
        $err = 'Le password non corrispondono.';
    } else {
        try {
            tr_user_create($email, $pass);
            $ok = 'Admin creato. Reindirizzamento al login…';
            header('Refresh: 1; url=/login.php');
        } catch (Throwable $e) {
            $err = 'Errore creazione utente: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TREUDAS Tracker — Installazione</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="auth">
<main class="auth-card">
    <h1>TREUDAS Tracker</h1>
    <p class="muted">Setup iniziale</p>

    <?php if ($alreadyInstalled): ?>
        <div class="alert alert-ok">
            ✔ Installazione già completata.<br>
            <a href="/login.php">Vai al login →</a>
        </div>
    <?php else: ?>
        <?php if ($err): ?><div class="alert alert-err"><?= tr_h($err) ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="alert alert-ok"><?= tr_h($ok) ?></div><?php endif; ?>

        <form method="post" autocomplete="off">
            <label>Email admin
                <input type="email" name="email" required value="<?= tr_h($_POST['email'] ?? '') ?>">
            </label>
            <label>Password (min 8 caratteri)
                <input type="password" name="password" required minlength="8">
            </label>
            <label>Ripeti password
                <input type="password" name="password2" required minlength="8">
            </label>
            <button type="submit">Crea admin</button>
        </form>

        <p class="muted small">Questa pagina si disabiliterà dopo la creazione del primo utente.</p>
    <?php endif; ?>
</main>
</body>
</html>
