# Runbook — Database backup & restore

Scope: the single MySQL/MariaDB database `lucebianca` (InnoDB, `utf8mb4`)
used by the PHP API. Credentials live in `api/.env` (`DB_HOST`, `DB_PORT`,
`DB_NAME`, `DB_USER`, `DB_PASS`) — never copy them into scripts or docs.
Schema source of truth: `database/migrations/*.sql`, applied in numeric
order (there is **no** migrations-tracking table or runner yet — apply and
verify manually).

## 1. Backup

The initial schema (14 tables) plus later migrations add customer PII:
`users` holds emails, phone numbers and password hashes; `orders` holds
names, phones and addresses. Treat every dump as confidential.

Full logical backup (safe on live InnoDB tables — consistent snapshot,
no blocking):

```bash
mysqldump \
  --host=<DB_HOST> --port=<DB_PORT> --user=<DB_USER> -p \
  --single-transaction --quick \
  --default-character-set=utf8mb4 \
  lucebianca | gzip > "lucebianca-$(date +%Y%m%d-%H%M%S).sql.gz"
```

Notes:
- No stored procedures/triggers exist in this project today; if you ever add
  some, append `--routines --triggers --events`.
- The dump contains no `CREATE DATABASE`/`USE` statement (mysqldump of a
  single DB). Restores go into a pre-created database — see §3.

### Schedule (example — no scheduler ships in this repo)

Install on the DB host yourself, e.g. cron:

```cron
15 3 * * *   <daily full dump as above>
30 3 * * 0   <weekly dump, kept separately>
```

Recommended retention: dailies 14 days · weeklies 8 weeks · monthlies 12
months. Prune with a small script (`find <backupdir> -name '*.sql.gz' -mtime +14 -delete`
per tier) rather than keeping one flat pile.

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
| Schema migrations (apply in order) | `database/migrations/001…009_*.sql` |
| Fresh-setup full snapshot (= 001 + USE line) | `database/schema.sql` |
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
   SHOW TABLES;                          -- expected: same table list as prod
   SELECT COUNT(*) FROM users;
   SELECT COUNT(*) FROM orders;          -- compare against known values
   SELECT COUNT(*) FROM product_variants;
   ```

5. **Swap**: drop/rename the broken schema and rename the restored one in
   (`RENAME TABLE` works across schemas for quick swaps), then re-point the
   API if you restored under a different name.
6. **Unfreeze & smoke-test**: restart PHP, then check:
   - `GET /api/health` → `{"status":"ok",...}`
   - `GET /api/products?page=1` → JSON with data (proves DB reads)
   - one admin login + one storefront page render.

7. Record what happened (time, dump used, verification results).
