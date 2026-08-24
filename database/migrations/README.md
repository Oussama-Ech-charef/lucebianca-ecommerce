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
| `004_add_users_google_id.sql` | users.google_id (Google sign-in create-or-link) | applied (phase 13) |
| `005_add_users_email_verification.sql` | users email verification token/expiry + flag | applied (phase 16) |
| `006_add_reviews_table.sql` | reviews (moderated product ratings) | applied |
| `007_add_wishlist_table.sql` | wishlist (per-customer saved products) | applied |
| `008_add_newsletter_table.sql` | newsletter_subscribers | applied |
| `009_add_email_to_orders.sql` | orders.email (guest checkout confirmations) | applied |
| `010_add_newsletter_status_columns.sql` | newsletter_subscribers.is_active/unsubscribed_at — aligns fresh installs with NewsletterRepository (first subscribe failed with "Unknown column 'is_active'"; caught by api/tests) | applied |
| `011_add_sale_price_to_products.sql` | products.sale_price — optional discounted price column for "Special Offers" feature; positive and < base_price | applied |