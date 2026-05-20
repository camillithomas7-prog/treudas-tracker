<?php
/**
 * TREUDAS Tracker — installer (modalità senza-login)
 * Inizializza lo schema SQLite e reindirizza alla dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

tracker_install_schema();

header('Location: /');
exit;
