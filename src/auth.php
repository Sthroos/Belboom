<?php
declare(strict_types=1);

const SESSION_IDLE_SECONDS = 1800;
const SESSION_ABSOLUTE_SECONDS = 28800;
const PENDING_2FA_SECONDS = 300;
const ATTEMPT_WINDOW_SECONDS = 900;
const ATTEMPT_LIMIT_PER_USER = 10;
const INVITE_TTL_SECONDS = 259200;
const PASSWORD_MIN = 12;
const PASSWORD_MAX = 128;
// OWASP 2025 Argon2id minimum (19 MiB, t=2): bounds memory per login so parallel logins cannot OOM the container.
const ARGON2_OPTIONS = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];
// Hash of a random discarded value with ARGON2_OPTIONS: keeps response time equal for unknown usernames.
const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=19456,t=2,p=1$SWNKZlVWbU1TY0dwNDFrSQ$SYtcULCRaGr5xz/SVFVdKvcmI5f81YPcw8jJEOTqp6I';

/* ---------- Session ---------- */

function start_session(): void
{
    session_start();
    $now = time();
    $idleExpired = isset($_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > SESSION_IDLE_SECONDS;
    $absoluteExpired = isset($_SESSION['created_at']) && $now - $_SESSION['created_at'] > SESSION_ABSOLUTE_SECONDS;
    if ($idleExpired || $absoluteExpired) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['created_at'] ??= $now;
    $_SESSION['last_activity'] = $now;
}

function establish_login_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'uid' => (int) $user['id'],
        'sv' => (int) $user['session_version'],
        'created_at' => time(),
        'last_activity' => time(),
    ];
}

function destroy_session(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/* ---------- CSRF / request guards ---------- */

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

/** Rejects non-POST requests, foreign origins and missing/invalid CSRF tokens. */
function require_valid_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Methode niet toegestaan.');
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin !== null && $origin !== app_config()['base_url']) {
        log_event('warning', 'csrf_origin_mismatch', ['ip' => client_ip()]);
        http_response_code(403);
        exit('Ongeldige herkomst.');
    }
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(400);
        exit('Formulier verlopen. Ga terug en herlaad de pagina.');
    }
}

/** REMOTE_ADDR is the real client IP: Apache mod_remoteip rewrites it from the trusted proxy only. */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/* ---------- Access control ---------- */

/** @return array<string, mixed> the logged-in user; redirects to login otherwise. */
function require_login(): array
{
    $uid = $_SESSION['uid'] ?? null;
    if (!is_int($uid)) {
        redirect('/login.php');
    }
    $stmt = db()->prepare('SELECT id, username, display_name, role, weekly_hours, region, session_version, active, password_hash
                           FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    $valid = $user !== false
        && (int) $user['active'] === 1
        && $user['password_hash'] !== null
        && (int) $user['session_version'] === ($_SESSION['sv'] ?? -1);
    if (!$valid) {
        destroy_session();
        redirect('/login.php');
    }
    unset($user['password_hash']);
    return $user;
}

const ROLE_LABELS = [
    'admin' => 'Admin',
    'staff' => 'Bureaumedewerker',
    'user' => 'Ambassadeur',
];
// Roles an admin may assign in the UI; admins are only created via bin/create-admin.php.
const ASSIGNABLE_ROLES = ['user', 'staff'];

/** Paid weekly hours only apply to ambassadors (and the admin); bureau staff have none. */
function role_has_hours(string $role): bool
{
    return $role !== 'staff';
}

function can_view_stats(array $user): bool
{
    return in_array($user['role'], ['admin', 'staff'], true);
}

/** @return array<string, mixed> logged-in admin or bureau staff; 403 otherwise. */
function require_stats_access(): array
{
    $user = require_login();
    if (!can_view_stats($user)) {
        http_response_code(403);
        exit('Geen toegang.');
    }
    return $user;
}

/** @return array<string, mixed> */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Geen toegang.');
    }
    return $user;
}

/* ---------- Brute-force protection ---------- */
// Per user only: behind the OpenVPN port-share every external client has the same IP,
// so a per-IP limit would let one attacker lock out everyone.

function attempt_key(string $username): string
{
    // Hashed so a password accidentally typed into the username field is never stored.
    return hash('sha256', mb_strtolower($username));
}

/**
 * Records the attempt BEFORE verification and then counts, so parallel requests cannot
 * all slip past the check (check-then-insert race).
 *
 * @return bool true when the attempt may proceed.
 */
function register_login_attempt(string $username): bool
{
    $now = time();
    $key = attempt_key($username);
    db()->prepare('DELETE FROM login_attempts WHERE created_at < ?')->execute([$now - ATTEMPT_WINDOW_SECONDS]);
    db()->prepare('INSERT INTO login_attempts (attempt_key, created_at) VALUES (?, ?)')->execute([$key, $now]);
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempt_key = ? AND created_at >= ?');
    $stmt->execute([$key, $now - ATTEMPT_WINDOW_SECONDS]);
    return (int) $stmt->fetchColumn() <= ATTEMPT_LIMIT_PER_USER;
}

