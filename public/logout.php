<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_valid_post();
if (is_int($_SESSION['uid'] ?? null)) {
    audit('logout', $_SESSION['uid'], $_SESSION['uid']);
}
destroy_session();
redirect('/login.php');
