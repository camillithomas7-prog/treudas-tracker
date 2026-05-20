<?php
/**
 * TREUDAS Tracker — reset dati (uso manuale)
 * URL: /reset.php  → mostra conferma
 * URL: /reset.php?confirm=YES → cancella tutto
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';

tracker_install_schema();
$db = tracker_db();

$confirm = ($_GET['confirm'] ?? '') === 'YES';
$cleared = false;

if ($confirm) {
    $db->exec("DELETE FROM events");
    $db->exec("DELETE FROM sessions");
    $db->exec("DELETE FROM orders");
    $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('events','orders')");
    $cleared = true;
}

$stats = [
    'sessions' => (int)$db->query("SELECT COUNT(*) FROM sessions")->fetchColumn(),
    'events'   => (int)$db->query("SELECT COUNT(*) FROM events")->fetchColumn(),
    'orders'   => (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
];
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TREUDAS Tracker — Reset dati</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="auth">
<main class="auth-card" style="max-width: 460px;">
    <h1>⚠ Reset dati</h1>
    <p class="muted">Cancella TUTTI i dati di tracking dal database.</p>

    <?php if ($cleared): ?>
        <div class="alert alert-ok">
            ✔ Database azzerato.<br>
            Tutti i dati cancellati: sessioni, eventi, ordini.
        </div>
        <p style="margin-top: 20px;">
            <a href="/" style="color: var(--accent);">→ Torna alla dashboard</a>
        </p>
    <?php else: ?>
        <div class="alert alert-err">
            <strong>Stato attuale:</strong><br>
            • <?= $stats['sessions'] ?> sessioni<br>
            • <?= $stats['events'] ?> eventi<br>
            • <?= $stats['orders'] ?> ordini
        </div>

        <p style="margin-top: 18px;">Questa azione <strong>non è reversibile</strong>. Continuare?</p>

        <form method="get" style="margin-top: 18px;">
            <input type="hidden" name="confirm" value="YES">
            <button type="submit" style="background: #ff6b6b; width: 100%;">
                Sì, cancella tutti i dati
            </button>
        </form>
        <p style="margin-top: 14px; text-align: center;">
            <a href="/" class="muted">← Annulla, torna alla dashboard</a>
        </p>
    <?php endif; ?>
</main>
</body>
</html>
