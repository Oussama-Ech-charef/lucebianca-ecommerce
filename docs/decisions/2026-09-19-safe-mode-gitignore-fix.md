# 2026-09-19 — Safe-mode .gitignore fix (docs tracking)

**Date:** 2026-09-19
**Mode:** strict safe mode — read-first, small scoped edit, validated, documented
**Scope:** single file — root `.gitignore:32-35`

## Context

Inspection before any edit showed:

- `docs/` and `lucebianca-project-full.md` were listed in root `.gitignore:35-36` (`docs/`, `lucebianca-project-full.md`), causing `git check-ignore -v` to mark `docs/README.md` and `docs/decisions/*` as ignored.
- `git status --ignored` confirmed `docs/` and `lucebianca-project-full.md` as **Ignored files**, while `git ls-files` showed zero `docs/` entries tracked. That contradicts spec §11 (`docs/` lives in repo and is committed) and §12 (the template `.gitignore` does not contain `docs/`).
- `lucebianca-project-full.md` is the single master spec — it should be versioned, not ignored.
- Secrets remained correctly ignored: `api/.env` via root `.gitignore:6` (`.env`), `frontend/.env.local` via `frontend/.gitignore:34` (`.env*`) — verified.

No git write, no file deletion, no external service was invoked during inspection.

## Intended change (explained before edit)

Remove the two erroneous lines from root `.gitignore`:

```diff
 # --- Uploaded files (local, not Cloudinary) ---
 public/uploads/
 uploads/
-docs/
-lucebianca-project-full.md

 # --- Database dumps / backups ---
```

Keep surrounding sections intact. No other file modified in this step. The change unblocks versioning of `docs/` without altering runtime code, DB, or dependencies.

Future small steps explicitly deferred from this step (not executed):
- Fix `frontend/.gitignore:34` (`.env*`) to allow `!.env.example` so `frontend/.env.example` can be tracked like `api/.env.example` (currently `git check-ignore -v frontend/.env.example` → `frontend/.gitignore:34:.env*`).
- Sync `docs/README.md` index — it lists decisions up to 2026-08-20-phase16 but is missing `2026-08-22-phase17-security-hardening.md` which already exists on disk.
- Decide on `api/storage/` — it is currently untracked (file cache + `api/storage/logs/app-error.log`, `api/storage/rate-limits/`) but not covered by root `.gitignore:28-30` (`*.log`, `logs/`, `storage/logs/`). The path `api/storage/rate-limits/` and `api/storage/cache/` remain untracked; consider adding `api/storage/` or `storage/` to ignore if transient.

## Actual change

Edited `C:\Users\ULTRA PC\Desktop\lucebianca\.gitignore` via single `edit` call — removed `docs/` and `lucebianca-project-full.md` (see `git diff` below). File now 54 lines (was 56), content verified with `read` after edit (`docs/README.md:1` still present on disk, no deletion).

## Validation (safe, non-destructive)

All checks were read-only; no DB, no `npm install`, no git write, no destructive test:

1. `git check-ignore -v docs/README.md` → exit 1 (no output) — **not ignored** (was exit 0 before).
2. `git check-ignore -v lucebianca-project-full.md` → exit 1 — **not ignored** (was exit 0 before).
3. `git check-ignore -v api/.env` → ` .gitignore:6:.env` exit 0 — **still ignored** (secrets protected).
4. `git status --ignored` — before: `Ignored files: … docs/ … lucebianca-project-full.md`; after: `Untracked files: … docs/ … lucebianca-project-full.md` and `Ignored files` no longer lists them. `modified: .gitignore` appears as expected; no `git add`/`commit` was run.
5. `php -l api/bootstrap.php`, `api/routes.php`, `api/src/Core/Database.php` → `No syntax errors` (sanity lint, unchanged code).
6. `Test-Path` + `read` on `.gitignore`, `docs/README.md`, `docs/decisions/2026-08-22-phase17-security-hardening.md` — files exist, content intact, no outside-workspace writes.

No `git add`, `git commit`, `git push`, `git reset`, `git checkout`, deletions, renames, moves, or installs were executed.

## Remaining risks / open items

- **Not in this step:** `frontend/.env.example` remains ignored by `frontend/.gitignore:34` — API template is tracked but frontend template is not. Fix requires `!.env.example` exception; risk is low (docs-only) but should be a separate safe-mode step.
- **Docs index drift:** `docs/README.md` is 27 lines and omits the phase17 entry already on disk; next step should append the missing bullet to keep the index accurate.
- **Storage untracked:** `api/storage/` (cache, logs, rate-limits) is untracked. `api/storage/logs/app-error.log` is gitignored via `*.log`/`storage/logs/`, but `api/storage/cache/` and `api/storage/rate-limits/` are not explicitly ignored; consider explicit `api/storage/cache/` / `api/storage/rate-limits/` or broader `storage/` if transient data should never be committed (disk currently has 3 cache files + 3 rate-limit files).
- **Spec file now untracked:** `lucebianca-project-full.md` is now correctly un-ignored but remains untracked until a future commit — no secret in it, but verify it contains no credentials before committing.
- **No functional code changed:** API/frontend behavior unchanged; no regression expected, but full `npm run build` / `npm run lint` and API test suite were deliberately not run to avoid heavy/destructive operations in safe mode. Run those in the next integration step.

## References

- ` .gitignore:32-35` after fix
- `docs/README.md:1` — index still lists 13 decisions, missing phase17
- `docs/decisions/2026-08-22-phase17-security-hardening.md:1` — exists on disk, ignored before, now visible
- `frontend/.gitignore:34`, `api/src/Core/RateLimiter.php:39`, `api/src/Core/CsrfProtection.php:94`
