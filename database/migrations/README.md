# Database migrations

Numbered, append-only migration files. **This folder, not `../schema.sql`,
is the source of truth going forward.**

## Convention

- `001_initial_schema.sql` — the original full schema (all 14 tables). It is
  frozen: never edit it in place.
- Any new table or column change is a new numbered file, e.g.
  `002_add_refresh_tokens_table.sql`, `003_add_<thing>.sql`, ...
- Migrations are applied in numeric order, once each, and recorded nowhere
  today (a real `migrations` tracking table / runner arrives in phase 10
  when the backup & monitoring story lands).

`../schema.sql` still exists as a convenient **full snapshot for fresh
setups** (it equals `001` today). Do not apply it after migrations have run.

## How to apply

From the project root, in order:

```bash
mysql -u root -p < database/migrations/001_initial_schema.sql
mysql -u root -p < database/migrations/002_add_refresh_tokens_table.sql
```

## Current catalog

| File | Purpose | Status |
|---|---|---|
| `001_initial_schema.sql` | Full initial schema (14 tables) | applied |
| `002_add_refresh_tokens_table.sql` | `refresh_tokens` (SHA-256 hashed, revocable) | applied (phase 2) |
| `003_add_admin_refresh_tokens_table.sql` | `admin_refresh_tokens` (admins are a fully separate table — see file for the FK decision) | applied (phase 4) |