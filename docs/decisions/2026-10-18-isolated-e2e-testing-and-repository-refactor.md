# Decisions — Isolated E2E Testing Harness & Repository Refactoring (2026-10-18)

**Date:** 2026-10-18  
**Context:** Following the database schema audit and restoration, full end-to-end browser testing was required to verify customer authentication, Cash on Delivery (COD) order placement, inventory decrements, and admin status management. However, conducting state-changing tests against the primary `lucebianca` database violated data protection rules. In addition, administrative controllers had accumulated raw SQL queries, and core cache deserialization lacked strict class restrictions.

---

## 1. Decision: Isolated E2E Test Harness via `LB_TEST_ENV_FILE`

### Context
Testing order placement and inventory reduction requires mutating database records. The local API server defaulted to `api/.env`, pointing at the primary database `lucebianca`. Testing against the live database risked corrupting historical records, stock counts, and test metrics.

### Decision
1. **Isolated Database:** Created a dedicated test database `lucebianca_test_e2e` cloned from `database/schema.sql` and marked with migration baseline `001`–`020`.
2. **Environment Injection:** Utilized `api/tests/server-index.php` as the front controller for test instances. It inspects the `LB_TEST_ENV_FILE` environment variable:
   ```php
   $envFile = getenv('LB_TEST_ENV_FILE');
   if ($envFile && is_file($envFile)) {
       App\Core\Env::load($envFile);
   }
   ```
3. **Immutability of `.env`:** The primary file `api/.env` is never modified during test execution. `Env::load()` is idempotent; because the test harness loads the temporary environment file first, the production configuration is ignored.
4. **Zero-Touch Main Database Rule:** State-changing browser tests are strictly prohibited unless the API server explicitly reports `"env": "testing"` on `GET /api/health`.

### Consequences
- **Positive:** Full browser checkout and admin order flows can be tested with real database persistence, real stock decrements, and real foreign key checks without risk to primary data.
- **Trade-off:** Running isolated tests requires spinning up a dedicated test API instance on an assigned port.

---

## 2. Decision: Repository Layer Decoupling for Admin Controllers

### Context
`DashboardController` and `CustomersController` contained inline SQL statements for calculating gross revenue, counting active customers, charting order volume, and searching customer accounts. This coupled HTTP request handling directly to database schema details and duplicated query logic.

### Decision
1. **`OrderRepository`:**
   - Created `api/src/Repositories/OrderRepository.php`.
   - Encapsulated metrics methods: `getDashboardStats()`, `getRecentOrders()`, `getSalesChart()`, `getTopProducts()`.
   - Centralized status-filtering logic and multi-table joins between `orders`, `order_items`, and `products`.
2. **`UserRepository`:**
   - Created `api/src/Repositories/UserRepository.php`.
   - Encapsulated customer search, pagination, and account verification checks in `paginateCustomers()`.
3. **Controller Refactoring:**
   - `DashboardController` and `CustomersController` now inject repositories via constructor or lazy instantiation, focusing solely on JSON response formatting and HTTP status codes.

### Consequences
- **Positive:** Cleaner unit testability, single responsibility per unit, and reuse of complex order reporting queries across administrative endpoints.
- **Trade-off:** Adds two new repository classes to the codebase.

---

## 3. Decision: Cache Deserialization Security Hardening

### Context
`api/src/Core/Cache.php` previously used `unserialize($raw)` without restrictions when reading cached catalog data from `api/storage/cache/`. In PHP, unbounded `unserialize()` can lead to PHP Object Injection vulnerabilities if cache files are modified or contaminated.

### Decision
Updated the deserialization call in `Cache::get()`:
```php
$data = @unserialize($raw, ['allowed_classes' => false]);
```
By setting `'allowed_classes' => false`, the PHP engine restricts deserialized data strictly to primitive types (arrays, strings, integers, floats, booleans, null). Object instantiation is impossible.

### Consequences
- **Positive:** Eliminates PHP Object Injection attack vectors via file cache manipulation.
- **Trade-off:** Cached data must be scalar values or associative arrays (which already matches the catalog caching implementation).

---

## 4. Decision: Authoritative Sale Price Filtering & Mutation Invalidation

### Context
1. In `ProductRepository::paginate()`, the price range filter evaluated `base_price BETWEEN :min AND :max`. When a product had a discounted `sale_price`, customers filtering by price would see inaccurate results.
2. When administrators modified product details or prices, the public storefront continued serving stale cached responses until TTL expiration.

### Decision
1. **Effective Price Calculation:** Updated SQL queries in `ProductRepository` to evaluate `COALESCE(sale_price, base_price)` for price range filtering and sorting.
2. **Event-Driven Cache Invalidation:** In `api/src/Controllers/Admin/ProductsController.php`, all state-changing mutations (`create`, `update`, `delete`, `toggleStatus`) explicitly invoke `Cache::forget('products_page_*')` to immediately purge stale catalog cache entries.

### Consequences
- **Positive:** Customers always filter by the price they actually pay; storefront displays updated product details immediately following admin saves.

---

## 5. Decision: Standardized PowerShell Runner Scripts

### Context
Developers launching the Next.js frontend and PHP API on Windows environments needed to remember exact paths, port numbers, and front-controller arguments.

### Decision
Created two standardized runner scripts in the project root:
- [`run-api.ps1`](file:///C:/Users/ULTRA%20PC/Desktop/lucebianca/run-api.ps1): Navigates to `api/` and executes `php -S 127.0.0.1:8090 public/index.php`.
- [`run-frontend.ps1`](file:///C:/Users/ULTRA%20PC/Desktop/lucebianca/run-frontend.ps1): Navigates to `frontend/` and executes `npm run dev`.

### Consequences
- **Positive:** One-click launch for local development; ensures consistent port `8090` and `3000` bindings across team environments.
