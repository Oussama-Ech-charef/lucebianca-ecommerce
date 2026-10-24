# Runbook — Database backup & restore

Scope: the single MySQL/MariaDB database `lucebianca` (InnoDB, `utf8mb4`)
used by the PHP API. Credentials live in `api/.env` (`DB_HOST`, `DB_PORT`,
`DB_NAME`, `DB_USER`, `DB_PASS`) — never copy them into scripts or docs.
Schema source of truth: `database/migrations/*.sql`, applied in numeric
order and tracked in `schema_migrations` by `api/scripts/migrate.php`.

## 1. Backup

The current schema (19 tables, migrations through `021`) adds customer PII:
`users` holds emails, phone numbers and password hashes; `orders` holds
names, phones and addresses. Treat every dump as confidential.

### Automated script (recommended)

`api/scripts/backup-db.php` creates a gzip dump in `backups/` (gitignored)
using the same `DB_*` credentials as the API:

```bash
php api/scripts/backup-db.php
```

What it does / guarantees:
- Streams `mysqldump --single-transaction --quick` into gzip; the completed
  file is `lucebianca_YYYYmmdd_HHMMSS.sql.gz`, renamed from `.tmp` only on
  success.
- Passes credentials through a temporary `0600` MySQL defaults-extra-file,
  removed afterwards — passwords never appear in process arguments.
- Uses a non-blocking lock (`.backup-db.lock`) so overlapping cron/manual
  runs cannot corrupt each other.
- Prunes **only generated dumps** matching
  `lucebianca_YYYYmmdd_HHMMSS.sql.gz` older than
  `DB_BACKUP_RETENTION_DAYS` (default 30; `0` keeps forever). Existing
  manually named files in `backups/` are never touched.
- Optional settings in `api/.env`: `MYSQLDUMP_BIN`, `DB_BACKUP_DIR`
  (relative paths resolve from the repo root),
  `DB_BACKUP_RETENTION_DAYS`.

Exit code `0` = success (or another run holds the lock), `1` = failure.
Generated dumps are gitignored by the existing `backups/` rule — never
commit them.

### Manual fallback

```bash
mysqldump \
  --host=<DB_HOST> --port=<DB_PORT> --user=<DB_USER> -p \
  --single-transaction --quick \
  --default-character-set=utf8mb4 \
  lucebianca | gzip > "lucebianca_$(date +%Y%m%d_%H%M%S).sql.gz"
```

Notes:
- No stored procedures/triggers exist in this project today; if you ever add
  some, append `--routines --triggers --events`.
- The dump contains no `CREATE DATABASE`/`USE` statement (mysqldump of a
  single DB). Restores go into a pre-created database — see §3.

### Schedule (example — no scheduler ships in this repo)

Install on the DB host or API host yourself, e.g. cron:

```cron
15 3 * * *   cd /path/to/lucebianca && mkdir -p api/storage/logs && php api/scripts/backup-db.php >> api/storage/logs/backup.log 2>&1
```

If the API host is Windows, create a Task Scheduler task that starts
`cmd.exe` with arguments
`/c "C:\path\to\php.exe C:\path\to\lucebianca\api\scripts\backup-db.php >> C:\path\to\lucebianca\api\storage\logs\backup.log 2>&1"`
and uses the repository root as its working directory. No scheduler task is
installed by this repository; installation and monitoring remain deployment
steps.

The script's built-in retention (default 30 days) covers daily dumps. For a
longer archive, copy a dump off-machine before it ages out, or set a larger
`DB_BACKUP_RETENTION_DAYS`. Do not point another prune command at `backups/`
unless it matches the generated filename pattern — the folder may contain
manual backups that must be preserved.

### Off-machine storage & encryption

A backup on the same machine dies with the machine. After each run, push the
encrypted file to a second location (object storage or another server via
`rclone`/`scp` — nothing is configured in this repo; pick one):

```bash
# Encrypt at rest (either tool is fine — record the passphrase in your
# password manager, NOT next to the backups):
age -r <RECIPIENT_PUBLIC_KEY> file.sql.gz -o file.sql.gz.age
gpg --encrypt --recipient <ops-key-id> file.sql.gz

# Then copy off-box, e.g.:
rclone copy file.sql.gz.age <REMOTE>:<BUCKET>/mysql/
```

Verify monthly that an off-machine copy actually restores (§3) — a backup
that has never been restored is a hypothesis.

## 2. What exists where

| Item | Location |
|---|---|
| Schema migrations (apply in order) | `database/migrations/001…021_*.sql` |
| Fresh-setup full snapshot (all tables + columns) | `database/schema.sql` |
| Generated + manual database dumps (gitignored) | `backups/` |
| API DB credentials | `api/.env` |
| App-level error log (not DB) | `api/storage/logs/app-error.log` |

## 3. Safe restore procedure

1. **Triage**: confirm what you are restoring and why; note current time.
2. **Freeze writes**: stop PHP traffic to the API first (pause the
   PHP-FPM pool / dev server / proxy upstream — whatever runs the API on
   your host). Orders written during a restore would be lost.
3. **Stage**: restore into a scratch database first when time allows:

   ```bash
   mysql --host=<DB_HOST> -u <DB_USER> -p \
     -e "CREATE DATABASE IF NOT EXISTS lucebianca_restore
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   gunzip -c lucebianca-<TS>.sql.gz | \
     mysql --host=<DB_HOST> -u <DB_USER> -p lucebianca_restore
   ```

4. **Verify the scratch copy**:

   ```sql
   SHOW TABLES;                          -- expected: same 19-table list as prod
   SELECT COUNT(*) FROM users;
   SELECT COUNT(*) FROM orders;          -- compare against known values
   SELECT COUNT(*) FROM product_variants;
   SELECT COUNT(*) FROM schema_migrations; -- compare with source of truth
   -- Expected 3 for a schema that includes migration 021:
   SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME IN ('coupon_id','coupon_code','discount_amount');
   ```

5. **Swap**: drop/rename the broken schema and rename the restored one in
   (`RENAME TABLE` works across schemas for quick swaps), then re-point the
   API if you restored under a different name.
6. **Unfreeze & smoke-test**: restart PHP, then check:
   - `GET /api/health` → `{"status":"ok",...}`
   - `GET /api/products?page=1` → JSON with data (proves DB reads)
   - one admin login + one storefront page render.

7. Record what happened (time, dump used, verification results).
