<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$viewer = require_stats_access();
$filter = parse_stats_filter($_GET);
$today = new DateTimeImmutable('today');
$total = stats_total($filter);
$weeks = period_weeks($filter, $total, $today);
$byCategory = stats_grouped('category', CONTACT_CATEGORIES, $filter);
$byOutcome = stats_grouped('outcome', OUTCOMES, $filter);
$byReason = stats_grouped('cancel_reason', CANCEL_REASONS, $filter);
$byFollowUp = stats_grouped('follow_up', FOLLOW_UPS, $filter);
$byUser = stats_by_user($filter);
$quotes = impressions($filter, 30);

$n = (int) $total['n'];
$reached = (int) $total['reached'];
$minutes = total_minutes($total);
$cancelRows = array_intersect_key($byCategory, array_flip(CANCEL_CATEGORIES));
$cancelN = array_sum(array_column($cancelRows, 'n'));
$cancelValueSum = array_sum(array_map(static fn (array $row) => (float) $row['avg_value'] * (int) $row['reached'], $cancelRows));
$cancelReached = array_sum(array_column($cancelRows, 'reached'));
$followUpNeeded = array_sum(array_map(static fn (array $row) => (int) $row['n'], array_diff_key($byFollowUp, ['geen' => true])));
$hoursUsers = array_filter($byUser, static fn (array $row) => $row['weekly_hours'] !== null);
$shares = array_map(static fn (array $row) => total_minutes($row) / $weeks / ((int) $row['weekly_hours'] * 60) * 100, $hoursUsers);

// Factual summary sentences derived only from the numbers below; no interpretation.
$findings = [];
if ($n > 0) {
    $findings[] = count_label($n, 'belpoging', 'belpogingen') . ", waarvan {$reached} (" . percent($reached, $n) . ') tot een gesprek leidde' . ($reached === 1 ? '' : 'n') . '.';
    $findings[] = 'Totaal ' . fmt_minutes($minutes) . ' besteed; gemiddeld '
        . ($reached > 0 ? fmt_number($minutes / $reached, 0) . ' minuten per gevoerd gesprek' : 'n.v.t.')
        . ', inclusief mislukte pogingen, voorbereiding en verslaglegging.';
    $start = $byCategory['start'];
    if ((int) $start['reached'] > 0) {
        $findings[] = 'Startende leden: ' . count_label((int) $start['reached'], 'gesprek', 'gesprekken') . ', gemiddelde meerwaarde '
            . fmt_number(avg_or_null($start['avg_value'])) . ' en energie ' . fmt_number(avg_or_null($start['avg_energy'])) . '.';
    }
    if ($cancelReached > 0) {
        $findings[] = 'Opzeggende leden: ' . count_label($cancelN, 'poging', 'pogingen') . ', ' . count_label($cancelReached, 'gesprek', 'gesprekken') . ', gemiddelde meerwaarde '
            . fmt_number($cancelValueSum / $cancelReached) . '.';
    }
    if ($followUpNeeded > 0) {
        $findings[] = 'Vervolgactie nodig na ' . count_label($followUpNeeded, 'contact', 'contacten') . ', waarvan ' . (int) $byFollowUp['bestuur']['n']
            . ' door het bestuur en ' . (int) $byFollowUp['ledenadministratie']['n'] . ' door de ledenadministratie.';
    }
    $findings[] = (int) $total['displaced'] . ' keer ging het bellen ten koste van ander ambassadeurswerk.';
    if ($shares !== []) {
        $findings[] = 'Bellen kostte gemiddeld ' . fmt_number(array_sum($shares) / count($shares))
            . '% van de betaalde ambassadeursuren (spreiding ' . fmt_number(min($shares)) . '–' . fmt_number(max($shares)) . '%).';
    }
}

render_header('Evaluatie pilot ledencontact', $viewer);
render_filter_form('/report.php', $filter);
?>
<p class="no-print hint">Opslaan als PDF: gebruik Afdrukken (Ctrl+P / Cmd+P) en kies "Opslaan als PDF". Navigatie en filters worden niet afgedrukt.</p>
<p class="report-meta"><?= e(filter_description($filter, $total)) ?> · <?= e(fmt_number($weeks)) ?> weken · gegenereerd <?= e($today->format('d-m-Y')) ?></p>

<h2>Kernbevindingen</h2>
<?php if ($findings === []): ?><p>Geen registraties in deze selectie.</p><?php else: ?>
<ul class="findings"><?php foreach ($findings as $finding): ?><li><?= e($finding) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<?php render_kpis($total); ?>

<div class="report-section">
<h2>Per soort contact</h2>
<?php render_grouped_table('Soort', CONTACT_CATEGORIES, $byCategory); ?>
</div>
<div class="report-section">
<h2>Uitkomst belpogingen</h2>
<?php render_grouped_table('Uitkomst', OUTCOMES, $byOutcome, false); ?>
</div>
<div class="report-section">
<h2>Opzegredenen</h2>
<?php render_grouped_table('Reden', CANCEL_REASONS, $byReason); ?>
</div>
<div class="report-section">
<h2>Vervolgacties</h2>
<?php render_grouped_table('Vervolgactie door', FOLLOW_UPS, $byFollowUp, false); ?>
</div>
<div class="report-section">
<h2>Tijdsbelasting per medewerker</h2>
<?php render_user_load_table($byUser, $weeks); ?>
</div>

<div class="report-section">
<h2>Kwalitatieve indrukken</h2>
<p class="hint">Maximaal 30 meest recente, zonder naam van medewerker of inhoud van het gesprek.</p>
<?php foreach ($quotes as $quote): ?>
<blockquote class="quote">
  <p class="pre"><?= e($quote['impression']) ?></p>
  <footer><?= e(CONTACT_CATEGORIES[$quote['category']]) ?> · <?= e(region_label($quote['region'])) ?> · <?= e(date('m-Y', strtotime($quote['contact_date']))) ?></footer>
</blockquote>
<?php endforeach; ?>
<?php if ($quotes === []): ?><p>Geen indrukken geregistreerd.</p><?php endif; ?>
</div>

<h2>Methode</h2>
<p class="hint">Zelfregistratie door ambassadeurs en bureaumedewerkers direct na elk contact. Tijd omvat gesprekstijd én overige tijd
(voorbereiden, terugbellen, verslag); mislukte pogingen tellen mee. Energie en meerwaarde zijn subjectieve scores en alleen
gevraagd bij gevoerde gesprekken. Percentage van betaalde uren = gemiddelde belminuten per week / betaalde uren per week.</p>
<?php
render_footer();
