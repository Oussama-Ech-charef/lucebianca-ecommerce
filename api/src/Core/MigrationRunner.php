<?php

namespace App\Core;

/**
 * MigrationRunner — applies database/migrations/*.sql once each.
 *
 * Project migration format (MySQL/MariaDB): plain DDL/DML batches using
 * `--` line comments, `#` line comments, slash-star block comments, string
 * literals (single/double quoted, backslash escapes), backtick identifiers,
 * and session statements (SET, PREPARE ... FROM @var, EXECUTE, DEALLOCATE).
 * There are no DELIMITER blocks or stored routines in this format, and this
 * runner does not support them.
 *
 * Execution strategy (deliberate, MySQL-specific):
 * - Statements are split by a tokenizer that respects quotes, backticks and
 *   comments — never a naive explode(';'). A `;` inside a string literal or
 *   a comment never ends a statement.
 * - Comment-only chunks are skipped, but a chunk that contains SQL after
 *   leading comments is EXECUTED (a leading `--` header must never cause a
 *   statement to be silently dropped).
 * - `USE <db>;` directives are skipped (the PDO connection already targets
 *   the configured database).
 * - Migrations run statement-by-statement in AUTOCOMMIT (no wrapping
 *   transaction): MySQL/MariaDB implicitly commit DDL (ALTER/CREATE TABLE),
 *   so a wrapping transaction cannot roll DDL back and would leave a false
 *   sense of atomicity. Consequences:
 *     * a migration is recorded (filename + SHA-256) only after ALL of its
 *       statements succeed — never on partial success;
 *     * on failure the runner stops immediately without recording, so a
 *       re-run retries the same file;
 *     * every migration file must therefore be written idempotently
 *       (INFORMATION_SCHEMA guards, `IF NOT EXISTS`), because a failed first
 *       attempt may have already applied its early statements.
 * - `--dry-run` is fully read-only: it never creates `schema_migrations`
 *   (existence is probed via INFORMATION_SCHEMA) and never executes anything.
 */
final class MigrationRunner
{
    public function __construct(
        private \PDO $pdo,
        private string $migrationsDir
    ) {
    }

    /**
     * Safe failure metadata for a migration throwable.
     *
     * Extracts ONLY the migration filename and statement position from the
     * runner's own "Migration {file} failed at statement {i} of {n}" shape —
     * the trailing PDO/SQL text is never included, so credentials, DSNs,
     * tokens, and SQL values cannot reach the structured log. Returns [] when
     * the message does not match (e.g. a connection failure before any file
     * ran). Pair with the static message 'Migration failed' at the call site.
     *
     * @return array{file?: string, statement?: int, statements?: int}
     */
    public static function failureContext(\Throwable $e): array
    {
        if (preg_match(
            '/^Migration ([A-Za-z0-9_.-]+\.sql) failed at statement (\d+) of (\d+)/',
            $e->getMessage(),
            $m
        ) !== 1) {
            return [];
        }

        return [
            'file' => $m[1],
            'statement' => (int) $m[2],
            'statements' => (int) $m[3],
        ];
    }

    /**
     * Sanitizes a migration failure message for CLI output (stderr).
     *
     * Useful diagnostics (migration filename, statement index, SQLSTATE and
     * short reason) pass through; credential/DSN/token-looking substrings
     * are masked and the result is capped, so secrets can never scroll by
     * in a terminal, CI log, or cron mail.
     */
    public static function sanitizeCliMessage(string $message): string
    {
        $patterns = [
            '#Bearer\s+[A-Za-z0-9\-_.~+/=:.]{8,}#i',
            '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/',
            '/\b(?:password|passwd|pwd|token|api[_-]?key|secret)\s*[:=]\s*\S+/i',
            '/mysql:[^\s]*/i',
            '/\bDB_(?:HOST|PORT|NAME|USER|PASS)\s*=\s*\S+/i',
        ];
        foreach ($patterns as $pattern) {
            $message = (string) preg_replace($pattern, '[redacted]', $message);
        }

        $trimmed = trim($message);

        return function_exists('mb_substr')
            ? mb_substr($trimmed, 0, 1000)
            : substr($trimmed, 0, 1000);
    }

