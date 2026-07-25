<?php
/**
 * Installer — inizializza lo schema SQLite e manda alla registrazione/login.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';

tracker_install_schema();

// nessun utente ancora → crea il primo account; altrimenti login
header('Location: ' . (tr_users_count() === 0 ? '/register.php' : '/login.php'));
exit;
