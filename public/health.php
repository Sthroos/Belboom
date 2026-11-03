<?php
declare(strict_types=1);

// No session: healthchecks must not create session files.
require __DIR__ . '/../src/app.php';

header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
db()->query('SELECT 1');
echo 'ok';
