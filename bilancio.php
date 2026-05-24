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

// POST: salva spese di un singolo giorno (AJAX o form)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? 'save_day';
    if ($action === 'save_day') {
        $date = (string)($_POST['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            sh_costs_upsert_day($date, [
                'spese_spedizione' => (float)str_replace(',', '.', (string)($_POST['spese_spedizione'] ?? 0)),
                'spesa_merce'      => (float)str_replace(',', '.', (string)($_POST['spesa_merce'] ?? 0)),
                'spesa_ads'        => (float)str_replace(',', '.', (string)($_POST['spesa_ads'] ?? 0)),
                'spesa_influencer' => (float)str_replace(',', '.', (string)($_POST['spesa_influencer'] ?? 0)),
                'spesa_team'       => (float)str_replace(',', '.', (string)($_POST['spesa_team'] ?? 0)),
                'spese_varie'      => (float)str_replace(',', '.', (string)($_POST['spese_varie'] ?? 0)),
                'bonifici_brt'     => (float)str_replace(',', '.', (string)($_POST['bonifici_brt'] ?? 0)),
                'note'             => (string)($_POST['note'] ?? ''),
            ]);
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'date' => $date]);
                exit;
            }
            $msg = sprintf('✔ Spese salvate per %s.', $date);
        }
    }
}

$view = $_GET['view'] ?? 'month';
$year = (int)($_GET['year'] ?? date('Y'));

$yearsAvail = sh_costs_all_years();
if (!in_array((int)date('Y'), $yearsAvail, true)) $yearsAvail[] = (int)date('Y');
rsort($yearsAvail);

