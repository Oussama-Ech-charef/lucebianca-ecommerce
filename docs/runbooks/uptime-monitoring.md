# Runbook — Uptime monitoring

The only monitoring that exists today is what you configure externally —
this repo ships no agent, cron checker or alerting service. Everything
below uses endpoints that already exist in the code.

## 1. Safe check targets

| Check | Method | Expect | Proves |
|---|---|---|---|
| `https://<API-HOST>/api/health` | GET | 200, JSON `status:"ok"` | PHP up, router + `.env` load OK. **Does not touch the DB** |
| `https://<API-HOST>/api/products?page=1` | GET | 200, JSON with non-empty `data` | Full public read path incl. MySQL |
| `https://<SITE>/` | GET | 200 HTML containing `Luce Bianca` | Next.js server rendering alive |
| `https://<SITE>/sitemap.xml` | GET | 200 XML | ISR/static generation pipeline alive |

Rules:
- **GET only.** Never wire monitors to POST/PUT/DELETE endpoints (side
  effects + CSRF + rate limits). In particular do not poll
  `/api/csrf-token` on a schedule — it exists for browsers mid-session.
- Poll at most every 60s per endpoint; these are cheap but not free.
- Replace `<API-HOST>` / `<SITE>` with your real hosts; in this repo they
  default to `127.0.0.1:8090` and `localhost:3000` for local dev.

## 2. Alert conditions (recommended minimum)

- Any check failing **2 consecutive runs** → alert (avoids flap noise).
- Response time > 2 s on the API product-list check.
- TLS certificate expiry < 14 days (most monitors offer this).
- Disk usage on the API host > 80 % — file-based rate limiting writes under
  `api/storage/rate-limits/` and error logs to `api/storage/logs/`; a full
  disk breaks both silently.

## 3. Error-log triage (no log shipping exists)

Unexpected server errors land as one JSON object per line in
`api/storage/logs/app-error.log`. Each line carries `timestamp` (UTC),
`level` (`error` for uncaught throwables, `warning`/`info` for security
and payment events), `request_id`, `method`, safe `path` (ids/slugs
collapsed to `{id}`/`{slug}`, query strings dropped), `http_status`,
`exception` class, safe `message`, plus `user_id` when authenticated and
a redacted `context` for business events (order ids only — never emails,
tokens, or card data). Bodies, headers, passwords, tokens, DSNs, and
stack traces are never logged.

**Request-ID investigation:** EVERY API response (2xx, 4xx, 5xx, and
middleware rejections) carries an `X-Request-Id` header stamped at the
front door (`Router::dispatch`), and the matching 500 log line stores
the SAME id. To trace a customer report:

```bash
# 1. Ask the customer for the X-Request-Id (visible in browser devtools →
#    Network → response headers). No id? Fall back to time + endpoint.
# 2. Grep the exact id (bash / PowerShell):
grep '"request_id":"<id>"' api/storage/logs/app-error.log
Select-String api\storage\logs\app-error.log -Pattern '"request_id":"<id>"'
```

To watch live:

```bash
tail -f api/storage/logs/app-error.log          # bash
Get-Content api\storage\logs\app-error.log -Tail 50 -Wait   # PowerShell
```

**Rotation/bounded retention:** the file is capped at ~10 MB — on
overflow it rotates to `app-error.log.1` (one generation kept, ~20 MB
max). Writes use `LOCK_EX` appends, safe under concurrent PHP workers.
For production, point your log shipper at `api/storage/logs/app-error.log`
(JSON Lines) and alert on `level:"error"` rate; keep the PHP process's
write permission on `api/storage/logs/` (0755 dirs).

(`api/php_errors.log` in the repo root is a leftover local dev artifact
of PHP itself, not part of this scheme — all best-effort email failures
now go through the structured logger instead of `error_log`.)

## 4. Incident checklist

1. Confirm scope: is `/api/health` down, or only one endpoint? Frontend up?
2. API host: is the PHP service running (`systemctl status <php-service>`,
   adjust to your setup)? Restart if stopped.
3. Database reachable from the API host? (`mysql -h <DB_HOST> -u <DB_USER> -p`
   then `SELECT 1`). Connection refused = DB down; access denied = creds/env.
4. Read the tail of `app-error.log` — exception class + message identify the
   failing subsystem fast.
5. Check disk space (`df -h`) — full disk mimics many unrelated failures.
6. If a recent deploy caused it: roll back that deployment first, debug after.
7. When restored: hit all four §1 checks yourself before closing out, and
   write a short post-mortem note into `docs/decisions/` per repo convention.

## 5. Card-payment expiry cron (exit-code contract)

`api/scripts/expire-card-pending.php` (every 15 min via system cron) moves
stale card-`pending` orders to cancelled+failed and restores stock exactly
once. It runs alongside callbacks and cancellations; all three serialize on
the `inventory_released_at` claim, so overlapping runs are safe.

- **Exit `0`**: all candidates expired or skipped (skips for already-handled
  orders are normal, not failures). stdout carries per-order lines.
- **Exit `1`**: at least one order failed, or the DB/lookup failed at
  startup. stderr carries one line per failure with the order id only —
  never customer data, tokens, or secrets.
- **Monitor:** alert on any non-zero exit (same 2-consecutive-runs rule as
  §2 to avoid flap noise); on alert, read stderr for the order id, then
  inspect that order via `/api/admin/orders/{id}`.

## 6. Production logging setup

1. **Permissions:** the PHP process user needs write access to
   `api/storage/logs/` (the logger creates the dir at 0755 and appends
   with `LOCK_EX`; logging never throws — a read-only dir only means
   silent log loss, responses still work).
2. **Environment:** set `APP_ENV=production`, `APP_DEBUG=false`
   (never `true` in prod — debug mode adds `exception`/`file`/`line`
   to 500 bodies), and a strong `JWT_SECRET` (≥32 chars).
3. **Reverse proxy:** forward a per-request `X-Request-Id` (nginx
   `$request_id`) so traces span proxy → API → logs; unparseable
   values are ignored and a fresh id is generated.
4. **Retention:** default is 10 MB + one rotated generation. For
   higher volume, ship `app-error.log` (JSON Lines) to your log
   aggregator and alert on `level:"error"`.
5. **Git safety:** `api/storage/logs/` and `api/storage/test-reports/`
   are gitignored — generated `.log`/`.json`/`.md` files must never be
   committed.
