<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$today = new DateTimeImmutable('today');
$errors = [];
$data = empty_contact($today);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_post();
    [$data, $errors] = validate_contact($_POST, $today);
    if ($errors === []) {
        insert_contact((int) $user['id'], $data);
        log_event('info', 'contact_created', ['user_id' => (int) $user['id'], 'category' => $data['category']]);
        flash('ok', 'Contact geregistreerd.');
        redirect('/');
    }
}

render_header('Contact registreren', $user);
render_errors($errors);
render_contact_form($data, '/contact-new.php', 'Opslaan', $today);
render_footer();
