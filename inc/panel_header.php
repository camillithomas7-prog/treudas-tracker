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
        <div class="store-switch" style="display:flex;align-items:center;gap:7px;background:var(--bg-card-2,#161b2e);border:1px solid var(--border,#26304a);border-radius:9px;padding:5px 10px;">
            <span style="width:9px;height:9px;border-radius:50%;background:<?= htmlspecialchars($__curColor) ?>;box-shadow:0 0 8px <?= htmlspecialchars($__curColor) ?>;flex:0 0 auto;"></span>
            <select onchange="var u=new URL(location);u.searchParams.set('store',this.value);location=u;"
                    style="background:transparent;border:0;color:var(--text,#e7ecf5);font-size:14px;font-weight:700;cursor:pointer;outline:none;max-width:200px;">
                <?php foreach ($__stores as $s): ?>
                    <option value="<?= htmlspecialchars($s['slug']) ?>" <?= (int)$s['id'] === (int)$__cur['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?> · <?= htmlspecialchars($s['currency']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
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
    <div class="user-info">
        <span><?= date('d M H:i') ?></span>
    </div>
</header>
