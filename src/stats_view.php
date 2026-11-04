<?php
declare(strict_types=1);

/** Rendering helpers shared by stats.php and report.php. */

function avg_or_null(mixed $value): ?float
{
    return $value === null ? null : (float) $value;
}

function percent(int $part, int $whole): string
{
    return $whole === 0 ? '–' : fmt_number($part / $whole * 100, 0) . '%';
}

function count_label(int $count, string $singular, string $plural): string
{
    return $count . ' ' . ($count === 1 ? $singular : $plural);
}

function render_filter_form(string $action, array $filter): void
{
    ?>
<form method="get" action="<?= e($action) ?>" class="filter no-print">
  <label>Regio
    <select name="regio"><option value="">Alle regio's</option><?php render_options(LHV_REGIONS, $filter['region']); ?></select>
  </label>
  <label>Van <input type="date" name="van" value="<?= e($filter['from']) ?>"></label>
  <label>Tot en met <input type="date" name="tot" value="<?= e($filter['to']) ?>"></label>
  <button type="submit">Filteren</button>
</form>
<?php
}

function filter_description(array $filter, array $total): string
{
    $from = $filter['from'] ?? $total['first_date'];
    $to = $filter['to'] ?? $total['last_date'];
    $period = $from === null ? 'geen registraties' : date('d-m-Y', strtotime($from)) . ' t/m ' . date('d-m-Y', strtotime($to ?? $from));
    return ($filter['region'] === null ? "Alle regio's" : region_label($filter['region'])) . ' · ' . $period;
}

function render_kpis(array $total): void
{
    $n = (int) $total['n'];
    $reached = (int) $total['reached'];
    $minutes = total_minutes($total);
    ?>
<section class="kpis">
  <div class="kpi"><span class="value"><?= e($n) ?></span><span class="label">belpogingen</span></div>
  <div class="kpi"><span class="value"><?= e($reached) ?> <small>(<?= e(percent($reached, $n)) ?>)</small></span><span class="label">gesprekken gevoerd</span></div>
  <div class="kpi"><span class="value"><?= e(fmt_minutes($minutes)) ?></span><span class="label">totale tijd incl. mislukte pogingen</span></div>
  <div class="kpi"><span class="value"><?= e($reached > 0 ? fmt_number($minutes / $reached, 0) . ' min' : '–') ?></span><span class="label">tijd per gevoerd gesprek (alles meegerekend)</span></div>
  <div class="kpi"><span class="value"><?= e(fmt_number(avg_or_null($total['avg_energy']))) ?></span><span class="label">energie (−2 kost … +2 levert)</span></div>
  <div class="kpi"><span class="value"><?= e(fmt_number(avg_or_null($total['avg_value']))) ?></span><span class="label">meerwaarde (1–5)</span></div>
  <div class="kpi"><span class="value"><?= e((int) $total['displaced']) ?></span><span class="label">ten koste van ander werk</span></div>
</section>
<?php
}

/**
 * @param array<string, string> $labels
 * @param array<string, array<string, mixed>> $rows keyed like $labels
 */
function render_grouped_table(string $firstHeader, array $labels, array $rows, bool $withQuality = true): void
{
    $sum = array_sum(array_map(static fn (array $row) => (int) $row['n'], $rows));
    ?>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e($firstHeader) ?></th><th>Aantal</th><th>Aandeel</th><th>Tijd totaal</th><th>Gem. min</th>
    <?php if ($withQuality): ?><th>Energie</th><th>Meerwaarde</th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($labels as $key => $label):
      $row = $rows[$key];
      $n = (int) $row['n'];
      $minutes = total_minutes($row); ?>
    <tr>
      <td><?= e($label) ?></td>
      <td><?= e($n) ?></td>
      <td><meter min="0" max="<?= e(max($sum, 1)) ?>" value="<?= e($n) ?>"></meter> <?= e(percent($n, $sum)) ?></td>
      <td><?= e(fmt_minutes($minutes)) ?></td>
      <td><?= e($n > 0 ? fmt_number($minutes / $n, 0) : '–') ?></td>
      <?php if ($withQuality): ?>
      <td><?= e(fmt_number(avg_or_null($row['avg_energy']))) ?></td>
      <td><?= e(fmt_number(avg_or_null($row['avg_value']))) ?></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php
}

/** @param list<array{text: string, n: int}> $items */
function render_other_explanations(string $title, array $items): void
{
    if ($items === []) {
        return;
    }
    ?>
<p class="other-list-title"><?= e($title) ?></p>
<ul class="other-list">
  <?php foreach ($items as $item): ?><li><?= e($item['text']) ?><?php if ((int) $item['n'] > 1): ?> <span class="sub-inline">(<?= e((int) $item['n']) ?>×)</span><?php endif; ?></li><?php endforeach; ?>
</ul>
<?php
}

function render_user_load_table(array $byUser, float $weeks): void
{
    ?>
<div class="table-wrap">
<table>
  <thead><tr><th>Medewerker</th><th>Regio</th><th>Betaalde uren/week</th><th>Pogingen</th><th>Gesprekken</th><th>Tijd totaal</th><th>Min/week</th><th>% van betaalde uren</th></tr></thead>
  <tbody>
  <?php foreach ($byUser as $row):
      $perWeek = total_minutes($row) / $weeks;
      $share = $row['weekly_hours'] === null ? null : $perWeek / ((int) $row['weekly_hours'] * 60) * 100; ?>
    <tr>
      <td><?= e($row['display_name']) ?></td>
      <td><?= e(region_label($row['region'])) ?></td>
      <td><?= e($row['weekly_hours'] ?? '–') ?></td>
      <td><?= e((int) $row['n']) ?></td>
      <td><?= e((int) $row['reached']) ?></td>
      <td><?= e(fmt_minutes(total_minutes($row))) ?></td>
      <td><?= e(fmt_number($perWeek, 0)) ?></td>
      <td><?php if ($share === null): ?>n.v.t.<?php else: ?><meter min="0" max="100" high="10" optimum="0" value="<?= e(min($share, 100)) ?>"></meter> <?= e(fmt_number($share)) ?>%<?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php
}
