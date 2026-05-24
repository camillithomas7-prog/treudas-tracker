<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/shopify_api.php';
require_once __DIR__ . '/inc/shopify_stats.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');

tracker_install_schema();
date_default_timezone_set(tracker_config()['timezone']);

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $year  = (int)($_POST['year']  ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    if ($year >= 2020 && $year <= 2100 && $month >= 1 && $month <= 12) {
        sh_costs_upsert($year, $month, [
            'spese_spedizione' => (float)str_replace(',', '.', (string)($_POST['spese_spedizione'] ?? 0)),
            'spesa_merce'      => (float)str_replace(',', '.', (string)($_POST['spesa_merce'] ?? 0)),
            'spesa_ads'        => (float)str_replace(',', '.', (string)($_POST['spesa_ads'] ?? 0)),
            'spesa_influencer' => (float)str_replace(',', '.', (string)($_POST['spesa_influencer'] ?? 0)),
            'spesa_team'       => (float)str_replace(',', '.', (string)($_POST['spesa_team'] ?? 0)),
            'spese_varie'      => (float)str_replace(',', '.', (string)($_POST['spese_varie'] ?? 0)),
            'bonifici_brt'     => (float)str_replace(',', '.', (string)($_POST['bonifici_brt'] ?? 0)),
            'note'             => (string)($_POST['note'] ?? ''),
        ]);
        $msg = sprintf('✔ Costi salvati per %02d/%d.', $month, $year);
    }
}

// Anno selezionato
$year = (int)($_GET['year'] ?? date('Y'));
$yearsAvail = [];
$rows = sh_costs_all();
foreach ($rows as $r) $yearsAvail[(int)$r['year']] = true;
$yearsAvail[(int)date('Y')] = true;
$yearsAvail = array_keys($yearsAvail);
rsort($yearsAvail);

$monthlyData = [];
for ($m = 1; $m <= 12; $m++) {
    $costs = sh_costs_for_month($year, $m);
    $rev   = sh_revenue_for_month($year, $m);
    $totCosts = sh_costs_month_total($costs);
    $monthlyData[$m] = [
        'costs'   => $costs,
        'rev'     => $rev,
        'tot_c'   => $totCosts,
        'margin'  => (float)$rev['netto'] - $totCosts - (float)($costs['bonifici_brt'] ?? 0),
    ];
}

$totals = [
    'lordo'   => 0,
    'netto'   => 0,
    'sped'    => 0,
    'merce'   => 0,
    'ads'     => 0,
    'inf'     => 0,
    'team'    => 0,
    'varie'   => 0,
    'brt'     => 0,
    'tot_c'   => 0,
    'margin'  => 0,
];
foreach ($monthlyData as $d) {
    $totals['lordo']  += (float)$d['rev']['lordo'];
    $totals['netto']  += (float)$d['rev']['netto'];
    $totals['sped']   += (float)$d['costs']['spese_spedizione'];
    $totals['merce']  += (float)$d['costs']['spesa_merce'];
    $totals['ads']    += (float)$d['costs']['spesa_ads'];
    $totals['inf']    += (float)$d['costs']['spesa_influencer'];
    $totals['team']   += (float)$d['costs']['spesa_team'];
    $totals['varie']  += (float)$d['costs']['spese_varie'];
    $totals['brt']    += (float)$d['costs']['bonifici_brt'];
    $totals['tot_c']  += (float)$d['tot_c'];
    $totals['margin'] += (float)$d['margin'];
}

$editMonth = (int)($_GET['edit'] ?? 0);
$mesiIt = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];

$panel_active = 'bilancio';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestionale TREUDAS — Bilancio</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/panel.css?v=<?= @filemtime(__DIR__ . '/assets/panel.css') ?>">
</head>
<body>
<?php include __DIR__ . '/inc/panel_header.php'; ?>

