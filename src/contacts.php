<?php
declare(strict_types=1);

// Keys must match the CHECK constraints in db.php (schema v5).
const CONTACT_CATEGORIES = [
    'start' => 'Startend lid',
    'opzeg_onvrede' => 'Opzeggend lid – onvrede',
    'opzeg_neutraal' => 'Opzeggend lid – neutrale reden',
    'overig' => 'Overig',
];
const CANCEL_CATEGORIES = ['opzeg_onvrede', 'opzeg_neutraal'];
const OUTCOMES = [
    'bereikt' => 'Bereikt – gesprek gevoerd',
    'voicemail' => 'Voicemail ingesproken',
    'niet_bereikt' => 'Niet bereikt',
];
const CANCEL_REASONS = [
    'pensioen' => 'Pensioen / stoppen als huisarts',
    'verhuizing' => 'Verhuizing (andere regio of buitenland)',
    'werkvorm' => 'Andere werkvorm of loopbaanstap',
    'contributie' => 'Contributie / kosten',
    'onvrede_dienstverlening' => 'Onvrede over dienstverlening of ondersteuning',
    'onvrede_standpunt' => 'Onvrede over standpunten of belangenbehartiging',
    'andere_vereniging' => 'Overgestapt naar andere vereniging',
    'geen_reden' => 'Geen reden gegeven',
    'overig' => 'Overig',
];
const FOLLOW_UPS = [
    'geen' => 'Geen vervolgactie',
    'zelf' => 'Ambassadeur zelf',
    'bureau' => 'Regiobureau',
    'ledenadministratie' => 'Ledenadministratie',
    'bestuur' => 'Bestuur',
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
const CATEGORY_OTHER_MAX = 60;
const CANCEL_REASON_OTHER_MAX = 150;
const CONTENT_MAX = 2000;
const IMPRESSION_MAX = 1000;

function int_in_range(mixed $value, int $min, int $max): ?int
{
    $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    return $int === false ? null : $int;
}

/** Trimmed UTF-8 text without control characters (keeps newline and tab); '' for non-strings or invalid UTF-8. */
function text_field(mixed $value): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $normalized = str_replace("\r\n", "\n", $value);
    return trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $normalized));
}

