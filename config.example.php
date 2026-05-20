<?php
/**
 * TREUDAS Tracker — configurazione
 *
 * COPIARE questo file in `config.php` e personalizzarlo.
 * `config.php` è in .gitignore (contiene segreti).
 */

return [

    // Percorso del database SQLite (relativo alla root del progetto)
    'db_path' => __DIR__ . '/data/tracker.db',

    // Segreto webhook Shopify (Settings → Notifications → Webhooks → Reveal secret)
    // Necessario per verificare l'autenticità delle chiamate `orders/paid`
    'shopify_webhook_secret' => 'INSERISCI_SECRET_SHOPIFY_QUI',

    // Origini autorizzate a inviare eventi (CORS)
    // Aggiungere il dominio dello store Shopify + eventuali domini custom
    'allowed_origins' => [
        'https://jdtzzb-7b.myshopify.com',
        'https://treudasofficial.com',
        'https://www.treudasofficial.com',
    ],

    // Fuso orario per la dashboard
    'timezone' => 'Europe/Rome',

    // Durata sessione admin (secondi)
    'admin_session_ttl' => 86400 * 7, // 7 giorni

    // Token segreto firma cookie admin (cambiare al primo deploy)
    'cookie_secret' => 'CAMBIARE_QUESTA_STRINGA_SUBITO_random_32+_chars',

    // Debug (true solo in locale, MAI in produzione)
    'debug' => false,
];