<main class="container">

    <?php if ($msg): ?><div class="alert alert-ok"><?= tr_h($msg) ?></div><?php endif; ?>

    <form method="get" class="filters">
        <div class="filter-row">
            <label>Anno
                <select name="year" onchange="this.form.submit()">
                    <?php foreach ($yearsAvail as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </form>

    <section class="panel-section">
        <h2>Bilancio <?= $year ?></h2>
        <div class="panel-table-wrap">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>Mese</th>
                        <th class="num">Fatturato lordo</th>
                        <th class="num">Netto (post resi)</th>
                        <th class="num">Spedizione</th>
                        <th class="num">Merce</th>
                        <th class="num">Ads</th>
                        <th class="num">Influencer</th>
                        <th class="num">Team</th>
                        <th class="num">Varie</th>
                        <th class="num">BRT</th>
                        <th class="num">Tot costi</th>
                        <th class="num">Margine</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php for ($m = 1; $m <= 12; $m++): $d = $monthlyData[$m]; ?>
                    <tr>
                        <td><strong><?= tr_h($mesiIt[$m]) ?></strong></td>
                        <td class="num">€ <?= number_format((float)$d['rev']['lordo'], 2, ',', '.') ?></td>
                        <td class="num">€ <?= number_format((float)$d['rev']['netto'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$d['costs']['spese_spedizione'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$d['costs']['spesa_merce'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$d['costs']['spesa_ads'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$d['costs']['spesa_influencer'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$d['costs']['spesa_team'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$d['costs']['spese_varie'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$d['costs']['bonifici_brt'], 2, ',', '.') ?></td>
                        <td class="num">€ <?= number_format((float)$d['tot_c'], 2, ',', '.') ?></td>
                        <td class="num" style="color: <?= $d['margin'] >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;">
                            € <?= number_format((float)$d['margin'], 2, ',', '.') ?>
                        </td>
                        <td><a href="?year=<?= $year ?>&edit=<?= $m ?>#edit" class="btn-sm">Modifica</a></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 700;">
                        <td>TOTALI <?= $year ?></td>
                        <td class="num">€ <?= number_format($totals['lordo'], 2, ',', '.') ?></td>
                        <td class="num">€ <?= number_format($totals['netto'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($totals['sped'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($totals['merce'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($totals['ads'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($totals['inf'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($totals['team'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($totals['varie'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($totals['brt'], 2, ',', '.') ?></td>
                        <td class="num">€ <?= number_format($totals['tot_c'], 2, ',', '.') ?></td>
                        <td class="num" style="color: <?= $totals['margin'] >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;">
                            € <?= number_format($totals['margin'], 2, ',', '.') ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <?php if ($editMonth >= 1 && $editMonth <= 12): $c = $monthlyData[$editMonth]['costs']; ?>
        <section class="panel-section" id="edit">
            <h2>Modifica costi — <?= tr_h($mesiIt[$editMonth]) ?> <?= $year ?></h2>
            <form method="post" class="cost-form">
                <input type="hidden" name="year"  value="<?= $year ?>">
                <input type="hidden" name="month" value="<?= $editMonth ?>">
                <div class="cost-grid">
                    <label>Spese spedizione (€)
                        <input type="text" name="spese_spedizione" value="<?= number_format((float)$c['spese_spedizione'], 2, '.', '') ?>">
                    </label>
                    <label>Spesa merce (€)
                        <input type="text" name="spesa_merce" value="<?= number_format((float)$c['spesa_merce'], 2, '.', '') ?>">
                    </label>
                    <label>Spesa ads (€)
                        <input type="text" name="spesa_ads" value="<?= number_format((float)$c['spesa_ads'], 2, '.', '') ?>">
                    </label>
                    <label>Spesa influencer / marketing (€)
                        <input type="text" name="spesa_influencer" value="<?= number_format((float)$c['spesa_influencer'], 2, '.', '') ?>">
                    </label>
                    <label>Spesa team (€)
                        <input type="text" name="spesa_team" value="<?= number_format((float)$c['spesa_team'], 2, '.', '') ?>">
                    </label>
                    <label>Spese varie (€)
                        <input type="text" name="spese_varie" value="<?= number_format((float)$c['spese_varie'], 2, '.', '') ?>">
                    </label>
                    <label>Bonifici BRT (€) <small class="muted">(non sottratto dal margine)</small>
                        <input type="text" name="bonifici_brt" value="<?= number_format((float)$c['bonifici_brt'], 2, '.', '') ?>">
                    </label>
                    <label style="grid-column: 1 / -1;">Note
                        <textarea name="note" rows="2"><?= tr_h((string)($c['note'] ?? '')) ?></textarea>
                    </label>
                </div>
                <div style="margin-top: 16px;">
                    <button type="submit">Salva</button>
                    <a href="?year=<?= $year ?>" class="btn-ghost">Annulla</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

</main>
</body>
</html>
