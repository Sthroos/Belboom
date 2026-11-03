<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_post();
    $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';

    if (is_rate_limited($user['username'], client_ip())) {
        $errors[] = 'Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.';
    } elseif (verify_password_login($user['username'], $current) === null) {
        record_login_failure($user['username'], client_ip());
        $errors[] = 'Huidig wachtwoord onjuist.';
    } else {
        $policyError = password_policy_error($password, $confirm, $user['username']);
        if ($policyError !== null) {
            $errors[] = $policyError;
        }
    }
    if ($errors === []) {
        // Bumping session_version logs out every other session of this user.
        db()->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_ARGON2ID), $user['id']]);
        $user['session_version'] = (int) $user['session_version'] + 1;
        establish_login_session($user);
        log_event('info', 'password_changed', ['user_id' => (int) $user['id']]);
        flash('ok', 'Wachtwoord gewijzigd. Andere sessies zijn uitgelogd.');
        redirect('/');
    }
    unset($current, $password, $confirm);
}

render_header('Wachtwoord wijzigen', $user);
render_errors($errors);
?>
<form method="post" action="/password.php" class="card narrow">
  <?= csrf_field() ?>
  <label>Huidig wachtwoord
    <input type="password" name="current" autocomplete="current-password" maxlength="<?= PASSWORD_MAX ?>" required>
  </label>
  <label>Nieuw wachtwoord (minimaal <?= PASSWORD_MIN ?> tekens)
    <input type="password" name="password" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" required>
  </label>
  <label>Herhaal nieuw wachtwoord
    <input type="password" name="password_confirm" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" required>
  </label>
  <button type="submit">Wijzigen</button>
</form>
<?php
render_footer();
