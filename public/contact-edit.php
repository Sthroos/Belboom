<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$userId = (int) $user['id'];
$today = new DateTimeImmutable('today');
$contactId = int_in_range($_POST['id'] ?? $_GET['id'] ?? null, 1, PHP_INT_MAX);
$existing = $contactId === null ? null : find_own_contact($contactId, $userId);
if ($existing === null) {
    http_response_code(404);
    exit('Registratie niet gevonden of niet van jou.');
}
$errors = [];
$data = $existing;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_post();
    [$data, $errors] = validate_contact($_POST, $today);
    if ($errors === []) {
        if (!update_own_contact($contactId, $userId, $data)) {
            throw new RuntimeException('Contact update affected no rows');
        }
        audit('contact_updated', $userId, $userId, 'contact ' . $contactId);
        flash('ok', 'Registratie bijgewerkt.');
        redirect('/');
    }
}

render_header('Registratie bewerken', $user);
render_errors($errors);
render_contact_form($data, '/contact-edit.php', 'Wijzigingen opslaan', $today, $contactId);
render_footer();
