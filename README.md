# TREUDAS Tracker

Sistema di tracking first-party per il funnel TREUDAS (advertorial → prodotto → acquisto), indipendente da Meta/Facebook Ads Manager. Dashboard PHP + SQLite ospitabile su Hostinger.

## Cosa fa

Misura **esattamente** il funnel di conversione:

1. `advertorial_view` — utente legge l'articolo "Diario di una Mamma"
2. `cta_click` — click su uno dei 6 CTA "Acquista ora"
3. `product_view` — utente arriva su `/products/treudas`
4. `add_to_cart` — submit form carrello
5. `checkout_start` — apertura checkout Shopify
6. `purchase` — ordine pagato (via webhook Shopify, **non falsificabile**)

Tutto attribuito alla campagna pubblicitaria di provenienza (UTM + fbclid/gclid/ttclid).

## Architettura

```
┌─────────────────────────────┐
│  Tema Shopify TREUDAS       │
│  (assets/tracker.js)        │  ──  navigator.sendBeacon
└─────────────────────────────┘                │
                                               ▼
                              ┌─────────────────────────────┐
                              │  track.treudasofficial.com  │
                              │  (questo progetto)          │
                              │  ┌─────────────────────────┐│
                              │  │ /api/track.php          ││ ◄── eventi client
                              │  │ /api/webhook.php        ││ ◄── orders/paid Shopify
                              │  │ /index.php (dashboard)  ││ ◄── tu accedi qui
                              │  │ SQLite data/tracker.db  ││
                              │  └─────────────────────────┘│
                              └─────────────────────────────┘
```

## Struttura repo

```
treudas-tracker/
├── api/
│   ├── track.php       Endpoint eventi client (CORS + sendBeacon)
│   └── webhook.php     Webhook Shopify orders/paid (HMAC verified)
├── inc/
│   ├── db.php          Connessione SQLite + schema
│   ├── auth.php        Login admin (PHP session)
│   ├── helpers.php     Utility (CORS, IP, formatting)
│   └── stats.php       Query funnel / campaign breakdown
├── assets/
│   └── style.css       Stili dashboard (dark theme)
├── theme-snippet/
│   ├── tracker.js              JS da copiare in assets/ del tema Shopify
│   └── INTEGRAZIONE_TEMA.md    Istruzioni passo-passo per il tema
├── data/                       SQLite DB (gitignored, accesso negato via .htaccess)
├── backups/                    backup ZIP/SQL (gitignored)
├── index.php                   Dashboard principale
├── login.php / logout.php
├── install.php                 Setup iniziale (auto-disabled dopo)
├── config.example.php          Template config
├── config.php                  (gitignored — segreti)
└── .htaccess                   HTTPS + sicurezza + CORS
```

## Deploy su Hostinger

### 1. Preparazione locale
```bash
git clone git@github.com:thomaspc/treudas-tracker.git
cd treudas-tracker
cp config.example.php config.php
# Aprire config.php e impostare:
#  - shopify_webhook_secret (dopo aver creato il webhook in Shopify)
#  - cookie_secret (stringa random 32+ char)
```

### 2. Setup su Hostinger
1. **Crea sottodominio**: `track.treudasofficial.com` puntato a una document root tipo `/public_html/track`
2. **Carica i file** via Git pull oppure FTP
3. **Permessi**: `chmod 775 data/ backups/`
4. **Apri**: `https://track.treudasofficial.com/install.php` → crea admin
5. **Cancella o blocca** `install.php` dopo (si auto-disabilita comunque dopo il primo utente)

### 3. Webhook Shopify
- Shopify Admin → Settings → Notifications → Webhooks → Create
- Event: **Order payment**, Format: JSON
- URL: `https://track.treudasofficial.com/api/webhook.php`
- Copia il **secret** mostrato una volta sola → incolla in `config.php → shopify_webhook_secret`

### 4. Integrazione tema
Vedi `theme-snippet/INTEGRAZIONE_TEMA.md` (una riga in `theme.liquid` + copia di `tracker.js`).

## Sicurezza

- `.htaccess` forza HTTPS, blocca accesso a `data/`, `inc/`, `backups/`, `config.php`
- Webhook Shopify verificato con HMAC SHA256
- Password admin hashate con `password_hash()` PASSWORD_DEFAULT
- CORS limitato agli `allowed_origins` configurati
- Cookie sessione `HttpOnly + Secure + SameSite=Lax`

## Privacy / GDPR

- Salva IP, User-Agent, UTM, session UUID (anonimo, non collegato a identità senza ordine)
- Per gli ordini: salva email (necessaria per Shopify)
- Nessun cookie di terze parti
- Nessun fingerprinting avanzato
- Considerare di aggiungere informativa nella privacy policy del sito

## Backup

Lo schema è SQLite single-file. Backup = copia di `data/tracker.db`.
Job cron consigliato su Hostinger:
```bash
0 4 * * * cp ~/public_html/track/data/tracker.db ~/backups/tracker-$(date +\%Y\%m\%d).db
```

## Stack

- PHP 7.4+ / 8.x (testato 8.2)
- SQLite (estensione `pdo_sqlite`, già presente su Hostinger)
- Apache con `mod_rewrite` + `mod_headers`
- Zero dipendenze esterne, niente Composer

## Licenza

Progetto interno TREUDAS / Soprhan — non distribuire.
