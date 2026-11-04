<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$viewer = require_stats_access();
log_event('info', 'export_csv', ['user_id' => (int) $viewer['id']]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ledencontact-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 correctly.
fputcsv($out, ['datum', 'ambassadeur', 'regio', 'soort', 'gesprek_min', 'overig_min', 'energie', 'meerwaarde',
    'ten_koste_van_werk', 'inhoud', 'indruk'], ';', '"', '');
$rows = db()->query('SELECT c.*, u.display_name, u.region FROM contacts c JOIN users u ON u.id = c.user_id
                     ORDER BY c.contact_date, c.id');
foreach ($rows as $row) {
    // Only free-text fields need formula escaping; numbers stay numeric for spreadsheet analysis.
    fputcsv($out, [
        $row['contact_date'], csv_safe($row['display_name']), region_label($row['region']), CONTACT_CATEGORIES[$row['category']],
        $row['call_minutes'], $row['extra_minutes'], $row['energy'],
        $row['value_score'], (int) $row['displaced_work'] === 1 ? 'ja' : 'nee',
        csv_safe($row['content']), csv_safe($row['impression']),
    ], ';', '"', '');
}
fclose($out);
