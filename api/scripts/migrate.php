<?php

/**
 * Safe migration runner — applies pending database/migrations/*.sql once each.
 *
 * Usage:
 *   php api/scripts/migrate.php [--dry-run] [--baseline[=prefix]] [--force]
 *
 * Behavior:
 * - Scans database/migrations/*.sql in numeric order, skips filenames
 *   already recorded in schema_migrations, warns (never re-applies) when a
 *   recorded file's on-disk checksum drifted.
 * - Executes each pending file statement-by-statement in AUTOCOMMIT — no
 *   wrapping transaction, because MySQL/MariaDB implicitly commit DDL
 *   (ALTER/CREATE TABLE). A file is recorded only after ALL of its
 *   statements succeed; on failure the runner stops without recording, so a
 *   re-run retries the same file. Migration files must therefore stay
 *   idempotent (INFORMATION_SCHEMA guards); historical files are NEVER
 *   rewritten — new changes are new files.
 * - --dry-run is fully read-only: it never creates schema_migrations and
 *   never executes anything; it only lists pending files (+ statement
 *   counts) and checksum drift.
 * - --baseline records pending files as applied WITHOUT executing them, for
 *   adopting a database that was already migrated manually (the legacy
 *   `mysql < file.sql` path). Only use it when the schema already contains
 *   those migrations' effects. Optional =prefix limits adoption to matching
 *   filenames (e.g. --baseline=01 for the historical range).
 * - Fresh installs must use database/schema.sql (snapshot), not this runner:
 *   replaying 001→019 from scratch fails at 010 (duplicate column after 008
 *   — by design; see database/migrations/README.md). If the target database
 *   already holds application tables but no migration history, the runner
 *   aborts and points at --baseline instead of executing (override with
 *   --force, at your own risk, after a backup).
 * - Fails loudly on any statement error; stops before recording success.
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\ErrorLogger;
use App\Core\MigrationRunner;

Env::load(__DIR__ . '/../.env');

$argvList = $argv ?? [];
$dryRun = in_array('--dry-run', $argvList, true);
$force = in_array('--force', $argvList, true);
$baselinePrefix = null;
$baseline = false;
foreach ($argvList as $arg) {
    if ($arg === '--baseline') {
        $baseline = true;
    } elseif (str_starts_with($arg, '--baseline=')) {
        $baseline = true;
        $baselinePrefix = substr($arg, strlen('--baseline='));
    }
}

if ($dryRun && $baseline) {
    fwrite(STDERR, "--dry-run and --baseline are mutually exclusive.\n");
    exit(1);
}

$migrationsDir = dirname(__DIR__, 2) . '/database/migrations';

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
    exit(1);
}

$pdo = Database::get();
$runner = new MigrationRunner($pdo, $migrationsDir);

// Checksum drift is reported in every mode (never re-applied).
foreach ($runner->drifted() as $name) {
    fwrite(STDERR, "WARNING: {$name} checksum changed since it was applied (history must be append-only).\n");
}

if ($dryRun) {
    $pending = $runner->pending();
    if ($pending === []) {
        echo "Migrations: 0 pending (all applied).\n";
        exit(0);
    }
    $counts = $runner->countStatements($pending);
    echo 'Migrations pending (dry-run, database untouched): ' . count($pending) . "\n";
    foreach ($pending as $file) {
        echo ' - ' . $file['name'] . ' (' . $counts[$file['name']] . " executable statements)\n";
    }
    exit(0);
}

if ($baseline) {
    $recorded = $runner->baseline($baselinePrefix);
    if ($recorded === []) {
        echo "Baseline: nothing to record (no pending migrations"
            . ($baselinePrefix !== null ? " with prefix '{$baselinePrefix}'" : '') . ").\n";
        exit(0);
    }
    echo 'Baseline recorded (NOT executed — only use when the schema already contains these effects): '
        . count($recorded) . "\n";
    foreach ($recorded as $name) {
        echo ' - ' . $name . "\n";
    }
    exit(0);
}

// Guard: a database with application tables but zero migration history was
// migrated manually — executing 001.. from scratch would fail (duplicate
// tables at 001, duplicate columns at 010). Adopt it instead of breaking it.
if (!$force && $runner->looksManuallyMigrated()) {
    fwrite(STDERR, "Refusing to execute migrations: the target database already holds application tables but has no migration history.\n");
    fwrite(STDERR, "It was migrated manually. Adopt it with: php api/scripts/migrate.php --baseline\n");
    fwrite(STDERR, "(Only for databases whose schema already contains the migrations' effects. Override with --force after a backup.)\n");
    exit(1);
}

$pending = $runner->pending();
if ($pending === []) {
    echo "Migrations: 0 pending (all applied).\n";
    exit(0);
}

echo 'Migrations pending: ' . count($pending) . "\n";
try {
    foreach ($runner->migrate() as $name) {
        echo "  applied {$name}\n";
    }
} catch (Throwable $e) {
    // Structured record: static message + safe metadata only (filename /
    // statement index). The raw PDO/SQL text is never passed to the logger.
    ErrorLogger::logEvent('warning', 'Migration failed', null, MigrationRunner::failureContext($e));
    // CLI keeps the useful detail, sanitized for secrets/DSNs/tokens first.
    fwrite(STDERR, 'FAILED: ' . MigrationRunner::sanitizeCliMessage($e->getMessage()) . "\n");
    fwrite(STDERR, "(Nothing was recorded for the failed file — fix the cause, restore from backup if needed, and re-run.)\n");
    exit(1);
}
