<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$admin = require_admin();
$errors = [];

/** @return string|null role to store; admins keep their role, others must get an assignable role. */
function resolve_role(string $currentRole, mixed $requested): ?string
{
    if ($currentRole === 'admin') {
        return 'admin';
    }
    return in_array($requested, ASSIGNABLE_ROLES, true) ? $requested : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';
    $targetId = int_in_range($_POST['user_id'] ?? null, 1, PHP_INT_MAX);
    $isSelf = $targetId === (int) $admin['id'];

    if ($action === 'create') {
        $username = mb_strtolower(text_field($_POST['username'] ?? null));
        $displayName = text_field($_POST['display_name'] ?? null);
        $region = parse_region($_POST['region'] ?? null);
        $role = resolve_role('user', $_POST['role'] ?? null);
        $weeklyHours = $role !== null && role_has_hours($role) ? int_in_range($_POST['weekly_hours'] ?? null, 1, 40) : null;
        if ($role === null) {
            $errors[] = 'Kies een rol.';
        }
        if (!username_is_valid($username)) {
            $errors[] = 'Gebruikersnaam: 3–32 tekens, alleen a-z, 0-9, punt, streepje of underscore.';
        }
        if ($displayName === '' || mb_strlen($displayName) > 80) {
            $errors[] = 'Naam is verplicht (maximaal 80 tekens).';
        }
        if ($role !== null && role_has_hours($role) && $weeklyHours === null) {
            $errors[] = 'Vul voor een ambassadeur de betaalde uren per week in (1–40).';
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
            $newId = create_user($username, $displayName, $role, $weeklyHours, $region);
            $_SESSION['invite_link'] = issue_invite($newId);
            log_event('info', 'user_created', ['admin_id' => (int) $admin['id'], 'user_id' => $newId]);
            redirect('/users.php');
        }
    } elseif ($action === 'update' && $targetId !== null) {
        $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $currentRole = $stmt->fetchColumn();
        $role = $currentRole === false ? null : resolve_role($currentRole, $_POST['role'] ?? null);
        $region = parse_region($_POST['region'] ?? null);
        $weeklyHours = $role !== null && role_has_hours($role) ? int_in_range($_POST['weekly_hours'] ?? null, 1, 40) : null;
        if ($role === null || $region === null || (role_has_hours($role) && $weeklyHours === null)) {
            $errors[] = 'Ongeldige rol of regio, of geen betaalde uren (1–40) ingevuld voor een ambassadeur.';
        } else {
            // Role is read from the database on every request, so a change takes effect immediately.
            db()->prepare('UPDATE users SET role = ?, region = ?, weekly_hours = ? WHERE id = ?')
                ->execute([$role, $region, $weeklyHours, $targetId]);
            log_event('info', 'user_updated', ['admin_id' => (int) $admin['id'], 'user_id' => $targetId, 'role' => $role]);
            flash('ok', 'Gebruiker bijgewerkt.');
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
                      password_hash IS NOT NULL AS activated FROM users ORDER BY role = \'admin\' DESC, display_name')->fetchAll();
$assignableRoleLabels = array_intersect_key(ROLE_LABELS, array_flip(ASSIGNABLE_ROLES));

render_header('Gebruikers', $admin);
render_errors($errors);
if ($inviteLink !== null): ?>
<div class="flash ok">
  <p>Uitnodigingslink (eenmalig zichtbaar, 72 uur geldig, eenmalig te gebruiken). Stuur via een ander kanaal dan de gebruikersnaam, bijv. Signal:</p>
  <input class="secret" value="<?= e($inviteLink) ?>" readonly>
</div>
<?php endif; ?>
<div class="table-wrap">
<table class="users">
  <thead><tr><th>Gebruiker</th><th>Rol</th><th>Regio</th><th>Uren/week</th><th>Status</th><th>Acties</th></tr></thead>
  <tbody>
  <?php foreach ($users as $row):
      $id = (int) $row['id'];
      $formId = 'edit-' . $id;
      $status = (int) $row['active'] === 0 ? 'gedeactiveerd' : ((int) $row['activated'] === 1 ? 'actief' : 'uitnodiging open'); ?>
    <tr>
      <td>
        <strong><?= e($row['display_name']) ?></strong>
        <span class="sub"><?= e($row['username']) ?></span>
      </td>
      <td>
        <?php if ($row['role'] === 'admin'): ?>
          <?= e(ROLE_LABELS['admin']) ?>
        <?php else: ?>
          <select name="role" form="<?= e($formId) ?>" aria-label="Rol"><?php render_options($assignableRoleLabels, $row['role']); ?></select>
        <?php endif; ?>
      </td>
      <td>
        <select name="region" form="<?= e($formId) ?>" aria-label="Regio" required>
          <?php if ($row['region'] === null): ?><option value="">Kies…</option><?php endif; ?>
          <?php render_options(LHV_REGIONS, $row['region']); ?>
        </select>
      </td>
      <td><input type="number" name="weekly_hours" form="<?= e($formId) ?>" aria-label="Uren per week" min="1" max="40" value="<?= e($row['weekly_hours']) ?>" placeholder="n.v.t." class="hours"></td>
      <td>
        <?= e($status) ?>
        <span class="sub"><?= e($row['last_login_at'] === null ? 'nooit ingelogd' : date('d-m-Y H:i', (int) $row['last_login_at'])) ?></span>
      </td>
      <td class="actions">
        <form method="post" action="/users.php" id="<?= e($formId) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="user_id" value="<?= e($id) ?>">
          <button type="submit" class="small">Opslaan</button>
        </form>
        <?php if ($id !== (int) $admin['id']): ?>
        <form method="post" action="/users.php">
          <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= e($id) ?>">
          <button name="action" value="reinvite" class="link">Nieuwe uitnodiging</button>
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
<p class="hint">Uren/week geldt alleen voor ambassadeurs; bij een bureaumedewerker wordt het genegeerd. "Nieuwe uitnodiging" wist wachtwoord en 2FA van de gebruiker en logt hem uit.</p>

<h2>Gebruiker toevoegen</h2>
<form method="post" action="/users.php" class="card narrow">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <label>Naam <input name="display_name" maxlength="80" required></label>
  <label>Gebruikersnaam <input name="username" maxlength="32" pattern="[a-z0-9._\-]{3,32}" required></label>
  <label>Rol
    <select name="role" required>
      <option value="user">Ambassadeur – registreren</option>
      <option value="staff">Bureaumedewerker – registreren en statistieken inzien</option>
    </select>
  </label>
  <label>Regio (LHV-afdeling)
    <select name="region" required><option value="">Kies…</option><?php render_options(LHV_REGIONS, null); ?></select>
  </label>
  <label>Betaalde uren per week (alleen ambassadeurs) <input type="number" name="weekly_hours" min="1" max="40" value="8"></label>
  <button type="submit">Aanmaken en uitnodigingslink tonen</button>
</form>
<?php
render_footer();
