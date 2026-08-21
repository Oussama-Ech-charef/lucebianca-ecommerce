<?php

namespace App\Services;

use App\Core\Env;

/**
 * NotificationService — admin notification handling.
 *
 * Sends notifications to admins for:
 * - New orders placed
 * - Low stock alerts (product variant below threshold)
 *
 * All notifications are configurable via settings and best-effort (failures
 * are logged but never block the triggering operation).
 */
final class NotificationService
{
    private EmailService $email;

    public function __construct()
    {
        $this->email = new EmailService();
    }

    /**
     * Sends a new order notification to admin.
     *
     * @param array $orderData Order details (id, customer_name, total_amount, etc.)
     */
    public function notifyNewOrder(array $orderData): void
    {
        $adminEmail = $this->getAdminEmail();
        if ($adminEmail === null) {
            return;
        }

        $orderId = $orderData['id'] ?? 0;
        $customerName = $orderData['customer_name'] ?? 'Unknown';
        $totalAmount = $orderData['total_amount'] ?? '0.00';
        $paymentMethod = $orderData['payment_method'] ?? '';

        $subject = "New Order #$orderId - Luce Bianca";
        $message = "A new order has been placed:\n\n"
            . "Order ID: #$orderId\n"
            . "Customer: $customerName\n"
            . "Total: $totalAmount MAD\n"
            . "Payment Method: " . $this->formatPaymentMethod($paymentMethod) . "\n\n"
            . "View order details in the admin panel.";

        try {
            $this->email->sendAdminNotification($adminEmail, $subject, $message);
        } catch (\RuntimeException $e) {
            error_log(sprintf(
                '[Notification] Failed to send new order notification for order #%d: %s',
                $orderId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Sends a low stock alert to admin.
     *
     * @param string $productName Product name.
     * @param string $size        Variant size.
     * @param string $color       Variant color.
     * @param int    $stockLeft   Remaining stock quantity.
     */
    public function notifyLowStock(string $productName, string $size, string $color, int $stockLeft): void
    {
        $adminEmail = $this->getAdminEmail();
        if ($adminEmail === null) {
            return;
        }

        $subject = "Low Stock Alert - $productName";
        $message = "Stock is running low for:\n\n"
            . "Product: $productName\n"
            . "Size: $size\n"
            . "Color: $color\n"
            . "Remaining: $stockLeft units\n\n"
            . "Please restock soon to avoid running out.";

        try {
            $this->email->sendAdminNotification($adminEmail, $subject, $message);
        } catch (\RuntimeException $e) {
            error_log(sprintf(
                '[Notification] Failed to send low stock alert for %s: %s',
                $productName,
                $e->getMessage()
            ));
        }
    }

    /**
     * Gets admin email from environment or settings.
     *
     * @return string|null Admin email address, or null if not configured.
     */
    private function getAdminEmail(): ?string
    {
        // Try environment variable first
        $email = Env::get('ADMIN_EMAIL');
        if ($email !== null && $email !== '') {
            return $email;
        }

        // TODO: When settings are implemented, read from settings table
        // For now, return null if not in environment
        return null;
    }

    /**
     * Formats payment method for display.
     */
    private function formatPaymentMethod(string $method): string
    {
        return match ($method) {
            'cod' => 'Cash on Delivery',
            'whatsapp' => 'Order via WhatsApp',
            'card' => 'Credit/Debit Card',
            default => ucfirst($method),
        };
    }

    /**
     * Check if admin notifications are enabled.
     *
     * @return bool True if admin email is configured.
     */
    public static function isEnabled(): bool
    {
        return Env::get('ADMIN_EMAIL') !== null;
    }
}
