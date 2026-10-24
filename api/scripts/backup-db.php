<?php

declare(strict_types=1);

/**
 * backup-db.php — create a compressed logical backup of the application DB.
 *
 * Usage:
 *   php api/scripts/backup-db.php
 *
 * Configuration (api/.env unless overridden by a real environment variable):
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS   connection (same as the API)
 *   MYSQLDUMP_BIN=mysqldump                       executable name/path
 *   DB_BACKUP_DIR=<repo>/backups                   output directory
 *   DB_BACKUP_RETENTION_DAYS=30                   delete generated dumps older
 *                                                 than this (0 = keep forever)
 *
 * Safety:
 * - Credentials are written to a short-lived 0600 defaults-extra-file and
 *   removed in a finally block; they never appear in process arguments.
 * - A non-blocking lock prevents two cron runs from overlapping.
 * - The dump is streamed through gzip into a .tmp file and renamed only on
 *   success, so an interrupted run never leaves a plausible-looking backup.
 * - Retention only deletes generated files matching
 *   lucebianca_YYYYmmdd_HHMMSS.sql.gz. Manual backups with other names are
 *   never touched. Generated dumps are already gitignored (backups/).
 *
 * Exit codes: 0 = success (or another run holds the lock), 1 = failure.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'CLI-only script — refusing to run over HTTP.' . PHP_EOL);
    exit(1);
}

require __DIR__ . '/../src/autoload.php';

use App\Core\Env;

// Tests point this at an isolated env file; production uses api/.env.
$envFile = getenv('LB_TEST_ENV_FILE');
Env::load($envFile !== false && $envFile !== '' ? $envFile : __DIR__ . '/../.env');

/**
 * Reads a setting from the real environment first, then the loaded .env.
 */
function backupSetting(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = Env::get($key);
    }
    if ($value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

$host = backupSetting('DB_HOST', '127.0.0.1');
$port = backupSetting('DB_PORT', '3306');
$name = backupSetting('DB_NAME');
$user = backupSetting('DB_USER');
$pass = backupSetting('DB_PASS', '');
$mysqldump = backupSetting('MYSQLDUMP_BIN', 'mysqldump');
$retentionDays = (int) backupSetting('DB_BACKUP_RETENTION_DAYS', '30');
$repoRoot = dirname(__DIR__, 2);
$backupDir = backupSetting('DB_BACKUP_DIR', $repoRoot . '/backups');

// Relative DB_BACKUP_DIR values are anchored to the repo root so cron jobs
// behave the same regardless of their working directory.
if ($backupDir !== null && preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $backupDir) !== 1) {
    $backupDir = $repoRoot . DIRECTORY_SEPARATOR . $backupDir;
}

if ($name === null || $user === null || $mysqldump === null || $backupDir === null) {
    fwrite(STDERR, "Backup failed: DB_NAME, DB_USER, MYSQLDUMP_BIN and DB_BACKUP_DIR are required.\n");
    exit(1);
}

if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    fwrite(STDERR, "Backup failed: DB_NAME contains unsupported characters.\n");
    exit(1);
}
if (preg_match('/^[0-9]{1,5}$/', $port) !== 1) {
    fwrite(STDERR, "Backup failed: DB_PORT must be numeric.\n");
    exit(1);
}
if ($retentionDays < 0) {
    $retentionDays = 0;
}

if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Backup failed: cannot create backup directory {$backupDir}.\n");
    exit(1);
}
if (!is_writable($backupDir)) {
    fwrite(STDERR, "Backup failed: backup directory is not writable: {$backupDir}.\n");
    exit(1);
}

// --- Single-run lock (never queue up cron jobs / manual runs) --------------
$lockPath = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . '.backup-db.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "Backup failed: cannot open the backup lock file.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    fwrite(STDOUT, "Another backup is already running; nothing to do.\n");
    exit(0);
}

$defaultsFile = null;
$stderrFile = null;
$tmpPath = null;
$status = 1;

