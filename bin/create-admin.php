<?php
declare(strict_types=1);

/*
 * Creates the admin account, or resets an existing admin's credentials, and prints a one-time invite link.
 * Usage: php bin/create-admin.php <username> "<display name>" <weekly hours>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/app.php';

[, $username, $displayName, $hours] = $argv + [null, '', '', ''];
$username = mb_strtolower((string) $username);
$displayName = trim((string) $displayName);
$weeklyHours = int_in_range($hours, 1, 40);
if (!username_is_valid($username) || $displayName === '' || mb_strlen($displayName) > 80 || $weeklyHours === null) {
    fwrite(STDERR, "Gebruik: php bin/create-admin.php <gebruikersnaam a-z0-9._-> \"<naam>\" <uren per week 1-40>\n");
    exit(1);
}

$stmt = db()->prepare('SELECT id, role FROM users WHERE username = ?');
$stmt->execute([$username]);
$existing = $stmt->fetch();
if ($existing !== false && $existing['role'] !== 'admin') {
    fwrite(STDERR, "Gebruiker bestaat al en is geen admin.\n");
    exit(1);
}
$userId = $existing === false ? create_user($username, $displayName, 'admin', $weeklyHours, null) : (int) $existing['id'];
$link = issue_invite($userId);
audit($existing === false ? 'admin_created_cli' : 'admin_reset_cli', null, $userId);
fwrite(STDOUT, "Uitnodigingslink (72 uur geldig, eenmalig):\n{$link}\n");
