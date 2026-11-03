<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_valid_post();
destroy_session();
redirect('/login.php');
