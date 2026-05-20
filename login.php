<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/helpers.php';

if (tr_users_count() === 0) {
    header('Location: /install.php');
    exit;
}

$err  = '';
$next = $_GET['next'] ?? '/';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = (string)($_POST['email'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    if (tr_auth_login($email, $pass)) {
        $dest = $_POST['next'] ?? '/';
        if (!preg_match('#^/[^/]#', $dest)) $dest = '/';
        header("Location: $dest");
        exit;
    }
    $err = 'Email o password errate.';
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TREUDAS Tracker — Login</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="auth">
<main class="auth-card">
    <h1>TREUDAS Tracker</h1>
    <p class="muted">Accedi alla dashboard</p>

    <?php if ($err): ?><div class="alert alert-err"><?= tr_h($err) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="next" value="<?= tr_h($next) ?>">
        <label>Email
            <input type="email" name="email" required autofocus value="<?= tr_h($_POST['email'] ?? '') ?>">
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button type="submit">Entra</button>
    </form>
</main>
</body>
</html>