    /**
     * Splits a SQL batch into individual statements.
     *
     * Tokenizer states: single-quoted string, double-quoted string, backtick
     * identifier (all with their escape rules), dash-dash and hash line
     * comments, slash-star block comments. A semicolon ends a statement only
     * in normal state. Returned statements keep their original text (comments
     * included — MySQL accepts them); use stripComments() to test emptiness.
     *
     * @return list<string> Raw statement texts (may include whitespace/comment-only entries).
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // Line comment: `--` followed by whitespace/control (MySQL rule),
            // or `#` anywhere in normal state.
            if ($ch === '-' && $next === '-' && ($i + 2 >= $len || self::isCommentWhitespace($sql[$i + 2]))) {
                $end = strpos($sql, "\n", $i);
                if ($end === false) {
                    $current .= substr($sql, $i);
                    $i = $len;
                } else {
                    $current .= substr($sql, $i, $end - $i);
                    $i = $end;
                }
                continue;
            }
            if ($ch === '#') {
                $end = strpos($sql, "\n", $i);
                if ($end === false) {
                    $current .= substr($sql, $i);
                    $i = $len;
                } else {
                    $current .= substr($sql, $i, $end - $i);
                    $i = $end;
                }
                continue;
            }

            // Block comment (kept verbatim in the statement text).
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    $current .= substr($sql, $i);
                    $i = $len;
                } else {
                    $current .= substr($sql, $i, $end + 2 - $i);
                    $i = $end + 2;
                }
                continue;
            }

            // Single-quoted string (backslash escapes).
            if ($ch === "'") {
                $current .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    $current .= $c;
                    if ($c === '\\' && $i + 1 < $len) {
                        $current .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $i++;
                    if ($c === "'") {
                        // '' is an escaped quote inside the string.
                        if ($i < $len && $sql[$i] === "'") {
                            $current .= $sql[$i];
                            $i++;
                            continue;
                        }
                        break;
                    }
                }
                continue;
            }

            // Double-quoted string (backslash escapes).
            if ($ch === '"') {
                $current .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    $current .= $c;
                    if ($c === '\\' && $i + 1 < $len) {
                        $current .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $i++;
                    if ($c === '"') {
                        break;
                    }
                }
                continue;
            }

            // Backtick identifier (`` is an escaped backtick).
            if ($ch === '`') {
                $current .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    $current .= $c;
                    $i++;
                    if ($c === '`') {
                        if ($i < $len && $sql[$i] === '`') {
                            $current .= $sql[$i];
                            $i++;
                            continue;
                        }
                        break;
                    }
                }
                continue;
            }

            // Statement terminator (normal state only — quotes/comments
            // are consumed above and never reach this branch inside one).
            if ($ch === ';') {
                $current .= $ch;
                $statements[] = $current;
                $current = '';
                $i++;
                continue;
            }

            $current .= $ch;
            $i++;
        }

        // Trailing text without a terminator (e.g. a final comment block).
        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }

    /**
     * Removes line/block comments from a statement for emptiness testing.
     * Quote-aware: comment markers inside string literals are preserved.
     */
    public static function stripComments(string $statement): string
    {
        $out = '';
        $len = strlen($statement);
        $i = 0;

        while ($i < $len) {
            $ch = $statement[$i];
            $next = $i + 1 < $len ? $statement[$i + 1] : '';

            if ($ch === '-' && $next === '-' && ($i + 2 >= $len || self::isCommentWhitespace($statement[$i + 2]))) {
                $end = strpos($statement, "\n", $i);
                $i = $end === false ? $len : $end;
                continue;
            }
            if ($ch === '#') {
                $end = strpos($statement, "\n", $i);
                $i = $end === false ? $len : $end;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $end = strpos($statement, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $out .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $statement[$i];
                    $out .= $c;
                    if (($quote !== '`' && $c === '\\') && $i + 1 < $len) {
                        $out .= $statement[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $i++;
                    if ($c === $quote) {
                        if ($quote === "'" && $i < $len && $statement[$i] === "'") {
                            $out .= $statement[$i];
                            $i++;
                            continue;
                        }
                        if ($quote === '`' && $i < $len && $statement[$i] === '`') {
                            $out .= $statement[$i];
                            $i++;
                            continue;
                        }
                        break;
                    }
                }
                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * True when a statement carries no executable SQL (only whitespace,
     * comments and/or bare semicolons).
     */
    public static function isEmptyStatement(string $statement): bool
    {
        $stripped = trim(self::stripComments($statement));
        // A lone terminator left over from splitting is not a statement.
        $stripped = trim($stripped, "; \t\n\r\0\x0B");

        return $stripped === '';
    }

    /**
     * True for `USE <database>;` directives, which are always skipped: the
     * PDO connection already targets the configured database, and executing
     * a hardcoded USE could switch away from it.
     */
    public static function isUseStatement(string $statement): bool
    {
        $stripped = trim(self::stripComments($statement));

        return preg_match('/^USE\s+[`"\w][^;]*;?$/i', $stripped) === 1;
    }

    /**
     * Lists *.sql migration files in numeric order with checksums.
     *
     * @return list<array{file: string, name: string, checksum: string}>
     */
    public function listFiles(): array
    {
        $paths = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($paths);
        $files = [];
        foreach ($paths as $path) {
            $files[] = [
                'file' => $path,
                'name' => basename($path),
                'checksum' => hash_file('sha256', $path),
            ];
        }

        return $files;
    }

    /**
     * Filenames already recorded as applied (empty when the tracking table
     * does not exist yet — probed read-only via INFORMATION_SCHEMA).
     *
     * @return array<string, string> filename => checksum
     */
    public function applied(): array
    {
        if (!$this->trackingTableExists()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT filename, checksum FROM schema_migrations');
        $applied = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $applied[$row['filename']] = $row['checksum'];
        }

        return $applied;
    }

    /**
     * Pending migrations (present on disk, not recorded as applied).
     *
     * @return list<array{file: string, name: string, checksum: string}>
     */
    public function pending(): array
    {
        $applied = $this->applied();
        $pending = [];
        foreach ($this->listFiles() as $file) {
            if (!isset($applied[$file['name']])) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    /**
     * Recorded files whose on-disk checksum changed (history must be
     * append-only — reported, never re-applied).
     *
     * @return list<string> Filenames with drifted checksums.
     */
    public function drifted(): array
    {
        $applied = $this->applied();
        $drifted = [];
        foreach ($this->listFiles() as $file) {
            if (isset($applied[$file['name']]) && $applied[$file['name']] !== $file['checksum']) {
                $drifted[] = $file['name'];
            }
        }

        return $drifted;
    }

    /**
     * Counts executable statements per file (for --dry-run reporting).
     *
     * @param list<array{file: string, name: string, checksum: string}> $files
     * @return array<string, int> filename => executable statement count
     */
    public function countStatements(array $files): array
    {
        $counts = [];
        foreach ($files as $file) {
            $counts[$file['name']] = count(self::executableStatements((string) file_get_contents($file['file'])));
        }

        return $counts;
    }

    /**
     * Splits raw SQL into the statements this runner would execute
     * (comment-only and USE statements excluded).
     *
     * @return list<string>
     */
    public static function executableStatements(string $sql): array
    {
        $out = [];
        foreach (self::splitStatements($sql) as $statement) {
            if (self::isEmptyStatement($statement) || self::isUseStatement($statement)) {
                continue;
            }
            $out[] = trim($statement);
        }

        return $out;
    }

    /**
     * Applies every pending migration in order.
     *
     * Each file runs statement-by-statement in autocommit (see class docs
     * for why no wrapping transaction is used) and is recorded only after
     * ALL of its statements succeed. Stops at the first failure without
     * recording that file.
     *
     * @return list<string> Applied filenames, in order.
     *
     * @throws \RuntimeException On the first failing migration (with the
     *   failing statement index and the underlying error).
     */
    public function migrate(): array
    {
        $this->ensureTrackingTable();

        $applied = [];
        foreach ($this->pending() as $file) {
            $this->applyFile($file);
            $applied[] = $file['name'];
        }

        return $applied;
    }

    /**
     * Records migrations as applied WITHOUT executing them (adoption of a
     * database that was already migrated manually, e.g. via the legacy
     * `mysql < file.sql` path documented in database/migrations/README.md).
     *
     * Only use when the schema already contains the migration's effects;
     * recording a migration whose statements never ran masks missing schema
     * and will surface later as obscure runtime errors.
     *
     * @param string|null $onlyPrefix When set, only filenames starting with
     *   this prefix are recorded (e.g. `--baseline=01` for the historical
     *   range); otherwise every pending file is recorded.
     *
     * @return list<string> Recorded filenames, in order.
     */
    public function baseline(?string $onlyPrefix = null): array
    {
        $this->ensureTrackingTable();

        $recorded = [];
        foreach ($this->pending() as $file) {
            if ($onlyPrefix !== null && !str_starts_with($file['name'], $onlyPrefix)) {
                continue;
            }
            $this->recordApplied($file['name'], $file['checksum']);
            $recorded[] = $file['name'];
        }

        return $recorded;
    }

    /**
     * True when the target database already holds application tables but no
     * migration history — i.e. it was migrated manually and should be
     * adopted with --baseline instead of executing 001.. from scratch
     * (001 would fail on existing tables; 010 is not re-runnable after 008).
     */
    public function looksManuallyMigrated(): bool
    {
        if ($this->trackingTableExists()) {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM schema_migrations');

            return ((int) $stmt->fetchColumn()) === 0 && $this->tableExists('orders');
        }

        return $this->tableExists('orders');
    }

    /**
     * Executes one migration file; records it only on full success.
     *
     * @param array{file: string, name: string, checksum: string} $file
     *
     * @throws \RuntimeException On any statement failure.
     */
    private function applyFile(array $file): void
    {
        $sql = (string) file_get_contents($file['file']);
        $statements = self::executableStatements($sql);

        if ($statements === []) {
            // Nothing executable — still record so the file is not retried
            // forever (a comment-only file is vacuously applied).
            $this->recordApplied($file['name'], $file['checksum']);
            return;
        }

        foreach ($statements as $index => $statement) {
            try {
                // query() + closeCursor() instead of exec(): a statement that
                // returns rows (e.g. a `SELECT 1` no-op guard) must have its
                // result set drained, otherwise the next statement fails with
                // "Cannot execute queries while other unbuffered queries are
                // active" (SQLSTATE 2014).
                $result = $this->pdo->query($statement);
                if ($result instanceof \PDOStatement) {
                    $result->closeCursor();
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Migration {$file['name']} failed at statement " . ($index + 1)
                    . ' of ' . count($statements) . ': ' . $e->getMessage()
                );
            }
        }

        $this->recordApplied($file['name'], $file['checksum']);
    }

    private function recordApplied(string $name, string $checksum): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO schema_migrations (filename, checksum) VALUES (:filename, :checksum)'
        );
        $stmt->bindValue(':filename', $name);
        $stmt->bindValue(':checksum', $checksum);
        $stmt->execute();
    }

    private function ensureTrackingTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              filename VARCHAR(255) NOT NULL,
              checksum CHAR(64) NOT NULL,
              applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_schema_migrations_filename (filename)
            ) ENGINE=InnoDB'
        );
    }

    private function trackingTableExists(): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->bindValue(':table', 'schema_migrations');
        $stmt->execute();
        $exists = ((int) $stmt->fetchColumn()) > 0;
        $stmt->closeCursor();

        return $exists;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->bindValue(':table', $table);
        $stmt->execute();
        $exists = ((int) $stmt->fetchColumn()) > 0;
        $stmt->closeCursor();

        return $exists;
    }

    private static function isCommentWhitespace(string $ch): bool
    {
        return $ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r";
    }
}
