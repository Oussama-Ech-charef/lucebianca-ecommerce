<?php

/**
 * Expire stale card-pending orders — restores reserved stock.
 *
 * Usage (cron, every 15 min):
 *   php api/scripts/expire-card-pending.php [--minutes=30]
 *
 * Policy (inventory): a card order created but never paid within the window
 * is moved to cancelled + payment_status failed, and its stock is restored
 * exactly once. COD/WhatsApp pending orders are NOT expired (they await
 * human confirmation, not a gateway callback).
 *
 * Concurrency: candidate selection is only a hint. Each order is
 * re-validated under a row lock and transitioned with a conditional
 * inventory-release claim in ONE transaction (see
 * OrderService::expireStaleCardOrder) — a concurrently cancelled, failed or
 * already-expired order is skipped, never double-restored. Safe to overlap
 * with customer/admin cancellations and CMI callbacks.
 *
 * Cron: run every 15 minutes from the system crontab; see
 * docs/decisions/2026-10-04-payment-shipping-inventory.md for the exact line.
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Database;
use App\Core\Env;

// Test harness support: run-api-tests.php points this script at the isolated
// test database via LB_TEST_ENV_FILE (same mechanism as server-index.php).
// In production the variable is unset and api/.env is used as before.
$testEnvFile = getenv('LB_TEST_ENV_FILE');
if (is_string($testEnvFile) && $testEnvFile !== '' && is_file($testEnvFile)) {
    Env::load($testEnvFile);
} else {
    Env::load(__DIR__ . '/../.env');
}

$minutes = 30;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--minutes=')) {
        $minutes = max(5, (int) substr($arg, strlen('--minutes=')));
    }
}

$pdo = Database::get();
// Hint-only selection: only unreleased, still-pending card orders older
// than the window. The atomic transition re-checks everything per order.
$stmt = $pdo->prepare(
    "SELECT id FROM orders
      WHERE payment_method = 'card'
        AND payment_status = 'pending'
        AND status = 'pending'
        AND inventory_released_at IS NULL
        AND created_at < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
      ORDER BY id"
);
$stmt->bindValue(':minutes', $minutes, PDO::PARAM_INT);
$stmt->execute();
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

if ($ids === []) {
    echo "No stale card-pending orders (window {$minutes}m).\n";
    exit(0);
}

$service = new App\Services\OrderService();
$expired = 0;
$skipped = 0;
foreach ($ids as $id) {
    try {
        if ($service->expireStaleCardOrder((int) $id, $minutes)) {
            $expired++;
            echo "Expired order #{$id} (cancelled + stock restored).\n";
        } else {
            // Already handled concurrently (cancelled/failed/released) or no
            // longer stale — a normal skip, not an error.
            $skipped++;
            echo "Skipped order #{$id} (already handled concurrently).\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Failed to expire order #{$id}: {$e->getMessage()}\n");
    }
}
echo "Expired {$expired}/" . count($ids) . " orders ({$skipped} skipped as already handled).\n";
