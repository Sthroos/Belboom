<?php
declare(strict_types=1);

const CONTACT_CATEGORIES = [
    'start' => 'Startend lid',
    'opzeg_onvrede' => 'Opzeggend lid – onvrede',
    'opzeg_neutraal' => 'Opzeggend lid – neutrale reden',
    'overig' => 'Overig',
];
const ENERGY_LABELS = [
    -2 => 'Kost veel energie',
    -1 => 'Kost wat energie',
    0 => 'Neutraal',
    1 => 'Levert wat energie op',
    2 => 'Levert veel energie op',
];
const VALUE_LABELS = [
    1 => 'Geen meerwaarde',
    2 => 'Weinig meerwaarde',
    3 => 'Enige meerwaarde',
    4 => 'Duidelijke meerwaarde',
    5 => 'Grote meerwaarde',
];
const CONTENT_MAX = 2000;
const IMPRESSION_MAX = 1000;

function int_in_range(mixed $value, int $min, int $max): ?int
{
    $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    return $int === false ? null : $int;
}

function text_field(mixed $value): string
{
    return is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
}

/**
 * @param array<string, mixed> $input raw $_POST
 * @return array{0: array<string, int|string>, 1: list<string>} [clean data, errors]
 */
function validate_contact(array $input, DateTimeImmutable $today): array
{
    $errors = [];
    $dateRaw = text_field($input['contact_date'] ?? null);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw);
    if ($date === false || $date->format('Y-m-d') !== $dateRaw || $date > $today || $date < $today->modify('-1 year')) {
        $errors[] = 'Datum is ongeldig (niet in de toekomst, maximaal een jaar terug).';
    }
    $category = text_field($input['category'] ?? null);
    if (!array_key_exists($category, CONTACT_CATEGORIES)) {
        $errors[] = 'Kies een soort contact.';
    }
    $callMinutes = int_in_range($input['call_minutes'] ?? null, 1, 240);
    if ($callMinutes === null) {
        $errors[] = 'Gesprekstijd moet tussen 1 en 240 minuten liggen.';
    }
    $extraMinutes = int_in_range($input['extra_minutes'] ?? null, 0, 240);
    if ($extraMinutes === null) {
        $errors[] = 'Overige tijd moet tussen 0 en 240 minuten liggen.';
    }
    $energy = int_in_range($input['energy'] ?? null, -2, 2);
    if ($energy === null) {
        $errors[] = 'Kies de energie-impact.';
    }
    $value = int_in_range($input['value_score'] ?? null, 1, 5);
    if ($value === null) {
        $errors[] = 'Kies de meerwaarde.';
    }
    $displaced = int_in_range($input['displaced_work'] ?? null, 0, 1);
    if ($displaced === null) {
        $errors[] = 'Geef aan of dit ten koste ging van ander werk.';
    }
    $content = text_field($input['content'] ?? null);
    if ($content === '' || mb_strlen($content) > CONTENT_MAX) {
        $errors[] = 'Inhoud is verplicht (maximaal ' . CONTENT_MAX . ' tekens).';
    }
    $impression = text_field($input['impression'] ?? null);
    if (mb_strlen($impression) > IMPRESSION_MAX) {
        $errors[] = 'Indruk mag maximaal ' . IMPRESSION_MAX . ' tekens zijn.';
    }
    $data = [
        'contact_date' => $dateRaw,
        'category' => $category,
        'call_minutes' => $callMinutes ?? 0,
        'extra_minutes' => $extraMinutes ?? 0,
        'energy' => $energy ?? 0,
        'value_score' => $value ?? 0,
        'displaced_work' => $displaced ?? 0,
        'content' => $content,
        'impression' => $impression,
    ];
    return [$data, $errors];
}

function insert_contact(int $userId, array $data): void
{
    db()->prepare('INSERT INTO contacts (user_id, contact_date, category, call_minutes, extra_minutes, energy,
                   value_score, displaced_work, content, impression, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $userId, $data['contact_date'], $data['category'], $data['call_minutes'], $data['extra_minutes'],
            $data['energy'], $data['value_score'], $data['displaced_work'], $data['content'], $data['impression'], time(),
        ]);
}

function delete_own_contact(int $contactId, int $userId): bool
{
    $stmt = db()->prepare('DELETE FROM contacts WHERE id = ? AND user_id = ?');
    $stmt->execute([$contactId, $userId]);
    return $stmt->rowCount() === 1;
}

/* ---------- Read models for the dashboard ---------- */

const STATS_COLUMNS = 'COUNT(*) AS n,
    COALESCE(SUM(call_minutes), 0) AS call_min,
    COALESCE(SUM(extra_minutes), 0) AS extra_min,
    AVG(energy) AS avg_energy,
    AVG(value_score) AS avg_value,
    COALESCE(SUM(displaced_work), 0) AS displaced';

function stats_total(): array
{
    $row = db()->query('SELECT ' . STATS_COLUMNS . ', MIN(contact_date) AS first_date FROM contacts')->fetch();
    return $row;
}

/** @return array<string, array<string, mixed>> keyed by category, every category present. */
function stats_by_category(): array
{
    $rows = db()->query('SELECT category, ' . STATS_COLUMNS . ' FROM contacts GROUP BY category')->fetchAll();
    $byCategory = array_column($rows, null, 'category');
    $result = [];
    foreach (array_keys(CONTACT_CATEGORIES) as $key) {
        $result[$key] = $byCategory[$key]
            ?? ['n' => 0, 'call_min' => 0, 'extra_min' => 0, 'avg_energy' => null, 'avg_value' => null, 'displaced' => 0];
    }
    return $result;
}

function stats_by_user(): array
{
    return db()->query('SELECT u.display_name, u.weekly_hours, ' . STATS_COLUMNS . '
                        FROM users u LEFT JOIN contacts c ON c.user_id = u.id
                        WHERE u.active = 1 GROUP BY u.id ORDER BY u.display_name')->fetchAll();
}

function stats_by_month(): array
{
    return db()->query("SELECT substr(contact_date, 1, 7) AS month, " . STATS_COLUMNS . "
                        FROM contacts GROUP BY month ORDER BY month DESC LIMIT 12")->fetchAll();
}

function recent_contacts(int $limit): array
{
    $stmt = db()->prepare('SELECT c.*, u.display_name FROM contacts c JOIN users u ON u.id = c.user_id
                           ORDER BY c.contact_date DESC, c.id DESC LIMIT ?');
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/** Pilot weeks from the first registration until today, minimum 1. */
function pilot_weeks(?string $firstDate, DateTimeImmutable $today): float
{
    if ($firstDate === null) {
        return 1.0;
    }
    $days = (int) (new DateTimeImmutable($firstDate))->diff($today)->days + 1;
    return max(1.0, $days / 7);
}

/** Prevents spreadsheet formula injection when the CSV is opened in Excel/LibreOffice. */
function csv_safe(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}
