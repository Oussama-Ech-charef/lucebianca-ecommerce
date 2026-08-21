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

    public function __construct()
    {
        $this->orders = new OrderRepository();
        $this->email = new EmailService();
        $this->notifications = new NotificationService();
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

            $unitPrice          = $row['price'] ?? $row['base_price'];
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
     * 1. Validates customer fields + payment method (only 'cod' and 'whatsapp'
     *    are accepted this phase — 'card' is rejected clearly, CMI/Payzone
     *    lands in phase 8).
     * 2. Re-checks every line against live stock (409 on insufficiency).
     * 3. In ONE transaction: insert orders, insert order_items with the
     *    server-computed price, and decrement each variant's stock via a
     *    guarded UPDATE (never below zero; affected-rows checked).
     *
     * @param string          $customerName    Customer name.
     * @param string          $phone           Customer phone.
     * @param string          $shippingAddress Shipping address.
     * @param string          $paymentMethod   'cod' | 'whatsapp' (card rejected).
     * @param array<int, mixed> $lines         {variant_id, quantity} lines.
     * @param int|null        $userId          Optional authenticated customer id
     *                                         (null = guest checkout) so a
     *                                         logged-in order shows up on the
     *                                         customer's /account page.
     *
     * @return Order The created order with its line items populated.
     *
     * @throws ValidationException On invalid fields or payment method.
     * @throws ConflictException   When any line's stock is insufficient.
     */
    public function placeOrder(
        string $customerName,
        string $phone,
        string $shippingAddress,
        string $paymentMethod,
        array $lines,
        ?int $userId = null
    ): Order {
        // --- Customer fields ---
        $errors = Validator::validate(
            [
                'customer_name'    => $customerName,
                'phone'            => $phone,
                'shipping_address' => $shippingAddress,
            ],
            [
                'customer_name'    => ['required', ['min', 2]],
                'phone'            => ['required'],
                'shipping_address' => ['required'],
            ]
        );

        if ($phone !== '' && preg_match('/^[0-9+()\-\s]{8,20}$/', $phone) !== 1) {
            $errors['phone'] = 'Phone must be a valid number (8-20 digits).';
        }

        // --- Payment method: validate against supported methods ---
        if (!in_array($paymentMethod, ['cod', 'whatsapp', 'card'], true)) {
            $errors['payment_method'] = 'Payment method must be "cod", "whatsapp", or "card".';
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
        $totalCents = 0;
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

            $unitPrice = (string) ($row['price'] ?? $row['base_price']);
            $totalCents += (int) round(((float) $unitPrice) * 100) * $line['quantity'];
            $resolved[] = [
                'variant_id' => $line['variant_id'],
                'quantity'   => $line['quantity'],
                'unit_price' => $unitPrice,
                'product_name' => (string) $row['product_name'],
            ];
        }

        $totalAmount = number_format($totalCents / 100, 2, '.', '');

        // --- One transaction: order + items + guarded stock decrement. ---
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $orderId = $this->orders->insertOrder([
                'total_amount'     => $totalAmount,
                'shipping_address' => $shippingAddress,
                'customer_name'    => $customerName,
                'phone'            => $phone,
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

                // Check for low stock after decrement (threshold: 5 units)
                $remainingStock = (int) $rows[$line['variant_id']]['stock_quantity'] - $line['quantity'];
                if ($remainingStock > 0 && $remainingStock <= 5) {
                    $this->notifications->notifyLowStock(
                        $line['product_name'],
                        (string) $rows[$line['variant_id']]['size'],
                        (string) $rows[$line['variant_id']]['color'],
                        $remainingStock
                    );
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

        // Send shipping status email if order status changed
        if ($order !== null && isset($fields['status'])) {
            // Get customer name and email for notification
            $customerName = $order->customer_name;
            $email = null;

            if ($order->user_id !== null) {
                $userRepo = new \App\Repositories\UserRepository();
                $user = $userRepo->findById($order->user_id);
                if ($user !== null) {
                    $email = $user->email;
                }
            }

            if ($email !== null) {
                $this->sendShippingStatusEmail($order, $customerName, $email);
            }
        }

        return $order;
    }

    /**
     * Normalizes + validates a raw items payload into clean lines.
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

        $normalized = [];
        foreach ($lines as $i => $line) {
            if (!is_array($line)) {
                throw new ValidationException(["items.{$i}" => 'Each cart line must be an object.']);
            }

            $variantId = $line['variant_id'] ?? null;
            $quantity  = $line['quantity'] ?? null;

            if (!is_numeric($variantId) || (int) $variantId < 1) {
                throw new ValidationException(["items.{$i}.variant_id" => 'Variant id must be a positive integer.']);
            }
            if (!is_numeric($quantity) || (int) $quantity < 1 || (int) $quantity > 9999) {
                throw new ValidationException(["items.{$i}.quantity" => 'Quantity must be between 1 and 9999.']);
            }

            $clientPrice = $line['unit_price'] ?? null;
            if ($clientPrice !== null && $clientPrice !== '' && !is_numeric($clientPrice)) {
                throw new ValidationException(["items.{$i}.unit_price" => 'Unit price must be a number.']);
            }

            $normalized[] = [
                'variant_id'  => (int) $variantId,
                'quantity'    => (int) $quantity,
                'unit_price'  => $clientPrice === null || $clientPrice === '' ? null : (string) $clientPrice,
            ];
        }

        return $normalized;
    }

    /**
     * Whether two decimal price strings represent different amounts.
     */
    private static function pricesDiffer(string $authoritative, string $client): bool
    {
        return abs(((float) $authoritative) - ((float) $client)) > 0.004;
    }

    /**
     * Sends order confirmation email (best-effort, never fails the order).
     *
     * @param Order  $order        The order object with items.
     * @param string $customerName Customer name for email.
     */
    private function sendOrderConfirmationEmail(Order $order, string $customerName): void
    {
        // Extract email from order or user
        $email = null;

        // Try to get email from user if order is attributed
        if ($order->user_id !== null) {
            $userRepo = new \App\Repositories\UserRepository();
            $user = $userRepo->findById($order->user_id);
            if ($user !== null) {
                $email = $user->email;
            }
        }

        // If no email available, skip silently (guest checkout without email)
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