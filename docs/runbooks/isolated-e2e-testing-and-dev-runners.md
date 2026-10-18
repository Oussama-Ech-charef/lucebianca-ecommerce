# Runbook — Isolated End-to-End Testing & Development Runners

**Scope:** Procedures for running the Luce Bianca development stack locally and safely executing end-to-end browser tests against an isolated database without mutating production or development records.

---

## 1. Standard Development Environment

The standard development environment runs the PHP API and Next.js frontend with live hot-reloading.

### 1.1 Starting the API Backend
From the workspace root, run:
```powershell
.\run-api.ps1
```
Or directly from the shell:
```bash
cd "C:\Users\ULTRA PC\Desktop\lucebianca\api"
php -S 127.0.0.1:8090 public/index.php
```
- **Port:** `127.0.0.1:8090`
- **Configuration:** Reads `api/.env` (default `DB_NAME=lucebianca`).
- **Health Check:**
  ```powershell
  Invoke-RestMethod -Uri "http://127.0.0.1:8090/api/health" -Method GET
  # Expected: {"status": "ok", "service": "lucebianca-api", "env": "local", ...}
  ```

### 1.2 Starting the Next.js Frontend
In a second terminal window, run:
```powershell
.\run-frontend.ps1
```
Or directly from the shell:
```bash
cd "C:\Users\ULTRA PC\Desktop\lucebianca\frontend"
npm run dev
```
- **Port:** `http://localhost:3000`
- **Configuration:** Reads `frontend/.env.local` (`NEXT_PUBLIC_API_URL=http://127.0.0.1:8090`).

---

## 2. Isolated End-to-End Testing Protocol

When executing state-changing tests (such as placing orders, updating stock, or changing admin passwords), you must **never** test against the main `lucebianca` database. Follow this protocol to establish a fully isolated test harness.

### Step 1: Verify or Prepare the Test Database (`lucebianca_test_e2e`)
1. Connect to MySQL and ensure the isolated database exists:
   ```sql
   CREATE DATABASE IF NOT EXISTS lucebianca_test_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. If initial setup is required, apply the snapshot schema:
   ```bash
   mysql -u root -p lucebianca_test_e2e < database/schema.sql
   ```
3. Record baseline migrations so the runner recognizes the schema as current:
   ```bash
   php api/scripts/migrate.php --baseline
   ```

### Step 2: Create a Temporary Environment File
Create a temporary `.env` file in the user's local Temp directory (e.g. `C:\Users\<User>\AppData\Local\Temp\lb-e2e-isolated.env`) containing:
```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=lucebianca_test_e2e
DB_USER=root
DB_PASS=<YourLocalPassword>
JWT_SECRET=super-secret-e2e-jwt-token-key-with-more-than-32-chars
JWT_TTL_SECONDS=3600
JWT_REFRESH_TTL_SECONDS=604800
GOOGLE_CLIENT_ID=
RESEND_API_KEY=
RESEND_FROM_EMAIL=test@localhost
APP_ENV=testing
APP_DEBUG=true
APP_TIMEZONE=Africa/Casablanca
SITE_URL=http://localhost:3000
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000
```
> [!IMPORTANT]
> Never modify the root `api/.env` file. The test harness relies on `LB_TEST_ENV_FILE` loading first in memory.

### Step 3: Launch the Test API Instance
Start the API using the test front controller `api/tests/server-index.php`:
```powershell
$env:LB_TEST_ENV_FILE = "C:\Users\<User>\AppData\Local\Temp\lb-e2e-isolated.env"
cd "C:\Users\ULTRA PC\Desktop\lucebianca"
php -S 127.0.0.1:8090 api/tests/server-index.php
```

### Step 4: Verify Isolation Before Starting Browser
Always verify that the test API reports `env: testing` before performing any browser actions:
```powershell
$health = Invoke-RestMethod -Uri "http://127.0.0.1:8090/api/health"
if ($health.env -ne "testing") {
    throw "STOP: API is not running in isolated test mode!"
}
Write-Host "Confirmed: Test API is running in isolated mode." -ForegroundColor Green
```

---

## 3. Data Integrity & Verification Queries

During and after test execution, run these queries to verify state transitions.

### Check Orders and Line Items in Test Database:
```sql
USE lucebianca_test_e2e;
SELECT id, customer_name, total_amount, status, payment_method, created_at FROM orders ORDER BY id DESC LIMIT 5;
SELECT * FROM order_items WHERE order_id = (SELECT MAX(id) FROM orders);
```

### Check Inventory Decrement:
```sql
USE lucebianca_test_e2e;
SELECT id, sku, size, color, stock_quantity FROM product_variants;
```

### Main Database Zero-Mutation Check:
Confirm that no records were created or modified in the primary database:
```sql
USE lucebianca;
SELECT COUNT(*) AS total_orders FROM orders;
-- Expected: Baseline count (e.g. 5 historical records) unchanged.
```

---

## 4. Teardown & Cleanup Checklist

Once E2E testing is complete, follow these steps to return the system to standard development mode:

1. **Stop Test Processes:**
   - Stop the PHP test process on port `8090`.
   - Stop the test frontend session on port `3000`.
   - Verify no orphaned processes remain:
     ```powershell
     Get-Process -Name php, node -ErrorAction SilentlyContinue
     ```
2. **Remove Temporary Environment File:**
   ```powershell
   Remove-Item -Path "C:\Users\<User>\AppData\Local\Temp\lb-e2e-isolated.env" -Force
   ```
3. **Purge Test Runtime Cache & Rate Limits:**
   ```powershell
   Remove-Item -Path "api\storage\cache\*.cache" -Force -ErrorAction SilentlyContinue
   Remove-Item -Path "api\storage\rate-limits\*" -Force -ErrorAction SilentlyContinue
   ```
4. **Preserve Databases:**
   - Leave `lucebianca_test_e2e` intact so test evidence and logs remain verifiable.
   - Do not drop tables unless explicitly re-seeding for a new test run.
5. **Check Git Status:**
   ```powershell
   git status --short
   # Expected: Clean working tree
   ```

---

## 5. Troubleshooting & Diagnostics

### Port 8090 in Use by Another Application
If `php -S 127.0.0.1:8090` fails with `Address already in use`:
```powershell
# 1. Identify which process owns port 8090
Get-NetTCPConnection -LocalPort 8090 | Select-Object LocalAddress, LocalPort, OwningProcess, State

# 2. Inspect the process
Get-Process -Id <OwningProcessId>

# 3. If an external background utility (e.g. WsToastNotification) bound the port, stop it:
Stop-Process -Id <OwningProcessId> -Force
```

### Migration Checksum Warnings
If `php api/scripts/migrate.php` reports checksum drift:
- Migration files in `database/migrations/` must never be modified once applied.
- If schema changes are needed, always create a new numbered migration (e.g. `021_*.sql`).
