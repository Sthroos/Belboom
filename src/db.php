<?php
declare(strict_types=1);

const SCHEMA_VERSION = 3;

/**
 * Returns the shared PDO connection; migrates the schema on first use.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . app_config()['db_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    // Overwrite deleted rows on disk instead of leaving them in free pages.
    $pdo->exec('PRAGMA secure_delete = ON');
    migrate($pdo);
    return $pdo;
}

/** Applies pending migrations under an exclusive write lock so concurrent requests cannot run them twice. */
function migrate(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() === SCHEMA_VERSION) {
        return;
    }
    // Table rebuilds (v3) require foreign key enforcement off; it cannot change inside a transaction.
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            $pdo->exec(SCHEMA_V1);
        }
        if ($version < 2) {
            $pdo->exec(<<<'SQL'
                ALTER TABLE users ADD COLUMN region TEXT;
                DROP TABLE IF EXISTS login_failures;
                CREATE TABLE login_attempts (
                    id INTEGER PRIMARY KEY,
                    attempt_key TEXT NOT NULL,
                    created_at INTEGER NOT NULL
                ) STRICT;
                CREATE INDEX idx_login_attempts_key ON login_attempts (attempt_key, created_at);
                SQL);
        }
        if ($version < 3) {
            // SQLite cannot alter a CHECK constraint: rebuild the table to allow the 'staff' role.
            $pdo->exec(<<<'SQL'
                CREATE TABLE users_v3 (
                    id INTEGER PRIMARY KEY,
                    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                    display_name TEXT NOT NULL,
                    role TEXT NOT NULL CHECK (role IN ('admin', 'staff', 'user')),
                    weekly_hours INTEGER NOT NULL CHECK (weekly_hours BETWEEN 1 AND 40),
                    region TEXT,
                    password_hash TEXT,
                    totp_secret TEXT,
                    totp_last_step INTEGER NOT NULL DEFAULT 0,
                    session_version INTEGER NOT NULL DEFAULT 0,
                    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                    created_at INTEGER NOT NULL,
                    last_login_at INTEGER
                ) STRICT;
                INSERT INTO users_v3 (id, username, display_name, role, weekly_hours, region, password_hash, totp_secret,
                                      totp_last_step, session_version, active, created_at, last_login_at)
                SELECT id, username, display_name, role, weekly_hours, region, password_hash, totp_secret,
                       totp_last_step, session_version, active, created_at, last_login_at FROM users;
                DROP TABLE users;
                ALTER TABLE users_v3 RENAME TO users;
                SQL);
            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('Foreign key violation after users table rebuild');
            }
        }
        $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

// IF NOT EXISTS: installs created before versioned migrations already have these tables.
const SCHEMA_V1 = <<<'SQL'
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        display_name TEXT NOT NULL,
        role TEXT NOT NULL CHECK (role IN ('admin', 'user')),
        weekly_hours INTEGER NOT NULL CHECK (weekly_hours BETWEEN 1 AND 40),
        password_hash TEXT,
        totp_secret TEXT,
        totp_last_step INTEGER NOT NULL DEFAULT 0,
        session_version INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
        created_at INTEGER NOT NULL,
        last_login_at INTEGER
    ) STRICT;

    CREATE TABLE IF NOT EXISTS invites (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id),
        token_hash TEXT NOT NULL UNIQUE,
        expires_at INTEGER NOT NULL,
        used_at INTEGER,
        created_at INTEGER NOT NULL
    ) STRICT;

    CREATE TABLE IF NOT EXISTS login_failures (
        id INTEGER PRIMARY KEY,
        attempt_key TEXT NOT NULL,
        created_at INTEGER NOT NULL
    ) STRICT;

    CREATE TABLE IF NOT EXISTS contacts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id),
        contact_date TEXT NOT NULL,
        category TEXT NOT NULL CHECK (category IN ('start', 'opzeg_onvrede', 'opzeg_neutraal', 'overig')),
        call_minutes INTEGER NOT NULL CHECK (call_minutes BETWEEN 1 AND 240),
        extra_minutes INTEGER NOT NULL CHECK (extra_minutes BETWEEN 0 AND 240),
        energy INTEGER NOT NULL CHECK (energy BETWEEN -2 AND 2),
        value_score INTEGER NOT NULL CHECK (value_score BETWEEN 1 AND 5),
        displaced_work INTEGER NOT NULL CHECK (displaced_work IN (0, 1)),
        content TEXT NOT NULL,
        impression TEXT NOT NULL,
        created_at INTEGER NOT NULL
    ) STRICT;
    CREATE INDEX IF NOT EXISTS idx_contacts_date ON contacts (contact_date);
    SQL;
