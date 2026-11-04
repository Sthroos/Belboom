<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (isset($_SESSION['uid'])) {
    redirect('/');
}

const GENERIC_LOGIN_ERROR = 'Inloggen mislukt. Controleer je gegevens.';
const RATE_LIMIT_ERROR = 'Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.';

$pending = $_SESSION['pending_2fa'] ?? null;
if (is_array($pending) && time() - $pending['at'] > PENDING_2FA_SECONDS) {
    unset($_SESSION['pending_2fa']);
    $pending = null;
}
$error = null;
$ip = client_ip();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_post();
    $stage = $_POST['stage'] ?? '';

    if ($stage === 'password') {
        $username = text_field($_POST['username'] ?? null);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (!register_login_attempt($username)) {
            log_event('warning', 'login_rate_limited', ['ip' => $ip]);
            $error = RATE_LIMIT_ERROR;
        } else {
            $user = verify_password_login($username, $password);
            if ($user === null) {
                log_event('notice', 'login_password_failed', ['ip' => $ip]);
                $error = GENERIC_LOGIN_ERROR;
            } else {
                session_regenerate_id(true);
                $_SESSION['pending_2fa'] = ['uid' => (int) $user['id'], 'username' => $user['username'], 'at' => time()];
                redirect('/login.php');
            }
        }
        unset($password);
    }

    if ($stage === 'totp' && is_array($pending)) {
        $code = preg_replace('/\s+/', '', text_field($_POST['code'] ?? null));
        if (!register_login_attempt($pending['username'])) {
            log_event('warning', 'login_rate_limited', ['ip' => $ip, 'user_id' => $pending['uid']]);
            $error = RATE_LIMIT_ERROR;
        } else {
            $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1 AND totp_secret IS NOT NULL');
            $stmt->execute([$pending['uid']]);
            $user = $stmt->fetch();
            if ($user !== false && verify_totp_login($user, $code)) {
                clear_login_attempts($user['username']);
                db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([time(), $user['id']]);
                establish_login_session($user);
                log_event('info', 'login_success', ['user_id' => (int) $user['id'], 'ip' => $ip]);
                redirect('/');
            }
            log_event('notice', 'login_totp_failed', ['ip' => $ip, 'user_id' => $pending['uid']]);
            $error = 'Code onjuist of al gebruikt. Wacht op een nieuwe code.';
        }
    }
}

render_header('Inloggen', null);
if ($error !== null) {
    echo '<p class="flash error">' . e($error) . '</p>';
}
if (is_array($pending)): ?>
<form method="post" action="/login.php" class="card narrow">
  <?= csrf_field() ?>
  <input type="hidden" name="stage" value="totp">
  <label>Code uit je authenticator-app
    <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
  </label>
  <button type="submit">Bevestigen</button>
</form>
<?php else: ?>
<form method="post" action="/login.php" class="card narrow">
  <?= csrf_field() ?>
  <input type="hidden" name="stage" value="password">
  <label>Gebruikersnaam
    <input name="username" autocomplete="username" maxlength="32" required autofocus>
  </label>
  <label>Wachtwoord
    <input type="password" name="password" autocomplete="current-password" maxlength="128" required>
  </label>
  <button type="submit">Volgende</button>
</form>
<?php endif;
render_footer();
