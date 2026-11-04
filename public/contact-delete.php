<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
require_valid_post();
$contactId = int_in_range($_POST['id'] ?? null, 1, PHP_INT_MAX);
if ($contactId !== null && delete_own_contact($contactId, (int) $user['id'])) {
    audit('contact_deleted', (int) $user['id'], (int) $user['id'], 'contact ' . $contactId);
    flash('ok', 'Registratie verwijderd.');
} else {
    flash('error', 'Registratie niet gevonden of niet van jou.');
}
redirect('/');
