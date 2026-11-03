<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/totp.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/contacts.php';
require __DIR__ . '/view.php';

/**
 * Reads and validates required environment configuration once.
 *
 * @return array{base_url: string, db_path: string}
 * @throws RuntimeException when a required variable is missing or invalid.
 */
function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $baseUrl = getenv('APP_BASE_URL');
    if (!is_string($baseUrl) || !preg_match('~^https://[a-z0-9.-]+(:\d+)?$~i', $baseUrl)) {
        throw new RuntimeException('APP_BASE_URL must be an https:// origin without path');
    }
    $dbPath = getenv('APP_DB_PATH');
    if (!is_string($dbPath) || $dbPath === '') {
        throw new RuntimeException('APP_DB_PATH is required');
    }
    $config = ['base_url' => $baseUrl, 'db_path' => $dbPath];
    return $config;
}

/**
 * Writes one structured JSON log line to stderr (docker logs). Never pass PII, passwords or tokens.
 *
 * @param array<string, scalar|null> $context
 */
function log_event(string $level, string $event, array $context = []): void
{
    $line = ['ts' => gmdate('c'), 'level' => $level, 'event' => $event] + $context;
    file_put_contents('php://stderr', json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
}

set_exception_handler(static function (Throwable $e): void {
    log_event('error', 'unhandled_exception', [
        'class' => $e::class,
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Fout: zie log.\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Er ging iets mis. Probeer het later opnieuw.';
});
