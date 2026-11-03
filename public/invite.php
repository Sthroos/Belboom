<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

function render_invalid_invite(): never
{
    http_response_code(404);
    render_header('Uitnodiging ongeldig', null);
    echo '<p>Deze link is ongeldig, verlopen of al gebruikt. Vraag de beheerder om een nieuwe uitnodiging.</p>';
    render_footer();
    exit;
}

// Move the token from the URL into the session and redirect, so it does not stay in the
// address bar or leak via Referer headers on follow-up requests.
if (isset($_GET['token'])) {
    $urlToken = text_field($_GET['token']);
    $invite = find_valid_invite($urlToken);
    if ($invite === null) {
        render_invalid_invite();
    }
    // The TOTP secret lives in the session until the user proves the app is set up correctly.
    if (($_SESSION['invite_setup']['invite_id'] ?? null) !== $invite['invite_id']) {
        session_regenerate_id(true);
        $_SESSION['invite_setup'] = [
            'token' => $urlToken,
            'invite_id' => $invite['invite_id'],
            'secret' => totp_generate_secret(),
        ];
    }
    redirect('/invite.php');
}

$setup = $_SESSION['invite_setup'] ?? null;
$invite = is_array($setup) ? find_valid_invite($setup['token']) : null;
if ($invite === null) {
    render_invalid_invite();
}
$secret = $setup['secret'];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_post();
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
    $code = preg_replace('/\s+/', '', text_field($_POST['code'] ?? null));

    $policyError = password_policy_error($password, $confirm, $invite['username']);
    if ($policyError !== null) {
        $errors[] = $policyError;
    }
    $step = totp_match($secret, $code, 0, time());
    if ($step === null) {
        $errors[] = 'De code uit de authenticator-app klopt niet. Controleer de sleutel en de klok van je telefoon.';
    }
    if ($errors === []) {
        if (!accept_invite($invite, $password, $secret, $step)) {
            throw new RuntimeException('Invite consumed concurrently');
        }
        unset($password, $confirm);
        $_SESSION = [];
        session_regenerate_id(true);
        log_event('info', 'invite_accepted', ['user_id' => (int) $invite['user_id']]);
        flash('ok', 'Account ingesteld. Je kunt nu inloggen.');
        redirect('/login.php');
    }
    unset($password, $confirm);
}

render_header('Account instellen', null);
render_errors($errors);
?>
<form method="post" action="/invite.php" class="card narrow">
  <?= csrf_field() ?>
  <p>Welkom <?= e($invite['display_name']) ?>. Je gebruikersnaam is <strong><?= e($invite['username']) ?></strong>.</p>

  <h2>1. Wachtwoord</h2>
  <label>Nieuw wachtwoord (minimaal <?= PASSWORD_MIN ?> tekens)
    <input type="password" name="password" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" required>
  </label>
  <label>Herhaal wachtwoord
    <input type="password" name="password_confirm" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" required>
  </label>

  <h2>2. Tweestapsverificatie</h2>
  <p>Voeg in een authenticator-app (bijv. Microsoft/Google Authenticator, Aegis, 2FAS) handmatig een account toe met deze sleutel (tijdgebaseerd, 6 cijfers):</p>
  <p class="secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></p>
  <details><summary>Of gebruik deze otpauth-link</summary><p class="secret small"><?= e(totp_uri($secret, $invite['username'])) ?></p></details>
  <label>Code die de app nu toont
    <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required>
  </label>
  <button type="submit">Account activeren</button>
</form>
<?php
render_footer();