/** @return string|null Y-m-d when valid, null otherwise. */
function parse_date(mixed $value): ?string
{
    $raw = text_field($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return $date !== false && $date->format('Y-m-d') === $raw ? $raw : null;
}

function option_key(mixed $value, array $options): ?string
{
    return is_string($value) && array_key_exists($value, $options) ? $value : null;
}

/** Blank form values for a new registration. */
function empty_contact(DateTimeImmutable $today): array
{
    return [
        'contact_date' => $today->format('Y-m-d'), 'category' => null, 'category_other' => '', 'outcome' => null,
        'cancel_reason' => null, 'cancel_reason_other' => '',
        'follow_up' => null, 'call_minutes' => '', 'extra_minutes' => '0', 'energy' => null, 'value_score' => null,
        'displaced_work' => null, 'content' => '', 'impression' => '',
    ];
}

/**
 * Validates a registration. Energy, value and content are required only for reached calls;
 * a cancel reason is required only for cancelling members and discarded otherwise.
 *
 * @param array<string, mixed> $input raw $_POST
 * @return array{0: array<string, int|string|null>, 1: list<string>} [clean data, errors]
 */
function validate_contact(array $input, DateTimeImmutable $today): array
{
    $errors = [];
    $date = parse_date($input['contact_date'] ?? null);
    if ($date === null || $date > $today->format('Y-m-d') || $date < $today->modify('-1 year')->format('Y-m-d')) {
        $errors[] = 'Datum is ongeldig (niet in de toekomst, maximaal een jaar terug).';
    }
    $category = option_key($input['category'] ?? null, CONTACT_CATEGORIES);
    if ($category === null) {
        $errors[] = 'Kies een soort contact.';
    }
    $categoryOther = $category === 'overig' ? text_field($input['category_other'] ?? null) : '';
    if ($category === 'overig' && ($categoryOther === '' || mb_strlen($categoryOther) > CATEGORY_OTHER_MAX)) {
        $errors[] = 'Geef bij soort contact "Overig" een korte omschrijving (maximaal ' . CATEGORY_OTHER_MAX . ' tekens).';
    }
    $outcome = option_key($input['outcome'] ?? null, OUTCOMES);
    if ($outcome === null) {
        $errors[] = 'Kies de uitkomst van de belpoging.';
    }
    $reached = $outcome === 'bereikt';
    $isCancel = in_array($category, CANCEL_CATEGORIES, true);
    $cancelReason = $isCancel ? option_key($input['cancel_reason'] ?? null, CANCEL_REASONS) : null;
    if ($isCancel && $cancelReason === null) {
        $errors[] = 'Kies de opzegreden (kies "Geen reden gegeven" als het lid niets zei of niet bereikt is).';
    }
    $reasonOther = $cancelReason === 'overig' ? text_field($input['cancel_reason_other'] ?? null) : '';
    if ($cancelReason === 'overig' && ($reasonOther === '' || mb_strlen($reasonOther) > CANCEL_REASON_OTHER_MAX)) {
        $errors[] = 'Geef bij opzegreden "Overig" een korte toelichting (maximaal ' . CANCEL_REASON_OTHER_MAX . ' tekens).';
    }
    $followUp = option_key($input['follow_up'] ?? null, FOLLOW_UPS);
    if ($followUp === null) {
        $errors[] = 'Kies of er een vervolgactie nodig is.';
    }
    // No conversation means no call minutes; the attempt itself is registered as other time.
    $callMinutes = $reached ? int_in_range($input['call_minutes'] ?? null, 1, 240) : 0;
    $extraMinutes = int_in_range($input['extra_minutes'] ?? null, 0, 240);
    if ($callMinutes === null) {
        $errors[] = 'Vul bij een gevoerd gesprek de gesprekstijd in (1–240 minuten).';
    }
    if ($extraMinutes === null || ($callMinutes === 0 && $extraMinutes < 1)) {
        $errors[] = 'Overige tijd: 0–240 minuten; bij een poging zonder gesprek minimaal 1 minuut.';
    }
    // Quality scores only describe real conversations; discarded for failed attempts.
    $energy = $reached ? int_in_range($input['energy'] ?? null, -2, 2) : null;
    $value = $reached ? int_in_range($input['value_score'] ?? null, 1, 5) : null;
    if ($reached && ($energy === null || $value === null)) {
        $errors[] = 'Kies bij een gevoerd gesprek de energie-impact en de meerwaarde.';
    }
    $displaced = int_in_range($input['displaced_work'] ?? null, 0, 1);
    if ($displaced === null) {
        $errors[] = 'Geef aan of dit ten koste ging van ander werk.';
    }
    $content = text_field($input['content'] ?? null);
    if (($reached && $content === '') || mb_strlen($content) > CONTENT_MAX) {
        $errors[] = 'Inhoud is verplicht bij een gevoerd gesprek (maximaal ' . CONTENT_MAX . ' tekens).';
    }
    $impression = text_field($input['impression'] ?? null);
    if (mb_strlen($impression) > IMPRESSION_MAX) {
        $errors[] = 'Indruk mag maximaal ' . IMPRESSION_MAX . ' tekens zijn.';
    }
    $data = [
        'contact_date' => $date ?? text_field($input['contact_date'] ?? null),
        'category' => $category,
        // Stored as NULL when not applicable; the CHECK constraint forbids text without "Overig".
        'category_other' => $categoryOther === '' ? null : $categoryOther,
        'outcome' => $outcome,
        'cancel_reason' => $cancelReason,
        'cancel_reason_other' => $reasonOther === '' ? null : $reasonOther,
        'follow_up' => $followUp,
        'call_minutes' => $callMinutes ?? 0,
        'extra_minutes' => $extraMinutes ?? 0,
        'energy' => $energy,
        'value_score' => $value,
        'displaced_work' => $displaced ?? 0,
        'content' => $content,
        'impression' => $impression,
    ];
    return [$data, $errors];
}

const CONTACT_WRITE_COLUMNS = ['contact_date', 'category', 'category_other', 'outcome', 'cancel_reason',
    'cancel_reason_other', 'follow_up', 'call_minutes',
    'extra_minutes', 'energy', 'value_score', 'displaced_work', 'content', 'impression'];

function contact_values(array $data): array
{
    return array_map(static fn (string $column) => $data[$column], CONTACT_WRITE_COLUMNS);
}

function insert_contact(int $userId, array $data): void
{
    $columns = implode(', ', CONTACT_WRITE_COLUMNS);
    $placeholders = implode(', ', array_fill(0, count(CONTACT_WRITE_COLUMNS), '?'));
    db()->prepare("INSERT INTO contacts (user_id, {$columns}, created_at) VALUES (?, {$placeholders}, ?)")
        ->execute([$userId, ...contact_values($data), time()]);
}

function update_own_contact(int $contactId, int $userId, array $data): bool
{
    $assignments = implode(', ', array_map(static fn (string $column) => "{$column} = ?", CONTACT_WRITE_COLUMNS));
    $stmt = db()->prepare("UPDATE contacts SET {$assignments}, updated_at = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([...contact_values($data), time(), $contactId, $userId]);
    return $stmt->rowCount() === 1;
}

/** @return array<string, mixed>|null */
function find_own_contact(int $contactId, int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM contacts WHERE id = ? AND user_id = ?');
    $stmt->execute([$contactId, $userId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function delete_own_contact(int $contactId, int $userId): bool
{
    $stmt = db()->prepare('DELETE FROM contacts WHERE id = ? AND user_id = ?');
    $stmt->execute([$contactId, $userId]);
    return $stmt->rowCount() === 1;
}

/* ---------- Read models ---------- */

/**
 * @param array<string, mixed> $query raw $_GET
 * @return array{region: ?string, from: ?string, to: ?string}
 */
function parse_stats_filter(array $query): array
{
    $from = parse_date($query['van'] ?? null);
    $to = parse_date($query['tot'] ?? null);
    if ($from !== null && $to !== null && $from > $to) {
        [$from, $to] = [$to, $from];
    }
    return ['region' => parse_region($query['regio'] ?? null), 'from' => $from, 'to' => $to];
}

function filter_query_string(array $filter): string
{
    return http_build_query(array_filter(['regio' => $filter['region'], 'van' => $filter['from'], 'tot' => $filter['to']]));
}

const STATS_COLUMNS = 'COUNT(c.id) AS n,
    COALESCE(SUM(c.outcome = \'bereikt\'), 0) AS reached,
    COALESCE(SUM(c.call_minutes), 0) AS call_min,
    COALESCE(SUM(c.extra_minutes), 0) AS extra_min,
    AVG(c.energy) AS avg_energy,
    AVG(c.value_score) AS avg_value,
    COALESCE(SUM(c.displaced_work), 0) AS displaced';

// NULL parameters mean "no filter"; SQLite allows reusing named parameters.
const DATE_FILTER = '(:from IS NULL OR c.contact_date >= :from) AND (:to IS NULL OR c.contact_date <= :to)';
const REGION_FILTER = '(:region IS NULL OR u.region = :region)';
const CONTACTS_FROM = 'FROM contacts c JOIN users u ON u.id = c.user_id WHERE ' . REGION_FILTER . ' AND ' . DATE_FILTER;
// Date conditions belong in the ON clause so users without contacts in the period still appear.
const USERS_FROM = 'FROM users u LEFT JOIN contacts c ON c.user_id = u.id AND ' . DATE_FILTER . '
    WHERE u.active = 1 AND ' . REGION_FILTER;

const EMPTY_STATS = ['n' => 0, 'reached' => 0, 'call_min' => 0, 'extra_min' => 0, 'avg_energy' => null,
    'avg_value' => null, 'displaced' => 0];

function stats_query(string $sql, array $filter): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute(['region' => $filter['region'], 'from' => $filter['from'], 'to' => $filter['to']]);
    return $stmt->fetchAll();
}

function stats_total(array $filter): array
{
    return stats_query('SELECT ' . STATS_COLUMNS . ', MIN(c.contact_date) AS first_date, MAX(c.contact_date) AS last_date '
        . CONTACTS_FROM, $filter)[0];
}

/**
 * Stats grouped by a whitelisted contact column, every option present in display order.
 *
 * @param array<string, string> $options keys of the column
 * @return array<string, array<string, mixed>>
 */
function stats_grouped(string $column, array $options, array $filter): array
{
    if (!in_array($column, ['category', 'outcome', 'cancel_reason', 'follow_up'], true)) {
        throw new LogicException('Column not groupable');
    }
    $rows = stats_query("SELECT c.{$column} AS k, " . STATS_COLUMNS . ' ' . CONTACTS_FROM
        . " AND c.{$column} IS NOT NULL GROUP BY c.{$column}", $filter);
    $byKey = array_column($rows, null, 'k');
    $result = [];
    foreach (array_keys($options) as $key) {
        $result[$key] = $byKey[$key] ?? EMPTY_STATS;
    }
    return $result;
}

function stats_by_user(array $filter): array
{
    return stats_query('SELECT u.display_name, u.region, u.weekly_hours, ' . STATS_COLUMNS . ' ' . USERS_FROM
        . ' GROUP BY u.id ORDER BY u.display_name', $filter);
}

function stats_by_region(array $filter): array
{
    return stats_query('SELECT u.region, COUNT(DISTINCT u.id) AS users, ' . STATS_COLUMNS . ' ' . USERS_FROM
        . ' GROUP BY u.region ORDER BY u.region', $filter);
}

function stats_by_month(array $filter): array
{
    return stats_query('SELECT substr(c.contact_date, 1, 7) AS month, ' . STATS_COLUMNS . ' ' . CONTACTS_FROM
        . ' GROUP BY month ORDER BY month DESC LIMIT 12', $filter);
}

function recent_contacts(array $filter, int $limit): array
{
    $stmt = db()->prepare('SELECT c.*, u.display_name, u.region ' . CONTACTS_FROM
        . ' ORDER BY c.contact_date DESC, c.id DESC LIMIT :limit');
    $stmt->execute(['region' => $filter['region'], 'from' => $filter['from'], 'to' => $filter['to'], 'limit' => $limit]);
    return $stmt->fetchAll();
}

/**
 * Explanations given with "Overig", grouped case-insensitively so recurring answers stand out.
 *
 * @return list<array{text: string, n: int}>
 */
function other_explanations(string $column, array $filter, int $limit): array
{
    if (!in_array($column, ['category_other', 'cancel_reason_other'], true)) {
        throw new LogicException('Column has no explanations');
    }
    $stmt = db()->prepare("SELECT MIN(c.{$column}) AS text, COUNT(*) AS n " . CONTACTS_FROM
        . " AND c.{$column} IS NOT NULL GROUP BY lower(c.{$column}) ORDER BY n DESC, text LIMIT :limit");
    $stmt->execute(['region' => $filter['region'], 'from' => $filter['from'], 'to' => $filter['to'], 'limit' => $limit]);
    return $stmt->fetchAll();
}

/** Qualitative impressions for the report: no names, no contact content. */
function impressions(array $filter, int $limit): array
{
    $stmt = db()->prepare('SELECT c.contact_date, c.category, c.impression, u.region ' . CONTACTS_FROM
        . " AND c.impression <> '' ORDER BY c.contact_date DESC, c.id DESC LIMIT :limit");
    $stmt->execute(['region' => $filter['region'], 'from' => $filter['from'], 'to' => $filter['to'], 'limit' => $limit]);
    return $stmt->fetchAll();
}

function own_contacts(int $userId, int $limit): array
{
    $stmt = db()->prepare('SELECT * FROM contacts WHERE user_id = ? ORDER BY contact_date DESC, id DESC LIMIT ?');
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

/** Weeks covered by the filter (or by the registrations when open-ended), minimum 1. */
function period_weeks(array $filter, array $total, DateTimeImmutable $today): float
{
    $start = $filter['from'] ?? $total['first_date'];
    if ($start === null) {
        return 1.0;
    }
    $end = $filter['to'] ?? $today->format('Y-m-d');
    $days = (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
    return max(1.0, $days / 7);
}

function total_minutes(array $row): int
{
    return (int) $row['call_min'] + (int) $row['extra_min'];
}

/** Prevents spreadsheet formula injection when the CSV is opened in Excel/LibreOffice. */
function csv_safe(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}
