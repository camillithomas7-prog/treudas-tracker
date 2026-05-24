<?php
/**
 * TREUDAS Gestionale — Guida setup integrata
 *
 * Step-by-step per collegare uno store Shopify: app custom, OAuth,
 * webhook, snippet tracker tema. Mostra lo stato attuale di ogni step
 * così l'utente vede a colpo d'occhio cosa ha già fatto.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/shopify_api.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');

tracker_install_schema();
date_default_timezone_set(tracker_config()['timezone']);

function s_setting(string $k): ?string {
    $stmt = tracker_db()->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$k]);
    $v = $stmt->fetchColumn();
    return ($v !== false && $v !== '') ? (string)$v : null;
}

// Stato corrente
$clientSecret   = s_setting('shopify_app_client_secret');
$adminToken     = s_setting('shopify_admin_token');
$tokenSaved     = s_setting('shopify_admin_token_saved_at');
$webhookSecret  = s_setting('shopify_webhook_secret');
$lastOrderSync  = s_setting('shopify_sync_last_run_at');
$lastProdSync   = s_setting('shopify_products_last_sync');
$nOrders        = (int)tracker_db()->query("SELECT COUNT(*) FROM shopify_orders")->fetchColumn();
$nProducts      = (int)tracker_db()->query("SELECT COUNT(*) FROM shopify_products")->fetchColumn();
$nVariants      = (int)tracker_db()->query("SELECT COUNT(*) FROM shopify_variants")->fetchColumn();
$nTrackEvents   = (int)tracker_db()->query("SELECT COUNT(*) FROM events")->fetchColumn();
$nPurchaseEvts  = (int)tracker_db()->query("SELECT COUNT(*) FROM orders")->fetchColumn();

$host           = $_SERVER['HTTP_HOST'] ?? 'lemonchiffon-lion-484144.hostingersite.com';
$base           = 'https://' . $host;
$callbackUrl    = $base . '/oauth_callback.php';
$webhookUrl     = $base . '/api/webhook.php';
$trackUrl       = $base . '/api/track.php';

$step1 = !!$clientSecret;
$step2 = !!$adminToken;
$step3 = !!$webhookSecret;
$step4 = $nOrders > 0 || $nProducts > 0;
$step5 = $nTrackEvents > 0;

$completed = (int)$step1 + (int)$step2 + (int)$step3 + (int)$step4 + (int)$step5;

$panel_active = 'setup';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestionale TREUDAS — Guida Setup</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/panel.css?v=<?= @filemtime(__DIR__ . '/assets/panel.css') ?>">
</head>
<body>
<?php include __DIR__ . '/inc/panel_header.php'; ?>

<main class="container">

    <div class="page-title">
        <h1>Guida Setup Shopify</h1>
        <p class="muted">Tutti i passaggi per collegare uno store Shopify al gestionale · <?= $completed ?>/5 completati</p>
    </div>

    <div class="setup-progress">
        <div class="setup-progress-bar" style="width: <?= ($completed / 5) * 100 ?>%;"></div>
    </div>

    <!-- STEP 1 — App Shopify -->
    <section class="setup-step <?= $step1 ? 'done' : 'todo' ?>">
        <header class="setup-step-head">
            <div class="setup-step-num"><?= $step1 ? '✓' : '1' ?></div>
            <div>
                <h2>Crea l'app custom sul Dev Dashboard</h2>
                <p class="muted">Una sola volta per store. Genera Client ID e Client Secret necessari per OAuth.</p>
            </div>
            <span class="setup-status <?= $step1 ? 'ok' : 'pending' ?>"><?= $step1 ? 'Configurato' : 'Da fare' ?></span>
        </header>
        <div class="setup-step-body">
            <ol>
                <li>Apri il <a href="https://dev.shopify.com/dashboard" target="_blank" rel="noopener">Dev Dashboard Shopify</a> e accedi con l'account proprietario dello store.</li>
                <li>Clicca <strong>Create app</strong> → scegli un nome (es. "Gestionale [Brand]") → <em>Create app</em>.</li>
                <li>Vai su <strong>Versions</strong> → <em>Create version</em> e compila:
                    <ul>
                        <li><strong>Scopes</strong> (Access → Scopes), copia/incolla esattamente:
                            <pre class="setup-code">read_all_orders,read_customers,read_fulfillments,read_inventory,read_orders,read_products,read_returns,read_shipping</pre>
                        </li>
                        <li><strong>URL app</strong>:
                            <pre class="setup-code"><?= tr_h($base) ?>/</pre>
                        </li>
                        <li><strong>URL di reindirizzamento</strong> (Redirect URLs):
                            <pre class="setup-code"><?= tr_h($callbackUrl) ?></pre>
                        </li>
                        <li>Togli la spunta su <strong>"Incorpora l'app nel pannello Shopify"</strong> (non serve)</li>
                    </ul>
                </li>
                <li>In fondo clicca <strong>Save and release</strong> → conferma il rilascio.</li>
                <li>Vai su <strong>Settings</strong> → sezione <em>Credentials</em> → clicca <em>l'occhio</em> accanto a <strong>Secret</strong> per rivelare il Client Secret (formato <code>shpss_...</code>).</li>
                <li>Apri <a href="/shopify_oauth.php">/shopify_oauth.php</a> e incollalo nel campo, poi clicca <strong>Salva Client Secret</strong>.</li>
            </ol>
            <?php if ($step1): ?>
                <div class="setup-success">✓ Client Secret salvato: <code><?= tr_h(substr($clientSecret, 0, 12) . '••••' . substr($clientSecret, -4)) ?></code></div>
            <?php endif; ?>
        </div>
    </section>

    <!-- STEP 2 — OAuth install -->
    <section class="setup-step <?= $step2 ? 'done' : 'todo' ?>">
        <header class="setup-step-head">
            <div class="setup-step-num"><?= $step2 ? '✓' : '2' ?></div>
            <div>
                <h2>Installa l'app sullo store e ottieni l'Admin API token</h2>
                <p class="muted">Flusso OAuth: ti porta nell'admin Shopify, confermi i permessi, torni qui con il token salvato.</p>
            </div>
            <span class="setup-status <?= $step2 ? 'ok' : 'pending' ?>"><?= $step2 ? 'Connesso' : 'Da fare' ?></span>
        </header>
        <div class="setup-step-body">
            <ol>
                <li>Assicurati di essere <strong>già loggato nell'admin dello store</strong> Shopify (altrimenti Shopify ti reindirizza al profilo account invece che alla pagina di install).
                    <br><small class="muted">Apri prima <code>https://NOMESTORE.myshopify.com/admin</code> in un'altra tab.</small>
                </li>
                <li>Vai su <a href="/shopify_oauth.php">/shopify_oauth.php</a> e clicca il bottone arancione <strong>→ Connetti</strong>.</li>
                <li>Conferma <em>Install app</em> nella schermata Shopify che si apre — vedrai elencati i permessi richiesti.</li>
                <li>Torni automaticamente qui con il messaggio <em>Admin Token salvato</em>.</li>
            </ol>
            <?php if ($step2): ?>
                <div class="setup-success">
                    ✓ Token Admin attivo: <code><?= tr_h(substr($adminToken, 0, 10) . '••••' . substr($adminToken, -4)) ?></code>
                    <?php if ($tokenSaved): ?> · salvato il <?= tr_h(date('d/m/Y H:i', (int)$tokenSaved)) ?><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- STEP 3 — Webhook ordini -->
    <section class="setup-step <?= $step3 ? 'done' : 'todo' ?>">
        <header class="setup-step-head">
            <div class="setup-step-num"><?= $step3 ? '✓' : '3' ?></div>
            <div>
                <h2>Configura webhook Order paid (per tracking real-time)</h2>
                <p class="muted">Serve al tracker advertorial per collegare ogni acquisto alla sessione che l'ha generato. Senza questo, la dashboard /index.php non vede gli acquisti.</p>
            </div>
            <span class="setup-status <?= $step3 ? 'ok' : 'pending' ?>"><?= $step3 ? 'Attivo' : 'Da fare' ?></span>
        </header>
        <div class="setup-step-body">
            <ol>
                <li>Nell'admin Shopify dello store: <strong>Settings</strong> → <strong>Notifications</strong>.</li>
                <li>Scorri in fondo fino a <strong>Webhooks</strong> → clicca <em>Create webhook</em>.</li>
                <li>Compila così:
                    <ul>
                        <li>Event: <code>Order payment</code></li>
                        <li>Format: <code>JSON</code></li>
                        <li>URL: <pre class="setup-code"><?= tr_h($webhookUrl) ?></pre></li>
                        <li>Webhook API version: <code>2024-10</code> (o successiva)</li>
                    </ul>
                </li>
                <li>Clicca <em>Save</em> → Shopify ti mostra una <strong>chiave segreta</strong> per la verifica HMAC (compare in cima alla sezione Webhooks, una sola volta).</li>
                <li>Copia quella chiave e incollala nella pagina <a href="/settings.php">/settings.php</a> del gestionale → salva.</li>
            </ol>
            <?php if ($step3): ?>
                <div class="setup-success">✓ Webhook secret salvato: <code><?= tr_h(substr($webhookSecret, 0, 6) . '••••' . substr($webhookSecret, -4)) ?></code></div>
                <p class="muted" style="margin-top:8px; font-size:12px;">Per verificare che arrivino davvero gli eventi: <a href="/webhook_logs.php">/webhook_logs.php</a></p>
            <?php endif; ?>
        </div>
    </section>

    <!-- STEP 4 — Primo sync -->
    <section class="setup-step <?= $step4 ? 'done' : 'todo' ?>">
        <header class="setup-step-head">
            <div class="setup-step-num"><?= $step4 ? '✓' : '4' ?></div>
            <div>
                <h2>Primo sync ordini e catalogo</h2>
                <p class="muted">Importa ordini storici e catalogo prodotti/varianti per popolare statistiche e COGS.</p>
            </div>
            <span class="setup-status <?= $step4 ? 'ok' : 'pending' ?>"><?= $step4 ? 'Dati presenti' : 'Da fare' ?></span>
        </header>
        <div class="setup-step-body">
            <ol>
                <li>Apri <a href="/ordini.php?sync=full">/ordini.php?sync=full</a> per scaricare <strong>tutti gli ordini storici</strong>. Tempo: 1-5 minuti per qualche migliaio di ordini.</li>
                <li>Apri <a href="/costi.php">/costi.php</a> e clicca <em>↻ Sync catalogo</em> → scarica prodotti e varianti (bundle).</li>
                <li>Setta i <strong>costi logistici drop shipping</strong> sulla stessa pagina:
                    <ul>
                        <li>Spedizione (€/ordine): quanto paghi al fornitore per ogni spedizione</li>
                        <li>Perdita rientro (€/ordine rientrato): quota persa quando il cliente non ritira</li>
                    </ul>
                </li>
                <li>Per ogni <strong>variante/bundle</strong> nel catalogo, inserisci il <em>Costo nostro (€)</em> = quanto ti costa quella specifica offerta dal fornitore. Il salvataggio è automatico al click fuori dal campo.</li>
                <li>Le <strong>spese variabili giornaliere</strong> (Ads, Team, Influencer, Varie) si inseriscono giorno per giorno in <a href="/bilancio.php?view=day&year=<?= date('Y') ?>&month=<?= date('n') ?>">/bilancio.php</a>.</li>
            </ol>
            <?php if ($step4): ?>
                <div class="setup-success">
                    ✓ <?= number_format($nOrders, 0, ',', '.') ?> ordini ·
                    <?= number_format($nProducts, 0, ',', '.') ?> prodotti ·
                    <?= number_format($nVariants, 0, ',', '.') ?> varianti sincronizzati
                    <?php if ($lastOrderSync): ?><br><small class="muted">Ultimo sync ordini: <?= tr_h(date('d/m/Y H:i', (int)$lastOrderSync)) ?></small><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- STEP 5 — Tracker tema -->
    <section class="setup-step <?= $step5 ? 'done' : 'todo' ?>">
        <header class="setup-step-head">
            <div class="setup-step-num"><?= $step5 ? '✓' : '5' ?></div>
            <div>
                <h2>Installa lo snippet tracker sul tema Shopify</h2>
                <p class="muted">Tracciamento first-party del funnel advertorial → carrello → acquisto. Necessario per <a href="/">/index.php</a> e attribuzione campagne.</p>
            </div>
            <span class="setup-status <?= $step5 ? 'ok' : 'pending' ?>"><?= $step5 ? 'Eventi in arrivo' : 'Da fare' ?></span>
        </header>
        <div class="setup-step-body">
            <ol>
                <li>Scarica il file <code>theme-snippet/tracker.js</code> dal repository del progetto (o copialo dalla cartella <code>~/treudas-tracker/theme-snippet/</code> in locale).</li>
                <li>Nel tema Shopify: <strong>Online Store</strong> → <em>Themes</em> → tema attivo → <strong>Edit code</strong>.</li>
                <li>Carica il file in <strong>assets/tracker.js</strong> (usa <em>Add a new asset</em> → seleziona il file).</li>
                <li>Apri <strong>layout/theme.liquid</strong> e aggiungi <u>poco prima di <code>&lt;/head&gt;</code></u>:
                    <pre class="setup-code">&lt;script src="{{ 'tracker.js' | asset_url }}" defer&gt;&lt;/script&gt;</pre>
                </li>
                <li>Apri il <strong>Custom Pixel</strong> Shopify (Settings → Customer events → Add custom pixel) e incolla il codice da <code>theme-snippet/shopify-customer-pixel.js</code>. Serve a inviare <code>checkout_start</code> e <code>thank_you_view</code>.</li>
                <li>Verifica che gli eventi arrivino: <a href="/track_logs.php">/track_logs.php</a> deve mostrare chiamate POST con status 200.</li>
            </ol>
            <?php if ($step5): ?>
                <div class="setup-success">
                    ✓ <?= number_format($nTrackEvents, 0, ',', '.') ?> eventi tracker ·
                    <?= number_format($nPurchaseEvts, 0, ',', '.') ?> acquisti collegati a sessione
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Risorse utili -->
    <section class="setup-step">
        <header class="setup-step-head">
            <div class="setup-step-num">📌</div>
            <div>
                <h2>Risorse e link rapidi</h2>
                <p class="muted">Tutte le pagine del gestionale a portata di click.</p>
            </div>
        </header>
        <div class="setup-step-body">
            <div class="setup-links">
                <a href="/shopify_oauth.php" class="setup-link"><strong>OAuth Shopify</strong><br><small class="muted">Connetti / disconnetti store</small></a>
                <a href="/settings.php" class="setup-link"><strong>Webhook secret</strong><br><small class="muted">Imposta HMAC verifica</small></a>
                <a href="/ordini.php" class="setup-link"><strong>Ordini</strong><br><small class="muted">Lista + sync</small></a>
                <a href="/statistiche.php" class="setup-link"><strong>Statistiche</strong><br><small class="muted">P&amp;L · CPA · ROAS</small></a>
                <a href="/costi.php" class="setup-link"><strong>Costi &amp; COGS</strong><br><small class="muted">Bundle, spedizione</small></a>
                <a href="/bilancio.php" class="setup-link"><strong>Bilancio</strong><br><small class="muted">Spese giornaliere</small></a>
                <a href="/track_logs.php" class="setup-link"><strong>Log tracker</strong><br><small class="muted">Debug eventi JS</small></a>
                <a href="/webhook_logs.php" class="setup-link"><strong>Log webhook</strong><br><small class="muted">Debug HMAC Shopify</small></a>
                <a href="/" class="setup-link"><strong>Tracker funnel</strong><br><small class="muted">Dashboard advertorial</small></a>
            </div>
        </div>
    </section>

</main>
</body>
</html>
