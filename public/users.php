<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$admin = require_admin();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';
    $targetId = int_in_range($_POST['user_id'] ?? null, 1, PHP_INT_MAX);
    $isSelf = $targetId === (int) $admin['id'];

    if ($action === 'create') {
        $username = mb_strtolower(text_field($_POST['username'] ?? null));
        $displayName = text_field($_POST['display_name'] ?? null);
        $weeklyHours = int_in_range($_POST['weekly_hours'] ?? null, 1, 40);
        $region = parse_region($_POST['region'] ?? null);
        if (!username_is_valid($username)) {
            $errors[] = 'Gebruikersnaam: 3–32 tekens, alleen a-z, 0-9, punt, streepje of underscore.';
        }
        if ($displayName === '' || mb_strlen($displayName) > 80) {
            $errors[] = 'Naam is verplicht (maximaal 80 tekens).';
        }
        if ($weeklyHours === null) {
            $errors[] = 'Betaalde uren per week moet tussen 1 en 40 liggen.';
        }
        if ($region === null) {
            $errors[] = 'Kies een regio.';
        }
        if ($errors === []) {
            $exists = db()->prepare('SELECT 1 FROM users WHERE username = ?');
            $exists->execute([$username]);
            if ($exists->fetchColumn() !== false) {
                $errors[] = 'Deze gebruikersnaam bestaat al.';
            }
        }
        if ($errors === []) {
            $newId = create_user($username, $displayName, 'user', $weeklyHours, $region);
            $_SESSION['invite_link'] = issue_invite($newId);
            log_event('info', 'user_created', ['admin_id' => (int) $admin['id'], 'user_id' => $newId]);
            redirect('/users.php');
        }
    } elseif ($action === 'set_region' && $targetId !== null) {
        $region = parse_region($_POST['region'] ?? null);
        if ($region === null) {
            $errors[] = 'Kies een geldige regio.';
        } else {
            db()->prepare('UPDATE users SET region = ? WHERE id = ?')->execute([$region, $targetId]);
            log_event('info', 'user_set_region', ['admin_id' => (int) $admin['id'], 'user_id' => $targetId]);
            flash('ok', 'Regio bijgewerkt.');
            redirect('/users.php');
        }
    } elseif ($targetId !== null && !$isSelf && in_array($action, ['reinvite', 'deactivate', 'activate'], true)) {
        if ($action === 'reinvite') {
            $_SESSION['invite_link'] = issue_invite($targetId);
        } else {
            db()->prepare('UPDATE users SET active = ?, session_version = session_version + 1 WHERE id = ?')
                ->execute([$action === 'activate' ? 1 : 0, $targetId]);
            flash('ok', $action === 'activate' ? 'Gebruiker geactiveerd.' : 'Gebruiker gedeactiveerd en uitgelogd.');
        }
        log_event('info', 'user_' . $action, ['admin_id' => (int) $admin['id'], 'user_id' => $targetId]);
        redirect('/users.php');
    } else {
        $errors[] = 'Ongeldige actie.';
    }
}

$inviteLink = $_SESSION['invite_link'] ?? null;
unset($_SESSION['invite_link']);
$users = db()->query('SELECT id, username, display_name, role, weekly_hours, region, active, last_login_at,
                      password_hash IS NOT NULL AS activated FROM users ORDER BY id')->fetchAll();

render_header('Gebruikers', $admin);
render_errors($errors);
if ($inviteLink !== null): ?>
<div class="flash ok">
  <p>Uitnodigingslink (eenmalig zichtbaar, 72 uur geldig, eenmalig te gebruiken). Stuur via een ander kanaal dan de gebruikersnaam, bijv. Signal:</p>
  <input class="secret" value="<?= e($inviteLink) ?>" readonly>
</div>
<?php endif; ?>
<div class="table-wrap">
<table>
  <thead><tr><th>Naam</th><th>Gebruikersnaam</th><th>Rol</th><th>Regio</th><th>Uren/week</th><th>Status</th><th>Laatst ingelogd</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $row): ?>
    <tr>
      <td><?= e($row['display_name']) ?></td>
      <td><?= e($row['username']) ?></td>
      <td><?= e($row['role']) ?></td>
      <td>
        <form method="post" action="/users.php" class="inline-row">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_region">
          <input type="hidden" name="user_id" value="<?= e((int) $row['id']) ?>">
          <select name="region" required>
            <?php if ($row['region'] === null): ?><option value="">Kies…</option><?php endif; ?>
            <?php render_options(LHV_REGIONS, $row['region']); ?>
          </select>
          <button type="submit" class="link">Opslaan</button>
        </form>
      </td>
      <td><?= e((int) $row['weekly_hours']) ?></td>
      <td><?= (int) $row['active'] === 0 ? 'gedeactiveerd' : ((int) $row['activated'] === 1 ? 'actief' : 'uitnodiging open') ?></td>
      <td><?= e($row['last_login_at'] === null ? '–' : date('Y-m-d H:i', (int) $row['last_login_at'])) ?></td>
      <td>
        <?php if ((int) $row['id'] !== (int) $admin['id']): ?>
        <form method="post" action="/users.php" class="inline">
          <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= e((int) $row['id']) ?>">
          <button name="action" value="reinvite" class="link">Nieuwe uitnodiging (reset)</button>
          <?php if ((int) $row['active'] === 1): ?>
          <button name="action" value="deactivate" class="link danger">Deactiveren</button>
          <?php else: ?>
          <button name="action" value="activate" class="link">Activeren</button>
          <?php endif; ?>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2>Gebruiker toevoegen</h2>
<form method="post" action="/users.php" class="card narrow">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <label>Naam <input name="display_name" maxlength="80" required></label>
  <label>Gebruikersnaam <input name="username" maxlength="32" pattern="[a-z0-9._\-]{3,32}" required></label>
  <label>Regio (LHV-afdeling)
    <select name="region" required><option value="">Kies…</option><?php render_options(LHV_REGIONS, null); ?></select>
  </label>
  <label>Betaalde uren per week <input type="number" name="weekly_hours" min="1" max="40" value="8" required></label>
  <button type="submit">Aanmaken en uitnodigingslink tonen</button>
</form>
<?php
render_footer();