$mesiIt = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
$mesiAbbr = ['','Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];

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
            <input type="hidden" name="view" value="<?= tr_h($view) ?>">
            <?php if (isset($_GET['month'])): ?>
                <input type="hidden" name="month" value="<?= (int)$_GET['month'] ?>">
            <?php endif; ?>
        </div>
    </form>

<?php if ($view === 'day' && isset($_GET['month'])):
    // ── VISTA GIORNALIERA per un mese specifico ──────────────────────
    $month = max(1, min(12, (int)$_GET['month']));
    $daysInMonth = (int)date('t', mktime(0,0,0,$month,1,$year));
    $existing = sh_costs_days_for_month($year, $month);
    $rev = sh_revenue_for_month($year, $month);
    $monthAgg = sh_costs_for_month($year, $month);
    $totalCostsMonth = sh_costs_month_total($monthAgg);
?>

    <section class="panel-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h2 style="margin: 0;">Spese giornaliere — <?= tr_h($mesiIt[$month]) ?> <?= $year ?></h2>
                <small class="muted">
                    Fatturato lordo <strong style="color: var(--pos);">€ <?= number_format((float)$rev['lordo'], 2, ',', '.') ?></strong>
                    · Netto post resi € <?= number_format((float)$rev['netto'], 2, ',', '.') ?>
                    · Totale spese mese € <?= number_format($totalCostsMonth, 2, ',', '.') ?>
                    · <a href="?year=<?= $year ?>" style="color: var(--accent-2);">← Vista mensile</a>
                </small>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <?php for ($mm = 1; $mm <= 12; $mm++): ?>
                    <a href="?view=day&year=<?= $year ?>&month=<?= $mm ?>" class="month-pill <?= $mm === $month ? 'active' : '' ?>"><?= $mesiAbbr[$mm] ?></a>
                <?php endfor; ?>
            </div>
        </div>

        <div class="panel-table-wrap">
            <table class="panel-table daily-costs-table">
                <thead>
                    <tr>
                        <th>Giorno</th>
                        <th class="num">Spedizione</th>
                        <th class="num">Merce</th>
                        <th class="num">Ads</th>
                        <th class="num">Influencer</th>
                        <th class="num">Team</th>
                        <th class="num">Varie</th>
                        <th class="num">BRT</th>
                        <th class="num">Tot</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $tot = ['sp'=>0,'me'=>0,'ad'=>0,'in'=>0,'tm'=>0,'va'=>0,'br'=>0,'tt'=>0];
                for ($d = 1; $d <= $daysInMonth; $d++):
                    $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
                    $row  = $existing[$date] ?? sh_costs_for_day($date);
                    $dayTot = (float)$row['spese_spedizione']
                            + (float)$row['spesa_merce']
                            + (float)$row['spesa_ads']
                            + (float)$row['spesa_influencer']
                            + (float)$row['spesa_team']
                            + (float)$row['spese_varie'];
                    $tot['sp'] += (float)$row['spese_spedizione'];
                    $tot['me'] += (float)$row['spesa_merce'];
                    $tot['ad'] += (float)$row['spesa_ads'];
                    $tot['in'] += (float)$row['spesa_influencer'];
                    $tot['tm'] += (float)$row['spesa_team'];
                    $tot['va'] += (float)$row['spese_varie'];
                    $tot['br'] += (float)$row['bonifici_brt'];
                    $tot['tt'] += $dayTot;
                    $isToday = $date === date('Y-m-d');
                    $isWknd  = in_array((int)date('w', strtotime($date)), [0,6], true);
                ?>
                    <tr class="daily-row <?= $isToday ? 'row-today' : '' ?> <?= $isWknd ? 'row-weekend' : '' ?>" data-date="<?= $date ?>">
                        <td class="day-cell">
                            <strong><?= sprintf('%02d', $d) ?></strong>
                            <small class="muted"><?= ['Dom','Lun','Mar','Mer','Gio','Ven','Sab'][(int)date('w', strtotime($date))] ?></small>
                        </td>
                        <td class="num"><input type="text" data-field="spese_spedizione" value="<?= number_format((float)$row['spese_spedizione'], 2, '.', '') ?>" class="cost-cell"></td>
                        <td class="num"><input type="text" data-field="spesa_merce"      value="<?= number_format((float)$row['spesa_merce'], 2, '.', '') ?>"      class="cost-cell"></td>
                        <td class="num"><input type="text" data-field="spesa_ads"        value="<?= number_format((float)$row['spesa_ads'], 2, '.', '') ?>"        class="cost-cell"></td>
                        <td class="num"><input type="text" data-field="spesa_influencer" value="<?= number_format((float)$row['spesa_influencer'], 2, '.', '') ?>" class="cost-cell"></td>
                        <td class="num"><input type="text" data-field="spesa_team"       value="<?= number_format((float)$row['spesa_team'], 2, '.', '') ?>"       class="cost-cell"></td>
                        <td class="num"><input type="text" data-field="spese_varie"      value="<?= number_format((float)$row['spese_varie'], 2, '.', '') ?>"      class="cost-cell"></td>
                        <td class="num"><input type="text" data-field="bonifici_brt"     value="<?= number_format((float)$row['bonifici_brt'], 2, '.', '') ?>"     class="cost-cell"></td>
                        <td class="num row-total">€ <?= number_format($dayTot, 2, ',', '.') ?></td>
                        <td><input type="text" data-field="note" value="<?= tr_h((string)($row['note'] ?? '')) ?>" class="note-cell" placeholder="…"></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 700;">
                        <td>TOTALI <?= tr_h($mesiAbbr[$month]) ?></td>
                        <td class="num"><?= number_format($tot['sp'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($tot['me'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($tot['ad'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($tot['in'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($tot['tm'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($tot['va'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format($tot['br'], 2, ',', '.') ?></td>
                        <td class="num" style="color: var(--accent-2);">€ <?= number_format($tot['tt'], 2, ',', '.') ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="muted" style="margin-top: 12px; font-size: 12px;">
            Modifica un campo e clicca fuori (o premi Tab/Enter) → salva automaticamente. Bordino verde = ok.
        </p>
    </section>

<?php else:
    // ── VISTA MENSILE aggregata (default) ────────────────────────────
    $monthlyData = [];
    for ($m = 1; $m <= 12; $m++) {
        $costs = sh_costs_for_month($year, $m);
        $rev   = sh_revenue_for_month($year, $m);
        $totCosts = sh_costs_month_total($costs);
        $monthlyData[$m] = [
            'costs'   => $costs,
            'rev'     => $rev,
            'tot_c'   => $totCosts,
            'margin'  => (float)$rev['netto'] - $totCosts,
        ];
    }
    $totals = ['lordo'=>0,'netto'=>0,'sped'=>0,'merce'=>0,'ads'=>0,'inf'=>0,'team'=>0,'varie'=>0,'brt'=>0,'tot_c'=>0,'margin'=>0];
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
?>
    <section class="panel-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
            <h2 style="margin: 0;">Bilancio <?= $year ?></h2>
            <small class="muted">Click su un mese per inserire le spese <strong style="color: var(--accent-2);">giornaliere</strong></small>
        </div>
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
                        <td><a href="?view=day&year=<?= $year ?>&month=<?= $m ?>" class="btn-sm">Giorni →</a></td>
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
<?php endif; ?>

</main>

<script>
// Auto-save AJAX per ogni cella, no <form> annidati (HTML invalido in <tr>)
function fmtEuro(n) {
    return '€ ' + n.toFixed(2).replace('.', ',');
}
function updateRowTotal(row) {
    let tot = 0;
    row.querySelectorAll('.cost-cell').forEach(c => {
        tot += parseFloat(String(c.value).replace(',', '.')) || 0;
    });
    const totCell = row.querySelector('.row-total');
    if (totCell) totCell.textContent = fmtEuro(tot);
}

document.querySelectorAll('tr.daily-row').forEach(row => {
    const date = row.dataset.date;
    const inputs = row.querySelectorAll('input[data-field]');
    inputs.forEach(inp => {
        let last = inp.value;
        const save = async () => {
            if (inp.value === last) return;
            last = inp.value;
            inp.classList.remove('saved', 'error');
            inp.classList.add('saving');

            const fd = new FormData();
            fd.append('action', 'save_day');
            fd.append('date', date);
            inputs.forEach(i => fd.append(i.dataset.field, i.value));

            try {
                const r = await fetch('/bilancio.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const j = await r.json();
                inp.classList.remove('saving');
                if (j.ok) {
                    inp.classList.add('saved');
                    setTimeout(() => inp.classList.remove('saved'), 900);
                    updateRowTotal(row);
                } else {
                    inp.classList.add('error');
                }
            } catch (e) {
                inp.classList.remove('saving');
                inp.classList.add('error');
            }
        };
        inp.addEventListener('blur', save);
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
        });
    });
});
</script>

</body>
</html>
