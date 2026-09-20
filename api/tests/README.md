# API tests

Dependency-free test harness for the PHP API (no PHPUnit — none is
installed in this project). Two runners:

## Unit tests (no database, no network)

```bash
php api/tests/run-unit-tests.php
```

Covers the pure core pieces: `Validator` rules, `CsrfProtection`
(generate/validate/tamper/expiry), `Auth` JWT roundtrip + tampered/expired/
foreign-secret rejection, and `ErrorLogger` (required JSON fields,
credential redaction, no stack traces). Leaves no residue.

## Integration tests (isolated throwaway DB + real routes)

```bash
php api/tests/run-api-tests.php
```

Boots the real API on PHP's built-in server (`127.0.0.1:8091`) against an
isolated MySQL database `lucebianca_test`, rebuilt from
`database/schema.sql` snapshot (18 tables, deterministic, includes `015`
`lookup_token` and drift repairs) on every run. The developer database is never
touched: the snapshot hard-codes `USE lucebianca;` / `CREATE DATABASE`, which
the runner rewrites in memory to the test database name (and hard-fails if a
future snapshot introduces any other production-DB reference). Migration files
`001…015` are **not** executed by this harness (010 has a known duplicate-column
conflict after 008, 014/015 are `UNTESTED` with `INFORMATION_SCHEMA+PREPARE`); see
`database/migrations/README.md` two-path docs.

Connection defaults are copied from `api/.env`; override with environment
variables if needed: `LB_TEST_DB_HOST`, `LB_TEST_DB_PORT`,
`LB_TEST_DB_USER`, `LB_TEST_DB_PASS`, `LB_TEST_DB_NAME`.

Requires only the `pdo_mysql` extension (already required by composer.json)
with a user that may create/drop databases. No Composer packages, browser,
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
- error-response safety: generic bodies, no stack/file/debug keys

### Known limitations

- Outbound email (Resend) is deliberately disabled in tests (`RESEND_API_KEY`
  empty); the suites assert the guarded best-effort path never blocks a
  response. Actual delivery needs a manual check.
- Frontend browser E2E is not automated in this repo (no Playwright config /
  browsers installed); past storefront QA was manual per-phase — see
  docs/decisions/.
