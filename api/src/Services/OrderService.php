<?php

namespace App\Services;

use App\Core\ConflictException;
use App\Core\Database;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Order;
use App\Repositories\OrderRepository;
use PDOException;

/**
 * OrderService — cart validation + order placement business logic.
 *
 * The cart itself is client-owned (React Context + localStorage, no server
 * session — the spec exposes only POST /api/cart + POST /api/orders). This
 * service validates a submitted cart against live variant data (authoritative
 * prices, remaining stock), then places the order atomically: orders row +
 * order_items rows + guarded stock decrement inside one transaction.
 *
 * Client-supplied prices are NEVER trusted: unit_price_at_purchase is always
 * recomputed from the variant/product (spec section 4, price_at_purchase).
 */
final class OrderService
{
    private OrderRepository $orders;
    private EmailService $email;
    private NotificationService $notifications;
    private \App\Repositories\SettingsRepository $settings;

    public function __construct()
    {
        $this->orders = new OrderRepository();
        $this->email = new EmailService();
        $this->notifications = new NotificationService();
        $this->settings = new \App\Repositories\SettingsRepository();
    }

    /**
     * Validates a cart payload against live variant data.
     *
     * Returns a per-line report (authoritative unit price, current stock,
     * whether the requested quantity is still available) plus an overall
     * validity flag, so the frontend can show which lines need adjustment
     * instead of an opaque accept/reject.
     *
     * @param array<int, mixed> $lines Raw {variant_id, quantity, unit_price?} lines.
     *
     * @return array{lines: array<int, array<string, mixed>>, valid: bool}
     *
     * @throws ValidationException When a line is malformed.
     */
    public function validateCart(array $lines): array
    {
        $normalized = $this->normalizeLines($lines);
        $rows       = $this->orders->variantsWithProducts(
            array_map(static fn (array $line): int => $line['variant_id'], $normalized)
        );

        $report = [];
        $valid  = true;

        foreach ($normalized as $line) {
            $row = $rows[$line['variant_id']] ?? null;

            if ($row === null || (int) $row['is_active'] !== 1) {
                $valid    = false;
                $report[] = [
                    'variant_id'         => $line['variant_id'],
                    'requested_quantity' => $line['quantity'],
                    'available'          => false,
                    'reason'             => $row === null ? 'variant_not_found' : 'product_unavailable',
                ];
                continue;
            }

            // Pricing rule (see sale_price migration): a valid product sale_price
            // (positive and strictly less than base_price) is the effective price
            // for every variant; otherwise fall back to variant.price ?? base_price.
            $unitPrice          = $this->effectiveUnitPrice($row);
            $stock              = (int) $row['stock_quantity'];
            $lineAvailable      = $stock >= $line['quantity'];
            $lineReport         = [
                'variant_id'         => $line['variant_id'],
                'product_id'         => (int) $row['product_id'],
                'product_name'       => (string) $row['product_name'],
                'slug'               => (string) $row['product_slug'],
                'image_url'          => $row['image_url'] ?? null,
                'size'               => (string) $row['size'],
                'color'              => (string) $row['color'],
                'sku'                => (string) $row['sku'],
                'unit_price'         => (string) $unitPrice,
                'stock_quantity'     => $stock,
                'requested_quantity' => $line['quantity'],
                'available'          => $lineAvailable,
                'available_quantity' => min($line['quantity'], $stock),
            ];

            if (isset($line['unit_price']) && $line['unit_price'] !== '') {
                $lineReport['price_changed'] = self::pricesDiffer(
                    (string) $unitPrice,
                    (string) $line['unit_price']
                );
            }

            $valid = $valid && $lineAvailable;
            $report[] = $lineReport;
        }

        return ['lines' => $report, 'valid' => $valid];
    }

