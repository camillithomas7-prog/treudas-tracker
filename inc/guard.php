<?php
/**
 * Guard di autenticazione per le pagine del pannello.
 * Includere in cima a ogni pagina protetta (subito dopo declare()).
 * NON includere negli endpoint api/ (chiamati da store esterni senza sessione).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
tracker_install_schema();           // garantisce che users/stores esistano al primo avvio
tr_auth_require();                  // redirect a /login.php se non loggato