try {
    $timestamp = date('Ymd_His');
    $filename = "lucebianca_{$timestamp}.sql.gz";
    $finalPath = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($finalPath)) {
        throw new RuntimeException("Refusing to overwrite an existing backup: {$filename}");
    }
    $tmpPath = $finalPath . '.tmp';

    // --- Temporary MySQL option file (credentials never on argv) ----------
    $defaultsFile = tempnam(sys_get_temp_dir(), 'lb-my-');
    if ($defaultsFile === false) {
        throw new RuntimeException('Could not create the temporary MySQL option file.');
    }
    @chmod($defaultsFile, 0600);

    $escapeOption = static fn (string $value): string => str_replace(
        ["\\", "\"", "\n", "\r"],
        ["\\\\", "\\\"", "\\n", "\\r"],
        $value
    );
    $optionLines = [
        '[client]',
        'host="' . $escapeOption($host ?? '') . '"',
        'port=' . $port,
        'user="' . $escapeOption($user) . '"',
        'password="' . $escapeOption($pass ?? '') . '"',
    ];
    if (file_put_contents($defaultsFile, implode("\n", $optionLines) . "\n") === false) {
        throw new RuntimeException('Could not write the temporary MySQL option file.');
    }

    // --- Stream mysqldump → gzip into a .tmp file --------------------------
    $stderrFile = tempnam(sys_get_temp_dir(), 'lb-dump-err-');
    if ($stderrFile === false) {
        throw new RuntimeException('Could not create the temporary error log.');
    }

    $command = [
        $mysqldump,
        '--defaults-extra-file=' . $defaultsFile,
        '--single-transaction',
        '--quick',
        '--skip-lock-tables',
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
        '--routines',
        '--triggers',
        $name,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', $stderrFile, 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $repoRoot);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start mysqldump. Check MYSQLDUMP_BIN.');
    }
    fclose($pipes[0]);

    $gzip = gzopen($tmpPath, 'wb9');
    if ($gzip === false) {
        fclose($pipes[1]);
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('Could not open the gzip output file.');
    }

    $bytesWritten = 0;
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        if ($chunk === '') {
            continue;
        }
        $written = gzwrite($gzip, $chunk);
        if ($written === false) {
            fclose($pipes[1]);
            proc_terminate($process);
            proc_close($process);
            gzclose($gzip);
            throw new RuntimeException('Could not write the compressed backup.');
        }
        $bytesWritten += $written;
    }
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    gzclose($gzip);

    if ($exitCode !== 0) {
        $error = trim((string) @file_get_contents($stderrFile));
        // Defense in depth: never echo a password that a tool might include.
        $error = (string) preg_replace('/password\s*=\s*\S+/i', 'password=[redacted]', $error);
        $error = $error === '' ? 'mysqldump exited with an error.' : $error;
        throw new RuntimeException("mysqldump failed (exit {$exitCode}): {$error}");
    }
    if ($bytesWritten <= 0 || !is_file($tmpPath) || (int) filesize($tmpPath) <= 0) {
        throw new RuntimeException('mysqldump produced an empty backup.');
    }

    if (!rename($tmpPath, $finalPath)) {
        throw new RuntimeException('Could not move the completed backup into place.');
    }
    $tmpPath = null;
    @chmod($finalPath, 0600);

    $size = (int) filesize($finalPath);
    fwrite(STDOUT, "Backup created: {$finalPath} ({$size} bytes)\n");

    // --- Retention: only generated dumps, filename-pattern matched --------
    if ($retentionDays > 0) {
        $cutoff = time() - ($retentionDays * 86400);
        $pruned = 0;
        $entries = glob(rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . 'lucebianca_*.sql.gz') ?: [];
        foreach ($entries as $entry) {
            $base = basename($entry);
            if (preg_match('/^lucebianca_[0-9]{8}_[0-9]{6}\.sql\.gz$/', $base) !== 1) {
                continue;
            }
            if (is_link($entry) || !is_file($entry)) {
                continue;
            }
            $mtime = @filemtime($entry);
            if ($mtime !== false && $mtime <= $cutoff && @unlink($entry)) {
                $pruned++;
            }
        }
        if ($pruned > 0) {
            fwrite(STDOUT, "Pruned {$pruned} backup(s) older than {$retentionDays} day(s).\n");
        }
    }

    $status = 0;
} catch (Throwable $e) {
    if ($tmpPath !== null && is_file($tmpPath)) {
        @unlink($tmpPath);
    }
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . PHP_EOL);
    $status = 1;
} finally {
    if (is_string($defaultsFile) && is_file($defaultsFile)) {
        @unlink($defaultsFile);
    }
    if (is_string($stderrFile) && is_file($stderrFile)) {
        @unlink($stderrFile);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($status);
