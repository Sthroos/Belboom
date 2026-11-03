<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$today = new DateTimeImmutable('today');
$total = stats_total();
$byCategory = stats_by_category();
$byUser = stats_by_user();
$byMonth = stats_by_month();
$recent = recent_contacts(50);
$count = (int) $total['n'];
$totalMinutes = (int) $total['call_min'] + (int) $total['extra_min'];
$weeks = pilot_weeks($total['first_date'], $today);

render_header('Dashboard', $user);
?>
<section class="kpis">
  <div class="kpi"><span class="value"><?= e($count) ?></span><span class="label">contacten</span></div>
  <div class="kpi"><span class="value"><?= e(fmt_minutes($totalMinutes)) ?></span><span class="label">totale tijd (gesprek + overig)</span></div>
  <div class="kpi"><span class="value"><?= e($count > 0 ? fmt_number($totalMinutes / $count, 0) . ' min' : '–') ?></span><span class="label">gemiddeld per contact</span></div>
  <div class="kpi"><span class="value"><?= e(fmt_number($total['avg_energy'] === null ? null : (float) $total['avg_energy'])) ?></span><span class="label">energie (−2 kost … +2 levert)</span></div>
  <div class="kpi"><span class="value"><?= e(fmt_number($total['avg_value'] === null ? null : (float) $total['avg_value'])) ?></span><span class="label">meerwaarde (1–5)</span></div>
  <div class="kpi"><span class="value"><?= e((int) $total['displaced']) ?></span><span class="label">ten koste van ander werk</span></div>
</section>

<h2>Per soort contact</h2>
<p class="hint">Hier zie je of bellen van startende leden iets anders oplevert dan bellen van opzeggende leden.</p>
<div class="table-wrap">
<table>
  <thead><tr><th>Soort</th><th>Aantal</th><th>Aandeel</th><th>Tijd totaal</th><th>Gem. min</th><th>Energie</th><th>Meerwaarde</th><th>Ten koste van werk</th></tr></thead>
  <tbody>
  <?php foreach ($byCategory as $key => $row):
      $n = (int) $row['n'];
      $minutes = (int) $row['call_min'] + (int) $row['extra_min']; ?>
    <tr>
      <td><?= e(CONTACT_CATEGORIES[$key]) ?></td>
      <td><?= e($n) ?></td>
      <td><meter min="0" max="<?= e(max($count, 1)) ?>" value="<?= e($n) ?>"></meter></td>
      <td><?= e(fmt_minutes($minutes)) ?></td>
      <td><?= e($n > 0 ? fmt_number($minutes / $n, 0) : '–') ?></td>
      <td><?= e(fmt_number($row['avg_energy'] === null ? null : (float) $row['avg_energy'])) ?></td>
      <td><?= e(fmt_number($row['avg_value'] === null ? null : (float) $row['avg_value'])) ?></td>
      <td><?= e((int) $row['displaced']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2>Tijdsbelasting per ambassadeur</h2>
<p class="hint">Gemiddelde tijd per week sinds de eerste registratie (<?= e(fmt_number($weeks)) ?> weken), afgezet tegen de betaalde uren per week.</p>
<div class="table-wrap">
<table>
  <thead><tr><th>Ambassadeur</th><th>Betaalde uren/week</th><th>Contacten</th><th>Tijd totaal</th><th>Min/week</th><th>% van betaalde uren</th></tr></thead>
  <tbody>
  <?php foreach ($byUser as $row):
      $minutes = (int) $row['call_min'] + (int) $row['extra_min'];
      $perWeek = $minutes / $weeks;
      $share = $perWeek / ((int) $row['weekly_hours'] * 60) * 100; ?>
    <tr>
      <td><?= e($row['display_name']) ?></td>
      <td><?= e((int) $row['weekly_hours']) ?></td>
      <td><?= e((int) $row['n']) ?></td>
      <td><?= e(fmt_minutes($minutes)) ?></td>
      <td><?= e(fmt_number($perWeek, 0)) ?></td>
      <td><meter min="0" max="100" high="10" optimum="0" value="<?= e(min($share, 100)) ?>"></meter> <?= e(fmt_number($share)) ?>%</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2>Per maand</h2>
<div class="table-wrap">
<table>
  <thead><tr><th>Maand</th><th>Contacten</th><th>Tijd totaal</th><th>Energie</th><th>Meerwaarde</th></tr></thead>
  <tbody>
  <?php foreach ($byMonth as $row): ?>
    <tr>
      <td><?= e($row['month']) ?></td>
      <td><?= e((int) $row['n']) ?></td>
      <td><?= e(fmt_minutes((int) $row['call_min'] + (int) $row['extra_min'])) ?></td>
      <td><?= e(fmt_number((float) $row['avg_energy'])) ?></td>
      <td><?= e(fmt_number((float) $row['avg_value'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($byMonth === []): ?><tr><td colspan="5">Nog geen registraties.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<h2>Laatste registraties</h2>
<?php foreach ($recent as $row): ?>
<article class="card contact">
  <header>
    <strong><?= e(CONTACT_CATEGORIES[$row['category']]) ?></strong>
    · <?= e($row['contact_date']) ?> · <?= e($row['display_name']) ?>
    · <?= e((int) $row['call_minutes']) ?> + <?= e((int) $row['extra_minutes']) ?> min
    · <?= e(ENERGY_LABELS[(int) $row['energy']]) ?>
    · <?= e(VALUE_LABELS[(int) $row['value_score']]) ?>
    <?php if ((int) $row['displaced_work'] === 1): ?>· <em>ten koste van ander werk</em><?php endif; ?>
  </header>
  <p class="pre"><?= e($row['content']) ?></p>
  <?php if ($row['impression'] !== ''): ?><p class="pre muted">Indruk: <?= e($row['impression']) ?></p><?php endif; ?>
  <?php if ((int) $row['user_id'] === (int) $user['id']): ?>
  <form method="post" action="/contact-delete.php" class="inline">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((int) $row['id']) ?>">
    <button type="submit" class="link danger">Verwijderen</button>
  </form>
  <?php endif; ?>
</article>
<?php endforeach; ?>
<?php if ($recent === []): ?><p>Nog geen registraties.</p><?php endif; ?>
<?php
render_footer();
