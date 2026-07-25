<?php
/**
 * Gestione multi-store: aggiungi / modifica / archivia gli store.
 * Connessione manuale (token Admin API + webhook secret).
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/guard.php';
require_once __DIR__ . '/inc/helpers.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
tracker_install_schema();
date_default_timezone_set(tracker_config()['timezone']);

$msg = ''; $err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? 'save';
    try {
        if ($action === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Il nome dello store è obbligatorio.');
            $id = tr_store_upsert($_POST);
            $msg = '✔ Store «' . $name . '» salvato.';
        } elseif ($action === 'archive') {
            tracker_db()->prepare("UPDATE stores SET archived = 1 WHERE id = ? AND user_id = ?")->execute([(int)$_POST['id'], tr_uid()]);
            $msg = 'Store archiviato.';
        } elseif ($action === 'restore') {
            tracker_db()->prepare("UPDATE stores SET archived = 0 WHERE id = ? AND user_id = ?")->execute([(int)$_POST['id'], tr_uid()]);
            $msg = 'Store ripristinato.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$stores = tr_stores_all(true);
$scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$webhookUrl = $scheme . '://' . $host . '/api/webhook.php';
$trackUrl   = $scheme . '://' . $host . '/api/track.php';
$colors = ['#f59e0b','#2f8bff','#e8467e','#34d399','#a855f7','#ef4444','#14b8a6','#f97316'];
$edit = null;
// solo uno store DELL'UTENTE è modificabile (blocca ?edit=<id altrui>)
if (isset($_GET['edit'])) { $e = tr_store_get((int)$_GET['edit']); if (tr_store_owned($e)) $edit = $e; }
$panel_active = '';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestionale — Store</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/panel.css?v=<?= @filemtime(__DIR__ . '/assets/panel.css') ?>">
<style>
.sm-wrap{max-width:1100px;margin:0 auto;padding:0 22px 60px;}
.sm-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:22px;align-items:start;}
@media(max-width:880px){.sm-grid{grid-template-columns:1fr;}}
.card{background:var(--bg-card,#10131f);border:1px solid var(--border,#26304a);border-radius:16px;padding:20px;}
.card h2{margin:0 0 14px;font-size:17px;}
.fld{display:block;margin-bottom:13px;font-size:13px;color:var(--text-dim,#9aa6bf);}
.fld input,.fld select{width:100%;margin-top:5px;padding:10px 12px;background:var(--bg-card-2,#161b2e);border:1px solid var(--border,#26304a);border-radius:9px;color:var(--text,#e7ecf5);font-size:14px;}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.btn{display:inline-block;padding:11px 20px;border:0;border-radius:10px;background:linear-gradient(135deg,#ffb347,#f59e0b);color:#10131c;font-weight:800;cursor:pointer;font-size:14px;}
.btn.ghost{background:var(--bg-card-2,#161b2e);color:var(--text,#e7ecf5);border:1px solid var(--border,#26304a);}
.alert{padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;}
.alert.ok{background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.35);color:#34d399;}
.alert.err{background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.35);color:#f87171;}
.store-row{display:flex;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid var(--border,#26304a);}
.store-row .dot{width:11px;height:11px;border-radius:50%;flex:0 0 auto;}
.store-row .nm{font-weight:700;}
.store-row .meta{font-size:12px;color:var(--text-dim,#9aa6bf);}
.store-row .act{margin-left:auto;display:flex;gap:8px;}
.act a,.act button{font-size:12px;padding:6px 11px;border-radius:8px;text-decoration:none;border:1px solid var(--border,#26304a);background:var(--bg-card-2,#161b2e);color:var(--text,#e7ecf5);cursor:pointer;}
.swatch{display:flex;gap:7px;margin-top:6px;}
.swatch label{width:24px;height:24px;border-radius:6px;cursor:pointer;border:2px solid transparent;}
.swatch input{display:none;}
.swatch input:checked + span{outline:2px solid #fff;outline-offset:1px;}
.swatch span{display:block;width:22px;height:22px;border-radius:6px;}
code.k{font-size:11px;color:var(--accent-2,#8be9fd);word-break:break-all;}
.hint{font-size:12px;color:var(--text-dim,#9aa6bf);line-height:1.6;margin-top:10px;}
.tag-arch{font-size:10px;color:#9aa6bf;border:1px solid var(--border,#26304a);border-radius:5px;padding:1px 6px;margin-left:6px;}
</style>
</head>
<body>
<?php include __DIR__ . '/inc/panel_header.php'; ?>
<div class="sm-wrap">
    <h1 style="margin:24px 0 6px;">🏬 Gestione store</h1>
    <p class="muted" style="margin-top:0;">Ogni store viene monitorato con la stessa logica (ordini, statistiche, COGS, bilancio). Connessione tramite token Admin API.</p>

    <?php if ($msg): ?><div class="alert ok"><?= tr_h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert err"><?= tr_h($err) ?></div><?php endif; ?>

    <div class="sm-grid">
        <!-- FORM -->
        <div class="card">
            <h2><?= $edit ? 'Modifica store' : 'Aggiungi store' ?></h2>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
                <label class="fld">Nome store
                    <input type="text" name="name" required placeholder="Es. Palasig Digital" value="<?= tr_h($edit['name'] ?? '') ?>">
                </label>
                <div class="row2">
                    <label class="fld">Dominio myshopify
                        <input type="text" name="myshopify_domain" placeholder="xxxxx.myshopify.com" value="<?= tr_h($edit['myshopify_domain'] ?? '') ?>">
                    </label>
                    <label class="fld">Dominio pubblico
                        <input type="text" name="public_domain" placeholder="ilmiostore.com" value="<?= tr_h($edit['public_domain'] ?? '') ?>">
                    </label>
                </div>
                <div class="row2">
                    <label class="fld">Client ID (app Dev Dashboard)
                        <input type="text" name="client_id" placeholder="<?= $edit && !empty($edit['client_id']) ? tr_h($edit['client_id']) : 'es. 0a28fb90…' ?>" value="<?= tr_h($edit['client_id'] ?? '') ?>">
                    </label>
                    <label class="fld">Client Secret (shpss_…)
                        <input type="text" name="client_secret" placeholder="<?= $edit ? '•••• lascia vuoto per non modificare' : 'shpss_...' ?>">
                    </label>
                </div>
                <label class="fld">Webhook secret <span style="opacity:.6">(opzionale, per ordini realtime)</span>
                    <input type="text" name="webhook_secret" placeholder="<?= $edit ? '•••• lascia vuoto per non modificare' : 'secret del webhook' ?>">
                </label>
                <details style="margin-bottom:13px;"><summary style="cursor:pointer;font-size:12px;color:var(--text-dim,#9aa6bf);">Token Admin API manuale (avanzato, se ce l'hai già)</summary>
                    <label class="fld" style="margin-top:8px;">Token Admin API (shpat_…)
                        <input type="text" name="admin_token" placeholder="<?= $edit ? '•••• lascia vuoto per non modificare' : 'shpat_...' ?>">
                    </label>
                </details>
                <div class="row2">
                    <label class="fld">Valuta
                        <select name="currency">
                            <?php foreach (['EUR','USD','GBP'] as $c): ?>
                                <option value="<?= $c ?>" <?= ($edit['currency'] ?? 'EUR') === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="fld">Colore
                        <div class="swatch">
                            <?php foreach ($colors as $c): ?>
                                <label><input type="radio" name="color" value="<?= $c ?>" <?= ($edit['color'] ?? '#f59e0b') === $c ? 'checked' : '' ?>><span style="background:<?= $c ?>;"></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <button class="btn" type="submit"><?= $edit ? 'Salva modifiche' : '+ Crea store' ?></button>
                <?php if ($edit): ?><a class="btn ghost" href="/stores.php">Annulla</a><?php endif; ?>
            </form>

            <div class="hint">
                <strong>Per collegarlo (flusso OAuth, come TREUDAS):</strong><br>
                1. Su <strong>dev.shopify.com</strong> (Dev Dashboard) → crea/usa un'app → versione con scopes <code class="k">read_orders,read_products</code> → <strong>Rilascia</strong>.<br>
                2. Nell'app → <strong>Impostazioni</strong>: copia <strong>ID client</strong> e <strong>Segreto</strong> (<code class="k">shpss_…</code>) → incollali qui sopra, insieme al <strong>dominio myshopify</strong> → <strong>Crea store</strong>.<br>
                3. ⚠️ Sempre nell'app → <strong>Crea versione</strong> → sezione <strong>URL</strong>: metti lo <u>stesso host</u> in <strong>DUE</strong> campi (altrimenti l'errore <em>"redirect_uri and application url must have matching hosts"</em>):<br>
                &nbsp;&nbsp;• <strong>URL app:</strong> <code class="k"><?= tr_h($scheme . '://' . $host) ?></code><br>
                &nbsp;&nbsp;• <strong>URL di reindirizzamento:</strong> <code class="k"><?= tr_h($scheme . '://' . $host . '/oauth_callback.php') ?></code><br>
                &nbsp;&nbsp;→ <strong>Rilascia</strong> la versione.<br>
                4. Poi qui a destra clicca <strong>Connetti</strong> sullo store → autorizza su Shopify → il token viene salvato in automatico. ✅<br>
                <span style="opacity:.7">Webhook (opzionale, realtime): <code class="k">Order payment</code> JSON → URL <code class="k"><?= tr_h($webhookUrl) ?></code>. Funnel tracker (opzionale): endpoint <code class="k"><?= tr_h($trackUrl) ?></code> con la <code class="k">track_key</code>.</span>
            </div>
        </div>

        <!-- LISTA -->
        <div class="card">
            <h2>Store collegati (<?= count(array_filter($stores, fn($s)=>!$s['archived'])) ?>)</h2>
            <?php foreach ($stores as $s): ?>
            <div class="store-row" style="<?= $s['archived'] ? 'opacity:.5;' : '' ?>">
                <span class="dot" style="background:<?= tr_h($s['color']) ?>;"></span>
                <div>
                    <div class="nm"><?= tr_h($s['name']) ?> <span class="meta"><?= tr_h($s['currency']) ?></span>
                        <?php if ($s['archived']): ?><span class="tag-arch">archiviato</span><?php endif; ?>
                    </div>
                    <div class="meta"><?= tr_h($s['myshopify_domain'] ?: '—') ?> · <?= !empty($s['admin_token']) ? '<span style="color:#34d399">connesso ✓</span>' : (!empty($s['client_id']) ? '<span style="color:#fbbf24">pronto, da connettere</span>' : 'da configurare') ?> · key <code class="k"><?= tr_h($s['track_key']) ?></code></div>
                </div>
                <div class="act">
                    <?php if (!$s['archived'] && !empty($s['client_id']) && !empty($s['myshopify_domain'])): ?>
                        <a href="/oauth_start.php?store=<?= (int)$s['id'] ?>" style="background:linear-gradient(135deg,#ffb347,#f59e0b);color:#10131c;font-weight:800;border-color:transparent;"><?= !empty($s['admin_token']) ? 'Riconnetti' : 'Connetti' ?></a>
                    <?php endif; ?>
                    <a href="/statistiche.php?store=<?= tr_h($s['slug']) ?>">Apri</a>
                    <a href="/stores.php?edit=<?= (int)$s['id'] ?>">Modifica</a>
                    <?php if ($s['archived']): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button>Ripristina</button></form>
                    <?php elseif ((int)$s['id'] !== 1): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Archiviare questo store? I dati restano salvati.')"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button>Archivia</button></form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <p class="hint">Il token e il secret non vengono mai mostrati: per cambiarli incolla i nuovi valori nel form; lasciali vuoti per mantenerli.</p>
        </div>
    </div>
</div>
</body>
</html>
