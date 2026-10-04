# API tests

Dependency-free test harness for the PHP API (no PHPUnit — none is
installed in this project). Two runners:

## Unit tests (no database, no network)

```bash
php api/tests/run-unit-tests.php
```

Covers the pure core pieces: `Validator` rules, `CsrfProtection`
(generate/validate/tamper/expiry), `Auth` JWT roundtrip + tampered/expired/
foreign-secret rejection, `ErrorLogger` (required JSON fields,
credential redaction incl. recursive `redact()` for headers/bodies/arrays,
request-id front-door sharing, no stack traces), `MigrationRunner` SQL splitting
(comment-prefixed/quoted-semicolon/USE handling), and payment-initiation URL
separation + request-hash coverage. Leaves no residue.

## Test reports (both runners)

Every run — pass, fail, or fatal — writes (gitignored, never committed):

- `api/storage/test-reports/latest-report.json`
- `api/storage/test-reports/latest-report.md`

Failed runs additionally leave a timestamped copy
(`unit-<UTC>.json/.md` or `api-<UTC>.json/.md`) in the same directory.
Console output and exit codes are unchanged (reporting is a silent
shutdown-hook that only creates files).

Each report carries: command, UTC start/end, duration, environment (PHP
version + test database NAME only — never host/user/password), totals
(total/passed/failed/skipped), status, failed test names with safe
messages, and a sanitized fatal line when applicable. Raw
request/response bodies are excluded by design (they may carry tokens,
emails, or customer data); failure labels are static strings, still
passed through redaction before writing.

## Integration tests (isolated throwaway DB + real routes)

```bash
php api/tests/run-api-tests.php
```

Boots the real API on a random local port (`127.0.0.1`) against an
isolated MySQL database `lucebianca_test`, rebuilt from
`database/schema.sql` snapshot (19 tables, deterministic, includes `015`
`lookup_token` and drift repairs) on every run. The developer database is never
touched: the runner accepts only `lucebianca_test` or a name with the
`lucebianca_test_` prefix, then rewrites the snapshot's hard-coded
`USE lucebianca;` / `CREATE DATABASE` directives in memory. It hard-fails if
a future snapshot introduces any other production-DB reference. Migration files
`001…015` are **not** executed by this harness (010 has a known duplicate-column
conflict after 008, 014/015 are `UNTESTED` with `INFORMATION_SCHEMA+PREPARE`); see
`database/migrations/README.md` two-path docs. `MigrationRunner` itself is
covered separately against throwaway `lucebianca_test_migrate_*` databases
(dry-run purity, failure non-recording, checksum drift, baseline adoption).

Connection defaults are copied from `api/.env`; override with environment
variables if needed: `LB_TEST_DB_HOST`, `LB_TEST_DB_PORT`,
`LB_TEST_DB_USER`, `LB_TEST_DB_PASS`, `LB_TEST_DB_NAME`.

Requires the `pdo_mysql` extension (already required by composer.json) and a
user that may create/drop databases. The runner drops and recreates its named
test database, so never point it at a production database. No Composer packages, browser,
or external service is involved.

### Suites

- health endpoint
- CSRF-protected mutations: newsletter subscribe/repeat/invalid/tamper;
  wishlist add/list/remove; review submit/validation errors
- customer auth/session: register, duplicate 409, generic 401 login message,
  account access with/without/garbage token, refresh rotation (reuse → 401)
- guest checkout email flow: order stores email with NULL user_id,
  authoritative pricing, stock decrement verified via POST /api/cart,
  logged-in order attribution, conflict/validation responses
- card payments + idempotent inventory: initiation storefront URLs (never the
  API callback), signed paid/failed callbacks + replays, late-paid rejection
  after failure/cancel, repeated cancels, real expiry-cron run, expiry skip
  after cancel (stock asserted via cart validation throughout)
- migration runner: dry-run purity (no tracking table created), failure names
  file + statement index without recording, repair + re-run, checksum drift,
  baseline adoption, manual-migration detection (scratch `migrate_*` DBs)
- newsletter email flow: welcome best-effort with empty mailer key, no token
  in subscribe response, read-only GET verify, POST-only unsubscribe,
  audit counter single-increment, email-bound tokens
- error-response safety: generic bodies, no stack/file/debug keys

### Known limitations

- Outbound email (Resend) is deliberately disabled in tests (`RESEND_API_KEY`
  empty); the suites assert the guarded best-effort path never blocks a
  response. Actual delivery needs a manual check.
- Card-gateway tests use throwaway CMI secrets (`test-*`, test database
  only) to craft correctly-signed callbacks — no production credentials,
  no real gateway traffic.
- Frontend browser E2E is not automated in this repo (no Playwright config /
  browsers installed); past storefront QA was manual per-phase — see
  docs/decisions/.
