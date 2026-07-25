<?php
/**
 * Header comune pannello gestionale (multi-store).
 * Da includere DOPO <body>. Mostra selettore store + nav.
 */
$panel_active = $panel_active ?? '';
$__stores = function_exists('tr_stores_all') ? tr_stores_all() : [];
$__cur    = function_exists('tr_store_current') ? tr_store_current() : ['id' => 0, 'name' => '—', 'slug' => '', 'color' => '#f59e0b', 'currency' => 'EUR'];
$__curColor = $__cur['color'] ?? '#f59e0b';
?>
<header class="topbar">
    <div class="brand" style="display:flex;align-items:center;gap:14px;">
        <span style="opacity:.6;font-weight:700;">Gestionale</span>
        <?php if ($panel_active !== 'dashboard' && !empty($__stores)): ?>
        <details class="storesw">
            <summary>
                <span class="ss-dot" style="background:<?= htmlspecialchars($__curColor) ?>;box-shadow:0 0 8px <?= htmlspecialchars($__curColor) ?>;"></span>
                <span class="ss-cur"><?= htmlspecialchars($__cur['name']) ?> · <?= htmlspecialchars($__cur['currency']) ?></span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="ss-chev"><path d="m6 9 6 6 6-6"></path></svg>
            </summary>
            <div class="ss-menu">
                <?php foreach ($__stores as $s): $on = (int)$s['id'] === (int)$__cur['id']; ?>
                    <a href="?store=<?= htmlspecialchars($s['slug']) ?>" class="<?= $on ? 'on' : '' ?>">
                        <span class="ss-dot" style="background:<?= htmlspecialchars($s['color']) ?>;"></span>
                        <span><?= htmlspecialchars($s['name']) ?> · <?= htmlspecialchars($s['currency']) ?></span>
                        <?php if ($on): ?><span class="ss-ck">✓</span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <a href="/stores.php" class="ss-add">+ Gestisci store</a>
            </div>
        </details>
        <style>
            .storesw{position:relative;}
            .storesw>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:8px;background:var(--bg-card-2,#161b2e);border:1px solid var(--border,#26304a);border-radius:9px;padding:6px 11px;font-weight:700;font-size:14px;color:var(--text,#e7ecf5);}
            .storesw>summary::-webkit-details-marker{display:none;}
            .storesw .ss-dot{width:9px;height:9px;border-radius:50%;flex:0 0 auto;}
            .storesw .ss-chev{opacity:.6;transition:transform .15s;}
            .storesw[open] .ss-chev{transform:rotate(180deg);}
            .storesw .ss-menu{position:absolute;top:calc(100% + 6px);left:0;min-width:215px;background:var(--bg-card,#10131f);border:1px solid var(--border,#26304a);border-radius:11px;padding:6px;z-index:200;box-shadow:0 16px 40px rgba(0,0,0,.5);}
            .storesw .ss-menu a{display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:8px;text-decoration:none;color:var(--text,#e7ecf5);font-size:14px;font-weight:600;}
            .storesw .ss-menu a:hover{background:var(--bg-card-2,#161b2e);}
            .storesw .ss-menu a.on{background:rgba(245,158,11,.12);}
            .storesw .ss-menu a .ss-ck{margin-left:auto;color:var(--accent,#f59e0b);}
            .storesw .ss-add{margin-top:4px;border-top:1px solid var(--border,#26304a);border-radius:0 0 8px 8px;color:var(--text-dim,#9aa6bf)!important;font-weight:500!important;font-size:13px!important;}
        </style>
        <?php endif; ?>
    </div>
    <nav class="panel-nav">
        <a href="/dashboard.php"   class="panel-link <?= $panel_active==='dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="/"                class="panel-link">Tracker</a>
        <a href="/ordini.php"      class="panel-link <?= $panel_active==='ordini' ? 'active' : '' ?>">Ordini</a>
        <a href="/statistiche.php" class="panel-link <?= $panel_active==='statistiche' ? 'active' : '' ?>">Statistiche</a>
        <a href="/costi.php"       class="panel-link <?= $panel_active==='costi' ? 'active' : '' ?>">Costi</a>
        <a href="/bilancio.php"    class="panel-link <?= $panel_active==='bilancio' ? 'active' : '' ?>">Bilancio</a>
        <a href="/setup.php"       class="panel-link <?= $panel_active==='setup' ? 'active' : '' ?>">Setup</a>
    </nav>
    <div class="user-info" style="display:flex;align-items:center;gap:12px;">
        <span style="opacity:.6;font-size:13px;"><?= date('d M H:i') ?></span>
        <?php $__u = function_exists('tr_auth_user') ? tr_auth_user() : null; if ($__u): ?>
        <details class="usermenu" style="position:relative;">
            <summary style="list-style:none;cursor:pointer;display:flex;align-items:center;gap:7px;background:var(--bg-card-2,#161b2e);border:1px solid var(--border,#26304a);border-radius:9px;padding:6px 10px;font-size:13px;font-weight:700;color:var(--text,#e7ecf5);">
                <span style="width:22px;height:22px;border-radius:50%;background:var(--accent,#f59e0b);color:#1a1200;display:grid;place-items:center;font-weight:800;font-size:12px;"><?= strtoupper(substr((string)($__u['name'] ?: $__u['email']),0,1)) ?></span>
                <span style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($__u['name'] ?: $__u['email']) ?></span>
            </summary>
            <div style="position:absolute;top:calc(100% + 6px);right:0;min-width:190px;background:var(--bg-card,#10131f);border:1px solid var(--border,#26304a);border-radius:11px;padding:6px;z-index:200;box-shadow:0 16px 40px rgba(0,0,0,.5);">
                <div style="padding:8px 11px;font-size:12px;color:var(--muted,#9aa4bd);border-bottom:1px solid var(--border,#26304a);margin-bottom:4px;word-break:break-all;"><?= htmlspecialchars($__u['email']) ?></div>
                <a href="/stores.php" style="display:block;padding:8px 11px;border-radius:8px;text-decoration:none;color:var(--text,#e7ecf5);font-size:14px;font-weight:600;">I miei store</a>
                <a href="/logout.php" style="display:block;padding:8px 11px;border-radius:8px;text-decoration:none;color:#fca5a5;font-size:14px;font-weight:600;">Esci</a>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <style>.usermenu>summary::-webkit-details-marker{display:none;} .usermenu div a:hover{background:var(--bg-card-2,#161b2e);}</style>
</header>