    /**
     * Places an order in a single transaction.
     *
     * 1. Validates customer fields + payment method (only 'cod', 'whatsapp', 'card'
     *    are accepted).
     * 2. Re-checks every line against live stock (409 on insufficiency).
     * 3. In ONE transaction: insert orders, insert order_items with the
     *    server-computed price, and decrement each variant's stock via a
     *    guarded UPDATE (never below zero; affected-rows checked).
     *
* @param string          $customerName    Customer name.
     * @param string          $phone           Customer phone.
     * @param string          $email           Customer email (for order confirmation).
     * @param string          $shippingAddress Shipping address.
     * @param string          $city            City.
     * @param string          $postalCode      Postal code.
     * @param string          $paymentMethod   'cod' | 'whatsapp' | 'card'.
     * @param array           $lines           Cart lines.
     * @param int|null        $userId          Optional authenticated customer id
     *                                         (null = guest checkout) so a
     *                                         logged-in order shows up on the
     *                                         customer's /account page.
     *
     * @return Order The created order with its line items.
     *
     * @throws ValidationException On invalid fields or payment method.
     * @throws ConflictException   When any line's stock is insufficient.
     */
    public function placeOrder(
        string $customerName,
        string $phone,
        string $email,
        string $shippingAddress,
        string $city,
        string $postalCode,
        string $paymentMethod,
        array $lines,
        ?int $userId = null
    ): Order {
        // --- Customer fields ---
        $errors = Validator::validate(
            [
                'customer_name'    => $customerName,
                'phone'            => $phone,
                'email'            => $email,
                'shipping_address' => $shippingAddress,
                'city'             => $city,
                'postal_code'      => $postalCode,
            ],
            [
                'customer_name'    => ['required', ['min', 2]],
                'phone'            => ['required'],
                'email'            => ['required', 'email'],
                'shipping_address' => ['required'],
                'city'             => ['required'],
                'postal_code'      => ['required'],
            ]
        );

        if ($phone !== '' && preg_match('/^[0-9+()\-\s]{8,20}$/', $phone) !== 1) {
            $errors['phone'] = 'Phone must be a valid number (8-20 digits).';
        }

        // --- Payment method: validate against supported methods ---
        if (!in_array($paymentMethod, ['cod', 'whatsapp', 'card'], true)) {
            $errors['payment_method'] = 'Payment method must be "cod", "whatsapp", or "card".';
        } elseif ($this->isPaymentMethodDisabled($paymentMethod)) {
            $errors['payment_method'] = 'This payment method is currently disabled.';
        }

        // Check if card payment is configured when card method is selected
        if ($paymentMethod === 'card' && !PaymentService::isConfigured()) {
            $errors['payment_method'] = 'Card payment is not available. Please choose Cash on Delivery or Order via WhatsApp.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // --- Cart lines: normalize, then resolve against live data. ---
        $normalized = $this->normalizeLines($lines);
        $rows       = $this->orders->variantsWithProducts(
            array_map(static fn (array $line): int => $line['variant_id'], $normalized)
        );

        $resolved = [];
        $subtotalCents = 0;
        foreach ($normalized as $line) {
            $row = $rows[$line['variant_id']] ?? null;

            if ($row === null) {
                throw new ConflictException('A cart item is no longer available. Please review your cart.');
            }
            if ((int) $row['is_active'] !== 1) {
                throw new ConflictException("{$row['product_name']} is no longer available. Please review your cart.");
            }
            if ((int) $row['stock_quantity'] < $line['quantity']) {
                throw new ConflictException(
                    "Not enough stock for {$row['product_name']} (size {$row['size']}, {$row['color']}) — "
                    . "only {$row['stock_quantity']} left. Please adjust the quantity."
                );
            }

            $unitPrice = $this->effectiveUnitPrice($row);
            $subtotalCents += (int) round(((float) $unitPrice) * 100) * $line['quantity'];
            $resolved[] = [
                'variant_id' => $line['variant_id'],
                'quantity'   => $line['quantity'],
                'unit_price' => $unitPrice,
                'product_name' => (string) $row['product_name'],
            ];
        }

        // Authoritative shipping (settings-driven, never client-supplied).
        $shippingCents = $this->calculateShippingCents($subtotalCents);
        $totalCents = $subtotalCents + $shippingCents;
        $subtotalAmount = number_format($subtotalCents / 100, 2, '.', '');
        $shippingCost = number_format($shippingCents / 100, 2, '.', '');
        $totalAmount = number_format($totalCents / 100, 2, '.', '');

        // --- One transaction: order + items + guarded stock decrement. ---
        // Generate single-purpose lookup token for guest privacy (non-guessable, returned once).
        $lookupToken = \App\Repositories\OrderRepository::generateLookupToken();
        $pdo = Database::get();
        $lowStockAlerts = [];
        $pdo->beginTransaction();
        try {
            $orderId = $this->orders->insertOrder([
                'total_amount'     => $totalAmount,
                'subtotal_amount'  => $subtotalAmount,
                'shipping_cost'    => $shippingCost,
                'shipping_address' => $shippingAddress,
                'city'             => $city,
                'postal_code'      => $postalCode,
                'customer_name'    => $customerName,
                'phone'            => $phone,
                'email'            => $email,
                'lookup_token'     => $lookupToken,
                'payment_method'   => $paymentMethod,
            ], $userId);

            foreach ($resolved as $line) {
                $this->orders->insertItem(
                    $orderId,
                    $line['variant_id'],
                    $line['quantity'],
                    $line['unit_price']
                );

                // SQL-level guard: stock can never go below zero. If a
                // concurrent order drained the stock between the pre-check
                // above and now, this affects 0 rows → roll everything back.
                if (!$this->orders->decrementStock($line['variant_id'], $line['quantity'])) {
                    $pdo->rollBack();
                    throw new ConflictException(
                        "Stock just ran out for {$line['product_name']} — please adjust the quantity and try again."
                    );
                }

                // Collect low-stock alerts to send AFTER successful commit
                $remainingStock = (int) $rows[$line['variant_id']]['stock_quantity'] - $line['quantity'];
                if ($remainingStock > 0 && $remainingStock <= 5) {
                    $lowStockAlerts[] = [
                        'product_name' => $line['product_name'],
                        'size' => (string) $rows[$line['variant_id']]['size'],
                        'color' => (string) $rows[$line['variant_id']]['color'],
                        'stock' => $remainingStock,
                    ];
                }
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $order = $this->getOrder($orderId);

        // Side effects only after successful commit (best-effort, never fail the order)
        foreach ($lowStockAlerts as $alert) {
            $this->notifications->notifyLowStock(
                $alert['product_name'],
                $alert['size'],
                $alert['color'],
                $alert['stock']
            );
        }

        // Send order confirmation email (best-effort, never fails the order)
        $this->sendOrderConfirmationEmail($order, $customerName);

        // Send admin notification for new order
        $this->notifications->notifyNewOrder($order->toArray());

        return $order;
    }

    /**
     * Fetches an order with its line items.
     *
     * @return Order|null Null when the order id does not exist.
     */
    public function getOrder(int $id): ?Order
    {
        $order = $this->orders->findById($id);
        if ($order === null) {
            return null;
        }

        $order->items = $this->orders->itemsForOrder($id);

        return $order;
    }

    /**
     * Authorized fetch for privacy-sensitive reads. Uses the repository's
     * ownership/token check and then loads items only on success. Returns
     * null on 404 to hide existence.
     */
    public function getOrderAuthorized(int $id, ?int $authenticatedUserId, ?string $providedToken): ?Order
    {
        $order = $this->orders->findAuthorized($id, $authenticatedUserId, $providedToken);
        if ($order === null) {
            return null;
        }

        $order->items = $this->orders->itemsForOrder($id);

        return $order;
    }

    /**
     * Updates order status / payment_status and returns the fresh order
     * with its line items (the same join the confirmation page uses).
     *
     * Values must already be validated against Order::STATUSES /
     * Order::PAYMENT_STATUSES by the controller — the repository only writes
     * whitelisted columns, so arbitrary strings never reach the ENUM columns.
     *
     * Sends shipping status email when order status changes.
     *
     * @param int   $id     Order id to update.
     * @param array $fields Subset of: status, payment_status.
     *
     * @return Order|null The updated order (with items), or null when the id
     *                    does not exist.
     */
    public function updateStatus(int $id, array $fields): ?Order
    {
        $this->orders->updateStatus($id, $fields);

        $order = $this->getOrder($id);

        // Send shipping status email if order status changed (guest + registered)
        if ($order !== null && isset($fields['status'])) {
            $customerName = $order->customerName;
            // Prefer order.email (guest and registered both have it), fallback to user email for older rows
            $email = $order->email ?? null;
            if (($email === null || $email === '') && $order->userId !== null) {
                $userRepo = new \App\Repositories\UserRepository();
                $user = $userRepo->findById($order->userId);
                if ($user !== null) {
                    $email = $user->email;
                }
            }

            if ($email !== null && $email !== '') {
                $this->sendShippingStatusEmail($order, $customerName, $email);
            }
        }

        return $order;
    }

    /**
     * Admin order update with atomic transition validation (PUT /api/admin/orders/{id}).
     *
     * Everything happens in ONE transaction under SELECT ... FOR UPDATE, so
     * concurrent payment callbacks, cancellations, or admin updates cannot
     * slip an invalid combination past a stale pre-read:
     *
     * - An order is never cancelled and marked paid in the same request
     *   (409): that combination would keep the money while returning the
     *   goods to the shelf.
     * - A cancelled order (already cancelled, or being cancelled by this
     *   request) can never be marked paid (409): paid money needs a refund
     *   flow, not a status flip.
     * - Cancelling reuses the exact cancelOrder guards (pending/processing
     *   only, never paid) and the conditional release claim, so inventory
     *   is restored exactly once — never for a paid order.
     * - Marking a pending card/COD/WhatsApp payment failed releases the
     *   reservation in the same transaction (mirrors the callback path, so
     *   no dangling reservation is left for the expiry cron to miss). A
     *   paid payment is never touched by this path, so stock is never
     *   restored for an order that has been paid.
     * - All other transitions (status-only moves, pending→paid
     *   confirmations, idempotent replays) behave exactly as before.
     *
     * @param int   $id     Order id to update.
     * @param array $fields Non-empty subset of: status, payment_status.
     *                      Caller must have validated both against
     *                      Order::STATUSES / Order::PAYMENT_STATUSES.
     *
     * @return Order The updated order (with items).
     *
     * @throws ConflictException On invalid transitions (existing structured
     *                           409 contract) or a missing order.
     */
    public function adminUpdate(int $id, array $fields): Order
    {
        $pdo = \App\Core\Database::get();
        $pdo->beginTransaction();
        try {
            $locked = $this->orders->findByIdForUpdate($id);
            if ($locked === null) {
                $pdo->rollBack();
                throw new ConflictException('Order not found.');
            }

            $targetStatus = $fields['status'] ?? $locked->status;
            $targetPayment = $fields['payment_status'] ?? $locked->paymentStatus;
            $requestsPaid = ($fields['payment_status'] ?? null) === 'paid';
            $requestsCancel = ($fields['status'] ?? null) === 'cancelled';

            // Rule 1: conflicting combination — never cancelled + paid in one
            // request. Only an explicit status=cancelled TOGETHER WITH an
            // explicit payment_status=paid in THIS request triggers it; other
            // paths that would assemble cancelled+paid fall through to the
            // specific rejections below instead.
            if ($requestsCancel && $requestsPaid) {
                $pdo->rollBack();
                throw new ConflictException(
                    'An order cannot be cancelled and marked paid in the same update. ' .
                    'Cancel unpaid orders, or confirm payment on open orders — never both at once.'
                );
            }

            // Rule 2: a cancelled order can never become paid. Checked under
            // the row lock, so a concurrent cancellation that commits first
            // is visible here and a later paid-marking is rejected.
            if (
                $requestsPaid && $locked->paymentStatus !== 'paid'
                && ($locked->status === 'cancelled' || $targetStatus === 'cancelled')
            ) {
                $pdo->rollBack();
                throw new ConflictException(
                    'A cancelled order cannot be marked paid. Process a refund before cancelling.'
                );
            }

            if ($targetStatus === 'cancelled' && $locked->status !== 'cancelled') {
                // Cancel path — same guards as cancelOrder(), enforced here
                // under the lock instead of trusting a controller pre-read.
                if (!in_array($locked->status, ['pending', 'processing'], true)) {
                    $pdo->rollBack();
                    throw new ConflictException('Only pending or processing orders can be cancelled.');
                }
                if ($locked->paymentStatus === 'paid') {
                    $pdo->rollBack();
                    throw new ConflictException('Paid orders cannot be cancelled automatically. Process a refund before cancelling.');
                }

                // Claim the release together with the status change; a lost
                // race means a concurrent path already cancelled+released.
                if (!$this->orders->updateStatusAndRelease($id, ['status' => 'cancelled'])) {
                    $pdo->rollBack();
                    $fresh = $this->getOrder($id);
                    if ($fresh === null) {
                        throw new ConflictException('Order not found.');
                    }
                    return $fresh;
                }

                foreach ($this->orders->itemsForOrder($id) as $item) {
                    $this->orders->incrementStock((int) $item['product_variant_id'], (int) $item['quantity']);
                }

                // A simultaneous non-paid payment change (e.g. failed) still
                // applies — Rule 1 already excluded paid above.
                if (isset($fields['payment_status']) && $fields['payment_status'] !== $locked->paymentStatus) {
                    $this->orders->updateStatus($id, ['payment_status' => $fields['payment_status']]);
                }

                $pdo->commit();
            } else {
                // Non-cancel path: plain write under lock. Holding the lock
                // through commit is what makes Rule 2 race-safe against a
                // concurrent cancellation.
                if (
                    $targetPayment === 'failed' && $locked->paymentStatus === 'pending'
                    && $locked->inventoryReleasedAt === null
                ) {
                    // Pending→failed releases the reservation in the same
                    // transaction (callback parity); never for paid orders —
                    // the branch requires locked payment pending.
                    if ($this->orders->updateStatusAndRelease($id, $fields)) {
                        foreach ($this->orders->itemsForOrder($id) as $item) {
                            $this->orders->incrementStock((int) $item['product_variant_id'], (int) $item['quantity']);
                        }
                    } else {
                        // Defensive only (unreachable while holding the row
                        // lock with a NULL marker read): apply the requested
                        // fields without touching stock — never double-restore.
                        $this->orders->updateStatus($id, $fields);
                    }
                } else {
                    $this->orders->updateStatus($id, $fields);
                }
                $pdo->commit();
            }
        } catch (ConflictException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $fresh = $this->getOrder($id);
        if ($fresh === null) {
            throw new ConflictException('Order not found.');
        }
        return $fresh;
    }

    /** Max units per cart line — prevents abuse while covering real bulk buys. */
    public const MAX_QUANTITY_PER_LINE = 20;

    /** Max distinct lines per order — bounds transaction size. */
    public const MAX_LINES_PER_ORDER = 50;

    /**
     * Normalizes + validates a raw items payload into clean lines.
     *
     * Strict integer rules: variant_id and quantity must be positive
     * integers (int type or digit-only string). Decimals (2.5, "2.0"),
     * floats, malformed ids ("abc", "1.5", null) are rejected — (int)
     * casting would silently truncate them. Duplicate variant lines are
     * rejected (merge client-side instead). Quantities are capped at
     * MAX_QUANTITY_PER_LINE; line count at MAX_LINES_PER_ORDER.
     *
     * @param array<int, mixed> $lines Raw lines.
     *
     * @return array<int, array{variant_id: int, quantity: int, unit_price: string|null}>
     *
     * @throws ValidationException On malformed lines.
     */
    private function normalizeLines(array $lines): array
    {
        if ($lines === []) {
            throw new ValidationException(['items' => 'At least one cart line is required.']);
        }
        if (count($lines) > self::MAX_LINES_PER_ORDER) {
            throw new ValidationException(['items' => 'Too many cart lines (max ' . self::MAX_LINES_PER_ORDER . ').']);
        }

        $normalized = [];
        $seenVariantIds = [];
        foreach ($lines as $i => $line) {
            if (!is_array($line)) {
                throw new ValidationException(["items.{$i}" => 'Each cart line must be an object.']);
            }

            $variantId = $line['variant_id'] ?? null;
            $quantity  = $line['quantity'] ?? null;

            if (!self::isPositiveInteger($variantId)) {
                throw new ValidationException(["items.{$i}.variant_id" => 'Variant id must be a positive integer.']);
            }
            if (!self::isPositiveInteger($quantity) || (int) $quantity < 1 || (int) $quantity > self::MAX_QUANTITY_PER_LINE) {
                throw new ValidationException(["items.{$i}.quantity" => 'Quantity must be an integer between 1 and ' . self::MAX_QUANTITY_PER_LINE . '.']);
            }

            $variantIdInt = (int) $variantId;
            if (isset($seenVariantIds[$variantIdInt])) {
                throw new ValidationException(["items.{$i}.variant_id" => 'Duplicate cart line for this variant. Merge quantities instead.']);
            }
            $seenVariantIds[$variantIdInt] = true;

            $clientPrice = $line['unit_price'] ?? null;
            if ($clientPrice !== null && $clientPrice !== '' && !is_numeric($clientPrice)) {
                throw new ValidationException(["items.{$i}.unit_price" => 'Unit price must be a number.']);
            }

            $normalized[] = [
                'variant_id'  => $variantIdInt,
                'quantity'    => (int) $quantity,
                'unit_price'  => $clientPrice === null || $clientPrice === '' ? null : (string) $clientPrice,
            ];
        }

        return $normalized;
    }

    /**
     * Strict positive-integer check: int > 0 or digit-only string ("3").
     * Rejects floats, decimal strings ("2.5", "2.0"), bools, null, arrays.
     */
    public static function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            // Reject leading zeros that hide decimals? "007" is fine as 7.
            return (int) $value > 0;
        }
        return false;
    }

    /**
     * Authoritative shipping in cents from settings (never client-supplied).
     *
     * Rules (documented in docs/decisions/2026-10-03-shipping-and-inventory-policy.md):
     * - shipping_cost setting (MAD, default 0 when missing/invalid).
     * - free_shipping_threshold setting (MAD, 0/disabled = never free).
     * - If threshold > 0 and subtotal >= threshold → free (0).
     * - Otherwise charge shipping_cost. Regions are informational on
     *   /shipping until per-region rates are defined by the owner (single
     *   flat rate today, configurable via admin settings).
     */
    public function calculateShippingCents(int $subtotalCents): int
    {
        try {
            $costRaw = $this->settings->get('shipping_cost');
            $thresholdRaw = $this->settings->get('free_shipping_threshold');
        } catch (\Throwable) {
            return 0;
        }
        $cost = is_numeric($costRaw) ? (float) $costRaw : 0.0;
        $threshold = is_numeric($thresholdRaw) ? (float) $thresholdRaw : 0.0;
        if ($cost < 0) {
            $cost = 0.0;
        }
        if ($threshold > 0 && ($subtotalCents / 100) >= $threshold) {
            return 0;
        }
        return (int) round($cost * 100);
    }

    /**
     * Public preview for cart/checkout totals (no persistence).
     *
     * @return array{subtotal: string, shipping: string, total: string}
     */
    public function previewTotals(int $subtotalCents): array
    {
        $shippingCents = $this->calculateShippingCents($subtotalCents);
        return [
            'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
            'shipping' => number_format($shippingCents / 100, 2, '.', ''),
            'total' => number_format(($subtotalCents + $shippingCents) / 100, 2, '.', ''),
        ];
    }

    /**
     * Releases an order's reserved stock exactly once (inventory policy).
     *
     * ONE atomic transaction:
     *   1. the order row is locked (SELECT ... FOR UPDATE) and may only
     *      proceed when inventory_released_at IS NULL,
     *   2. the release is marked (inventory_released_at = NOW()) via a
     *      conditional UPDATE that succeeds for exactly one claimant,
     *   3. every order line's stock is restored,
     *   4. everything commits together.
     *
     * A concurrent claimant (failed callback vs. expiry cron vs. customer or
     * admin cancellation) finds the marker already set and returns safely
     * WITHOUT incrementing stock again.
     *
     * Must be called outside any existing transaction.
     *
     * @return bool True when THIS call released the stock; false when
     *              another request already had (idempotent no-op).
     *
     * @throws ConflictException When the order does not exist.
     */
    public function releaseInventoryOnce(int $orderId): bool
    {
        $pdo = \App\Core\Database::get();
        $pdo->beginTransaction();
        try {
            $order = $this->orders->findByIdForUpdate($orderId);
            if ($order === null) {
                $pdo->rollBack();
                throw new ConflictException('Order not found.');
            }
            if ($order->inventoryReleasedAt !== null) {
                $pdo->rollBack();
                return false;
            }

            if (!$this->orders->claimInventoryRelease($orderId)) {
                $pdo->rollBack();
                return false;
            }

            foreach ($this->orders->itemsForOrder($orderId) as $item) {
                $this->orders->incrementStock((int) $item['product_variant_id'], (int) $item['quantity']);
            }

            $pdo->commit();
            return true;
        } catch (ConflictException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Restores stock for every line of an order (inventory policy).
     *
     * Idempotent wrapper around releaseInventoryOnce(): safe to call from
     * any release path (failed card callback, customer/admin cancellation,
     * expiry of stale card-pending orders) — repeats are no-ops.
     */
    public function restoreStockForOrder(int $orderId): void
    {
        $this->releaseInventoryOnce($orderId);
    }

    /**
     * Cancels an order and restores its stock (customer or admin path).
     *
     * ONE atomic transaction: the order may only leave a reserving state
     * (pending/processing, never shipped/delivered, never already
     * cancelled) while simultaneously claiming the inventory release and
     * restoring every line's stock. A repeated cancel returns the already
     * cancelled order without touching stock again.
     *
     * Paid orders are NEVER cancelled automatically: money has changed hands
     * (card callback success or explicit admin payment confirmation), so
     * cancelling + restoring stock would keep the payment while returning
     * the goods to the shelf. The paid check runs both before the lock
     * (fast, advisory) and after SELECT ... FOR UPDATE inside the
     * transaction (authoritative — a concurrent payment callback that lands
     * between the two reads cannot bypass it).
     *
     * @return Order Fresh order with items.
     *
     * @throws ConflictException When the order cannot be cancelled.
     */
    public function cancelOrder(int $orderId): Order
    {
        $existing = $this->getOrder($orderId);
        if ($existing === null) {
            throw new ConflictException('Order not found.');
        }
        // Idempotent fast path: already terminal, nothing to restore
        // (the first cancellation released the stock).
        if ($existing->status === 'cancelled') {
            return $existing;
        }
        if (!in_array($existing->status, ['pending', 'processing'], true)) {
            throw new ConflictException('Only pending or processing orders can be cancelled.');
        }
        if ($existing->paymentStatus === 'paid') {
            throw new ConflictException('Paid orders cannot be cancelled automatically. Process a refund before cancelling.');
        }

        $pdo = \App\Core\Database::get();
        $pdo->beginTransaction();
        try {
            $locked = $this->orders->findByIdForUpdate($orderId);
            if ($locked === null) {
                $pdo->rollBack();
                throw new ConflictException('Order not found.');
            }
            if ($locked->status === 'cancelled') {
                $pdo->rollBack();
                $fresh = $this->getOrder($orderId);
                if ($fresh === null) {
                    throw new ConflictException('Order not found.');
                }
                return $fresh;
            }
            if (!in_array($locked->status, ['pending', 'processing'], true)) {
                $pdo->rollBack();
                throw new ConflictException('Only pending or processing orders can be cancelled.');
            }
            // Authoritative paid check under the row lock: a payment callback
            // that committed between the pre-lock read and now is visible
            // here, so paid money can never be cancelled away. No stock is
            // restored on this path.
            if ($locked->paymentStatus === 'paid') {
                $pdo->rollBack();
                throw new ConflictException('Paid orders cannot be cancelled automatically. Process a refund before cancelling.');
            }

            // Claim the release together with the status change; a lost race
            // means a concurrent path already cancelled+released.
            if (!$this->orders->updateStatusAndRelease($orderId, ['status' => 'cancelled'])) {
                $pdo->rollBack();
                $fresh = $this->getOrder($orderId);
                if ($fresh === null) {
                    throw new ConflictException('Order not found.');
                }
                return $fresh;
            }

            foreach ($this->orders->itemsForOrder($orderId) as $item) {
                $this->orders->incrementStock((int) $item['product_variant_id'], (int) $item['quantity']);
            }

            $pdo->commit();
        } catch (ConflictException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $fresh = $this->getOrder($orderId);
        if ($fresh === null) {
            throw new ConflictException('Order not found.');
        }
        return $fresh;
    }

    /**
     * Records a verified FAILED card payment and releases the reservation.
     *
     * ONE atomic transaction: only a still-pending card payment may move to
     * failed, and the inventory release is claimed in the same UPDATE. An
     * already-failed order returns as-is (idempotent replay, no second
     * restore); a non-pending payment (paid, or changed concurrently) is a
     * 409 conflict. Cancellation does NOT block this transition — a
     * cancelled order's payment may still truthfully be recorded as failed
     * (its stock was already released by the cancellation, so the claim
     * safely reports "already released").
     *
     * @return Order Fresh order with items.
     *
     * @throws ConflictException When the transition is not allowed.
     */
    public function markCardPaymentFailed(int $orderId): Order
    {
        $pdo = \App\Core\Database::get();
        $pdo->beginTransaction();
        try {
            $locked = $this->orders->findByIdForUpdate($orderId);
            if ($locked === null) {
                $pdo->rollBack();
                throw new ConflictException('Order not found.');
            }
            if ($locked->paymentMethod !== 'card') {
                $pdo->rollBack();
                throw new ConflictException('Order does not use card payment.');
            }
            if ($locked->paymentStatus === 'failed') {
                $pdo->rollBack();
                $fresh = $this->getOrder($orderId);
                if ($fresh === null) {
                    throw new ConflictException('Order not found.');
                }
                return $fresh;
            }
            if ($locked->paymentStatus !== 'pending') {
                $pdo->rollBack();
                throw new ConflictException('Invalid payment state transition.');
            }

            $claimed = $this->orders->updateStatusAndRelease($orderId, ['payment_status' => 'failed']);
            if ($claimed) {
                foreach ($this->orders->itemsForOrder($orderId) as $item) {
                    $this->orders->incrementStock((int) $item['product_variant_id'], (int) $item['quantity']);
                }
            }

            $pdo->commit();
        } catch (ConflictException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // updateStatusAndRelease is conditional on the marker: when the stock
        // was already released (e.g. a concurrent cancellation won the race)
        // the payment_status write is skipped — re-apply it without touching
        // stock so the payment truth is still recorded exactly once.
        $fresh = $this->getOrder($orderId);
        if ($fresh === null) {
            throw new ConflictException('Order not found.');
        }
        if ($fresh->paymentStatus !== 'failed') {
            $this->orders->updateStatus($orderId, ['payment_status' => 'failed']);
            $fresh = $this->getOrder($orderId);
            if ($fresh === null) {
                throw new ConflictException('Order not found.');
            }
        }
        return $fresh;
    }

    /**
     * Records a verified SUCCESSFUL card payment.
     *
     * Late-payment guard: a released order (failed, cancelled or expired —
     * its stock already went back to the shelf and may have been resold)
     * can NEVER become paid afterwards. Likewise a cancelled order or a
     * non-pending payment is rejected. A replay of an already-paid order
     * returns it unchanged (idempotent).
     *
     * @return Order Fresh order with items.
     *
     * @throws ConflictException When the transition is not allowed.
     */
    public function markCardPaymentPaid(int $orderId): Order
    {
        $pdo = \App\Core\Database::get();
        $pdo->beginTransaction();
        try {
            $locked = $this->orders->findByIdForUpdate($orderId);
            if ($locked === null) {
                $pdo->rollBack();
                throw new ConflictException('Order not found.');
            }
            if ($locked->paymentMethod !== 'card') {
                $pdo->rollBack();
                throw new ConflictException('Order does not use card payment.');
            }
            if ($locked->paymentStatus === 'paid') {
                $pdo->rollBack();
                $fresh = $this->getOrder($orderId);
                if ($fresh === null) {
                    throw new ConflictException('Order not found.');
                }
                return $fresh;
            }
            if ($locked->paymentStatus !== 'pending') {
                $pdo->rollBack();
                throw new ConflictException('Invalid payment state transition.');
            }
            if ($locked->inventoryReleasedAt !== null) {
                $pdo->rollBack();
                throw new ConflictException('Payment cannot complete: the order was already failed or cancelled and its stock was released.');
            }
            if ($locked->status === 'cancelled') {
                $pdo->rollBack();
                throw new ConflictException('Payment cannot complete a cancelled order.');
            }

            $this->orders->updateStatus($orderId, ['payment_status' => 'paid']);
            $pdo->commit();
        } catch (ConflictException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $fresh = $this->getOrder($orderId);
        if ($fresh === null) {
            throw new ConflictException('Order not found.');
        }
        return $fresh;
    }

    /**
     * Re-opens a released reservation for a card-payment retry.
     *
     * ONE atomic transaction: only a pending order whose failed payment had
     * released its stock may retry, and only when every line is still fully
     * available. The guarded decrements re-reserve the stock and the release
     * marker is cleared together — a concurrent release attempt in the same
     * window either wins first (retry then sees a set marker... which this
     * call just cleared: the FOR UPDATE lock serializes the two, so the
     * loser observes the winner's final state).
     *
     * @return bool True when the reservation was re-acquired.
     *
     * @throws ConflictException When a retry is not allowed or stock ran out.
     */
    public function reacquireInventoryForRetry(int $orderId): bool
    {
        $pdo = \App\Core\Database::get();
        $pdo->beginTransaction();
        try {
            $locked = $this->orders->findByIdForUpdate($orderId);
            if ($locked === null) {
                $pdo->rollBack();
                throw new ConflictException('Order not found.');
            }
            if ($locked->paymentMethod !== 'card' || $locked->status !== 'pending') {
                $pdo->rollBack();
                throw new ConflictException('This order cannot be retried. Please place a new order.');
            }
            if ($locked->paymentStatus === 'paid') {
                $pdo->rollBack();
                throw new ConflictException('This order has already been paid.');
            }
            if ($locked->paymentStatus !== 'failed' || $locked->inventoryReleasedAt === null) {
                // Nothing to re-reserve (still reserved, or never released):
                // retry may proceed without touching stock.
                $pdo->rollBack();
                return false;
            }

            foreach ($this->orders->itemsForOrder($orderId) as $item) {
                if (!$this->orders->decrementStock((int) $item['product_variant_id'], (int) $item['quantity'])) {
                    $pdo->rollBack();
                    throw new ConflictException(
                        'Some items just sold out — please review your cart and place a new order.'
                    );
                }
            }

            if (!$this->orders->clearInventoryRelease($orderId)) {
                $pdo->rollBack();
                throw new ConflictException('This order cannot be retried. Please place a new order.');
            }

            $pdo->commit();
            return true;
        } catch (ConflictException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Expires one stale card-pending order (expiry cron path).
     *
     * Safe under concurrency: the order is re-validated under lock
     * (card + pending/pending + older than the window + not released) and
     * the cancelled/failed transition claims the release in the same
     * conditional UPDATE. A lost race (cancelled or failed concurrently)
     * returns false — a skip, not an error.
     *
     * @return bool True when THIS call expired the order.
     */
    public function expireStaleCardOrder(int $orderId, int $olderThanMinutes): bool
    {
        $pdo = \App\Core\Database::get();
        $pdo->beginTransaction();
        try {
            $locked = $this->orders->findByIdForUpdate($orderId);
            if (
                $locked === null
                || $locked->paymentMethod !== 'card'
                || $locked->paymentStatus !== 'pending'
                || $locked->status !== 'pending'
                || $locked->inventoryReleasedAt !== null
                || strtotime((string) $locked->createdAt) > time() - $olderThanMinutes * 60
            ) {
                $pdo->rollBack();
                return false;
            }

            if (!$this->orders->updateStatusAndRelease($orderId, ['status' => 'cancelled', 'payment_status' => 'failed'])) {
                $pdo->rollBack();
                return false;
            }

            foreach ($this->orders->itemsForOrder($orderId) as $item) {
                $this->orders->incrementStock((int) $item['product_variant_id'], (int) $item['quantity']);
            }

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Effective unit price for a variant row (joined with product).
     *
     * Rule: if the parent product has a valid sale_price (sale_price IS NOT NULL,
     * sale_price > 0, sale_price < base_price) that price applies to every
     * variant; variant.price is ignored in that case. This keeps the storefront
     * display, cart validation, and order total in sync.
     */
    private function effectiveUnitPrice(array $row): string
    {
        $salePrice = $row['sale_price'] ?? null;
        $basePrice = $row['base_price'] ?? null;
        if (
            $salePrice !== null && $salePrice !== ''
            && (float) $salePrice > 0
            && $basePrice !== null && $basePrice !== ''
            && (float) $salePrice < (float) $basePrice
        ) {
            return (string) $salePrice;
        }

        return $row['price'] ?? $row['base_price'];
    }

    /**
     * Whether two decimal price strings represent different amounts.
     */
    private static function pricesDiffer(string $authoritative, string $client): bool
    {
        return abs(((float) $authoritative) - ((float) $client)) > 0.004;
    }

    /**
     * Whether a payment method is disabled via `settings` (admin toggle).
     * Missing row defaults to enabled ("1") to keep fresh installs working.
     */
    private function isPaymentMethodDisabled(string $method): bool
    {
        $key = "payment_{$method}_enabled";
        if (!in_array($key, ['payment_cod_enabled', 'payment_whatsapp_enabled', 'payment_card_enabled'], true)) {
            return false;
        }
        try {
            $value = $this->settings->get($key);
        } catch (\Throwable) {
            return false;
        }
        return $value === '0';
    }

    /**
     * Sends order confirmation email (best-effort, never fails the order).
     *
     * @param Order  $order        The order object with items.
     * @param string $customerName Customer name for email.
     */
    private function sendOrderConfirmationEmail(Order $order, string $customerName): void
    {
        // Respect admin toggle (default enabled)
        try {
            if ($this->settings->get('notify_customer_order_confirmation') === '0') {
                return;
            }
        } catch (\Throwable) {
            // ignore settings read errors, proceed to send
        }

        // Get email from order (now required for all orders)
        $email = $order->email ?? null;

        // Fallback to user email if order email is missing (backward compatibility)
        if (($email === null || $email === '') && $order->userId !== null) {
            $userRepo = new \App\Repositories\UserRepository();
            $user = $userRepo->findById($order->userId);
            if ($user !== null) {
                $email = $user->email;
            }
        }

        // If still no email available, skip silently
        if ($email === null || $email === '') {
            return;
        }

        try {
            $this->email->sendOrderConfirmation($email, $customerName, $order->toArray());
        } catch (\RuntimeException $e) {
            // Log failure but don't throw — order placement already succeeded
            error_log(sprintf(
                '[Email] Failed to send order confirmation for order #%d: %s',
                $order->id,
                $e->getMessage()
            ));
        }
    }

    /**
     * Sends shipping status update email (best-effort).
     *
     * @param Order  $order        The order object.
     * @param string $customerName Customer name.
     * @param string $email        Customer email.
     */
    private function sendShippingStatusEmail(Order $order, string $customerName, string $email): void
    {
        try {
            if ($this->settings->get('notify_customer_shipping_update') === '0') {
                return;
            }
        } catch (\Throwable) {
            // ignore
        }
        if ($email === '') {
            return;
        }

        // Only send for meaningful status changes
        if (!in_array($order->status, ['processing', 'shipped', 'delivered'], true)) {
            return;
        }

        try {
            $this->email->sendShippingStatusUpdate($email, $customerName, $order->toArray());
        } catch (\RuntimeException $e) {
            error_log(sprintf(
                '[Email] Failed to send shipping status for order #%d: %s',
                $order->id,
                $e->getMessage()
            ));
        }
    }
}