function clear_login_attempts(string $username): void
{
    db()->prepare('DELETE FROM login_attempts WHERE attempt_key = ?')->execute([attempt_key($username)]);
}

/* ---------- Credentials ---------- */

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
}

function user_id_by_username(string $username): ?int
{
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Failed or blocked logins go to the audit table only for existing accounts (bounded by the
 * per-user limit); attempts on unknown usernames are logged to stderr only.
 */
function audit_login_problem(string $event, string $username, string $ip): void
{
    $targetId = user_id_by_username($username);
    if ($targetId === null) {
        log_event('notice', $event, ['ip' => $ip, 'known_user' => false]);
        return;
    }
    if ($event === 'login_rate_limited') {
        audit_throttled($event, null, $targetId, ATTEMPT_WINDOW_SECONDS);
        return;
    }
    audit($event, null, $targetId);
}

/** @return array<string, mixed>|null active user when the password is correct. */
function verify_password_login(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1 AND password_hash IS NOT NULL');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $passwordOk = password_verify($password, $user === false ? DUMMY_PASSWORD_HASH : $user['password_hash']);
    if ($user === false || !$passwordOk) {
        return null;
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID, ARGON2_OPTIONS)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([hash_password($password), $user['id']]);
    }
    return $user;
}

/** Atomically consumes the TOTP step so the same code cannot be used twice, even concurrently. */
function verify_totp_login(array $user, string $code): bool
{
    $step = totp_match($user['totp_secret'], $code, (int) $user['totp_last_step'], time());
    if ($step === null) {
        return false;
    }
    $stmt = db()->prepare('UPDATE users SET totp_last_step = :step WHERE id = :id AND totp_last_step < :step');
    $stmt->execute(['step' => $step, 'id' => $user['id']]);
    return $stmt->rowCount() === 1;
}

/** @return string|null error message, null when acceptable (NIST SP 800-63B: length, no composition rules). */
function password_policy_error(string $password, string $confirm, string $username): ?string
{
    $length = mb_strlen($password);
    if ($length < PASSWORD_MIN || $length > PASSWORD_MAX) {
        return 'Wachtwoord moet ' . PASSWORD_MIN . ' tot ' . PASSWORD_MAX . ' tekens zijn. Een zin van een paar woorden werkt goed.';
    }
    if (!hash_equals($password, $confirm)) {
        return 'De wachtwoorden komen niet overeen.';
    }
    if (str_contains(mb_strtolower($password), mb_strtolower($username))) {
        return 'Het wachtwoord mag je gebruikersnaam niet bevatten.';
    }
    return null;
}

/* ---------- Users and invites ---------- */

function username_is_valid(string $username): bool
{
    return (bool) preg_match('/^[a-z0-9._-]{3,32}$/', $username);
}

function create_user(string $username, string $displayName, string $role, ?int $weeklyHours, ?string $region): int
{
    db()->prepare('INSERT INTO users (username, display_name, role, weekly_hours, region, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$username, $displayName, $role, $weeklyHours, $region, time()]);
    return (int) db()->lastInsertId();
}

/**
 * Wipes credentials, ends all sessions of the user and issues a fresh one-time invite.
 *
 * @return string invite URL; only the SHA-256 hash of the token is stored.
 */
function issue_invite(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET password_hash = NULL, totp_secret = NULL, totp_last_step = 0,
                   session_version = session_version + 1 WHERE id = ?')->execute([$userId]);
    $pdo->prepare('UPDATE invites SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([time(), $userId]);
    $pdo->prepare('INSERT INTO invites (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, hash('sha256', $token), time() + INVITE_TTL_SECONDS, time()]);
    $pdo->commit();
    return app_config()['base_url'] . '/invite.php?token=' . $token;
}

/** @return array<string, mixed>|null */
function find_valid_invite(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT i.id AS invite_id, u.id AS user_id, u.username, u.display_name
                           FROM invites i JOIN users u ON u.id = i.user_id
                           WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at > ? AND u.active = 1');
    $stmt->execute([hash('sha256', $token), time()]);
    $invite = $stmt->fetch();
    return $invite === false ? null : $invite;
}

/** Consumes the invite exactly once and stores the new credentials. */
function accept_invite(array $invite, string $password, string $totpSecret, int $totpStep): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    $consume = $pdo->prepare('UPDATE invites SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?');
    $consume->execute([time(), $invite['invite_id'], time()]);
    if ($consume->rowCount() !== 1) {
        $pdo->rollBack();
        return false;
    }
    $pdo->prepare('UPDATE users SET password_hash = ?, totp_secret = ?, totp_last_step = ?,
                   session_version = session_version + 1 WHERE id = ?')
        ->execute([hash_password($password), $totpSecret, $totpStep, $invite['user_id']]);
    $pdo->commit();
    return true;
}
