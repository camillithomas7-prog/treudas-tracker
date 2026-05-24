<?php
/**
 * Header comune pannello gestionale Shopify (ordini/statistiche/costi).
 * Da includere DOPO <body>.
 */
$panel_active = $panel_active ?? '';
?>
<header class="topbar">
    <div class="brand">TREUDAS <span>Gestionale</span></div>
    <nav class="panel-nav">
        <a href="/" class="panel-link">Tracker</a>
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
