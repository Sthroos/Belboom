<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$viewer = require_stats_access();
$filter = parse_stats_filter($_GET);
$today = new DateTimeImmutable('today');
$total = stats_total($filter);
$weeks = period_weeks($filter, $total, $today);
$recent = recent_contacts($filter, 50);
$query = filter_query_string($filter);

render_header('Statistieken', $viewer);
render_filter_form('/stats.php', $filter);
?>
<p class="hint"><?= e(filter_description($filter, $total)) ?> · <a href="/report.php<?= $query === '' ? '' : '?' . e($query) ?>">Evaluatierapport voor deze selectie</a></p>
<?php render_kpis($total); ?>

<h2>Per soort contact</h2>
<?php render_grouped_table('Soort', CONTACT_CATEGORIES, stats_grouped('category', CONTACT_CATEGORIES, $filter)); ?>
<?php render_other_explanations('Omschrijvingen bij "Overig":', other_explanations('category_other', $filter, 50)); ?>

<h2>Uitkomst belpogingen</h2>
<?php render_grouped_table('Uitkomst', OUTCOMES, stats_grouped('outcome', OUTCOMES, $filter), false); ?>

<h2>Opzegredenen</h2>
<?php render_grouped_table('Reden', CANCEL_REASONS, stats_grouped('cancel_reason', CANCEL_REASONS, $filter)); ?>
<?php render_other_explanations('Toelichtingen bij "Overig":', other_explanations('cancel_reason_other', $filter, 50)); ?>

<h2>Vervolgacties</h2>
<?php render_grouped_table('Vervolgactie door', FOLLOW_UPS, stats_grouped('follow_up', FOLLOW_UPS, $filter), false); ?>

<?php if ($filter['region'] === null): ?>
<h2>Per regio</h2>
<div class="table-wrap">
<table>
  <thead><tr><th>Regio</th><th>Medewerkers</th><th>Pogingen</th><th>Gesprekken</th><th>Tijd totaal</th><th>Energie</th><th>Meerwaarde</th></tr></thead>
  <tbody>
  <?php foreach (stats_by_region($filter) as $row): ?>
    <tr>
      <td><?php if ($row['region'] !== null): ?><a href="/stats.php?<?= e(filter_query_string(['region' => $row['region']] + $filter)) ?>"><?= e(region_label($row['region'])) ?></a><?php else: ?><?= e(region_label(null)) ?><?php endif; ?></td>
      <td><?= e((int) $row['users']) ?></td>
      <td><?= e((int) $row['n']) ?></td>
      <td><?= e((int) $row['reached']) ?></td>
      <td><?= e(fmt_minutes(total_minutes($row))) ?></td>
      <td><?= e(fmt_number(avg_or_null($row['avg_energy']))) ?></td>
      <td><?= e(fmt_number(avg_or_null($row['avg_value']))) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2>Tijdsbelasting per medewerker</h2>
<p class="hint">Gemiddelde tijd per week over <?= e(fmt_number($weeks)) ?> weken, afgezet tegen de betaalde uren per week.</p>
<?php render_user_load_table(stats_by_user($filter), $weeks); ?>

<h2>Per maand</h2>
<div class="table-wrap">
<table>
  <thead><tr><th>Maand</th><th>Pogingen</th><th>Gesprekken</th><th>Tijd totaal</th><th>Energie</th><th>Meerwaarde</th></tr></thead>
  <tbody>
  <?php $byMonth = stats_by_month($filter); foreach ($byMonth as $row): ?>
    <tr>
      <td><?= e($row['month']) ?></td>
      <td><?= e((int) $row['n']) ?></td>
      <td><?= e((int) $row['reached']) ?></td>
      <td><?= e(fmt_minutes(total_minutes($row))) ?></td>
      <td><?= e(fmt_number(avg_or_null($row['avg_energy']))) ?></td>
      <td><?= e(fmt_number(avg_or_null($row['avg_value']))) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($byMonth === []): ?><tr><td colspan="6">Geen registraties in deze selectie.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<h2>Laatste registraties</h2>
<?php foreach ($recent as $row): ?>
<article class="card contact">
  <header>
    <strong><?= e(category_label($row)) ?></strong>
    · <?= e($row['display_name']) ?> (<?= e(region_label($row['region'])) ?>) · <?= e(contact_summary($row)) ?>
  </header>
  <?php if ($row['content'] !== ''): ?><p class="pre"><?= e($row['content']) ?></p><?php endif; ?>
  <?php if ($row['impression'] !== ''): ?><p class="pre muted">Indruk: <?= e($row['impression']) ?></p><?php endif; ?>
</article>
<?php endforeach; ?>
<?php if ($recent === []): ?><p>Geen registraties in deze selectie.</p><?php endif; ?>
<?php
render_footer();
