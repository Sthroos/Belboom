<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$admin = require_admin();
$event = option_key($_GET['event'] ?? null, AUDIT_EVENT_LABELS);
$entries = recent_audit_entries($event, 300);

render_header('Logboek', $admin);
?>
<form method="get" action="/audit.php" class="filter">
  <label>Gebeurtenis
    <select name="event"><option value="">Alle</option><?php render_options(AUDIT_EVENT_LABELS, $event); ?></select>
  </label>
  <button type="submit">Filteren</button>
</form>
<p class="hint">Laatste 300 gebeurtenissen; ouder dan 180 dagen wordt automatisch verwijderd. Mislukte inlogpogingen op niet-bestaande gebruikersnamen staan alleen in de serverlog.</p>
<div class="table-wrap">
<table>
  <thead><tr><th>Tijdstip</th><th>Gebeurtenis</th><th>Door</th><th>Betreft</th><th>Details</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($entries as $row): ?>
    <tr class="<?= str_contains($row['event'], 'failed') || $row['event'] === 'login_rate_limited' ? 'warn' : '' ?>">
      <td><?= e(date('d-m-Y H:i:s', (int) $row['created_at'])) ?></td>
      <td><?= e(AUDIT_EVENT_LABELS[$row['event']] ?? $row['event']) ?></td>
      <td><?= e($row['actor_name'] ?? ($row['actor_user_id'] === null ? '–' : '#' . $row['actor_user_id'])) ?></td>
      <td><?= e($row['target_name'] ?? ($row['target_user_id'] === null ? '–' : '#' . $row['target_user_id'])) ?></td>
      <td><?= e($row['detail'] ?? '') ?></td>
      <td><?= e($row['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($entries === []): ?><tr><td colspan="6">Geen gebeurtenissen.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php
render_footer();
