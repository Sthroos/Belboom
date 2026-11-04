<?php
declare(strict_types=1);

const AUDIT_RETENTION_SECONDS = 15552000; // 180 days: long enough for the pilot, short enough for data minimization.

const AUDIT_EVENT_LABELS = [
    'login_success' => 'Ingelogd',
    'login_password_failed' => 'Inloggen mislukt (wachtwoord)',
    'login_totp_failed' => 'Inloggen mislukt (2FA-code)',
    'login_rate_limited' => 'Inloggen geblokkeerd (te veel pogingen)',
    'logout' => 'Uitgelogd',
    'invite_accepted' => 'Account geactiveerd',
    'password_changed' => 'Wachtwoord gewijzigd',
    'user_created' => 'Gebruiker aangemaakt',
    'user_updated' => 'Gebruiker gewijzigd',
    'user_reinvite' => 'Nieuwe uitnodiging (reset)',
    'user_deactivate' => 'Gebruiker gedeactiveerd',
    'user_activate' => 'Gebruiker geactiveerd',
    'admin_created_cli' => 'Admin aangemaakt (CLI)',
    'admin_reset_cli' => 'Admin gereset (CLI)',
    'export_csv' => 'CSV geëxporteerd',
    'contact_updated' => 'Registratie gewijzigd',
    'contact_deleted' => 'Registratie verwijderd',
];

/**
 * Records a security-relevant event in the audit log and in docker logs.
 * $detail must never contain passwords, tokens or contact content.
 */
function audit(string $event, ?int $actorUserId, ?int $targetUserId = null, ?string $detail = null): void
{
    if (!array_key_exists($event, AUDIT_EVENT_LABELS)) {
        throw new LogicException('Unknown audit event');
    }
    $ip = PHP_SAPI === 'cli' ? 'cli' : client_ip();
    $now = time();
    db()->prepare('INSERT INTO audit_log (created_at, event, actor_user_id, target_user_id, ip, detail) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$now, $event, $actorUserId, $targetUserId, $ip, $detail]);
    db()->prepare('DELETE FROM audit_log WHERE created_at < ?')->execute([$now - AUDIT_RETENTION_SECONDS]);
    log_event('info', $event, ['actor_user_id' => $actorUserId, 'target_user_id' => $targetUserId, 'ip' => $ip, 'detail' => $detail]);
}

/**
 * Like audit(), but writes to the database at most once per window per event and target, so an
 * attacker hammering a locked account cannot grow the audit table without bound.
 */
function audit_throttled(string $event, ?int $actorUserId, int $targetUserId, int $windowSeconds): void
{
    $stmt = db()->prepare('SELECT 1 FROM audit_log WHERE event = ? AND target_user_id = ? AND created_at >= ?');
    $stmt->execute([$event, $targetUserId, time() - $windowSeconds]);
    if ($stmt->fetchColumn() !== false) {
        log_event('info', $event, ['target_user_id' => $targetUserId, 'ip' => client_ip(), 'throttled' => true]);
        return;
    }
    audit($event, $actorUserId, $targetUserId);
}

/** @return list<array<string, mixed>> newest first, with display names resolved. */
function recent_audit_entries(?string $event, int $limit): array
{
    $stmt = db()->prepare('SELECT a.*, actor.display_name AS actor_name, target.display_name AS target_name
                           FROM audit_log a
                           LEFT JOIN users actor ON actor.id = a.actor_user_id
                           LEFT JOIN users target ON target.id = a.target_user_id
                           WHERE (:event IS NULL OR a.event = :event)
                           ORDER BY a.id DESC LIMIT :limit');
    $stmt->execute(['event' => $event, 'limit' => $limit]);
    return $stmt->fetchAll();
}
