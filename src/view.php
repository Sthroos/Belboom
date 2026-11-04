<?php
declare(strict_types=1);

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Only accepts local absolute paths to rule out open redirects. */
function redirect(string $path): never
{
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
        throw new LogicException('Redirect target must be a local path');
    }
    header('Location: ' . $path, true, 303);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function fmt_minutes(int $minutes): string
{
    return intdiv($minutes, 60) . ' u ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . ' min';
}

function fmt_number(?float $value, int $decimals = 1): string
{
    return $value === null ? '–' : number_format($value, $decimals, ',', '.');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function render_header(string $title, ?array $user): void
{
    ?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Ledencontact</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="top no-print">
  <span class="brand">Ledencontact · pilot</span>
  <?php if ($user !== null): ?>
  <nav>
    <a href="/">Mijn registraties</a>
    <a href="/contact-new.php">Contact registreren</a>
    <?php if (can_view_stats($user)): ?>
    <a href="/stats.php">Statistieken</a>
    <a href="/report.php">Rapport</a>
    <a href="/export.php">Export CSV</a>
    <?php endif; ?>
    <?php if ($user['role'] === 'admin'): ?><a href="/users.php">Gebruikers</a><a href="/audit.php">Logboek</a><?php endif; ?>
    <a href="/password.php">Wachtwoord</a>
    <form method="post" action="/logout.php" class="inline">
      <?= csrf_field() ?>
      <button type="submit" class="link">Uitloggen (<?= e($user['display_name']) ?>)</button>
    </form>
  </nav>
  <?php endif; ?>
</header>
<main>
<h1><?= e($title) ?></h1>
<?php foreach (take_flashes() as $flash): ?>
<p class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
<?php endforeach;
}

function render_footer(): void
{
    echo "</main>\n</body>\n</html>\n";
}

/** @param list<string> $errors */
function render_errors(array $errors): void
{
    if ($errors === []) {
        return;
    }
    echo '<ul class="flash error">';
    foreach ($errors as $error) {
        echo '<li>' . e($error) . '</li>';
    }
    echo '</ul>';
}

/** @param array<int|string, string> $options */
function render_options(array $options, string|int|null $selected): void
{
    foreach ($options as $value => $label) {
        $isSelected = $selected !== null && (string) $selected === (string) $value ? ' selected' : '';
        echo '<option value="' . e($value) . '"' . $isSelected . '>' . e($label) . '</option>';
    }
}
