<?php

namespace App\Services;

use App\Core\Env;

/**
 * EmailService — transactional email via the Resend REST API.
 *
 * Sends email notifications:
 * - Account verification (phase 16)
 * - Order confirmation (phase 3)
 * - Shipping status updates (phase 3)
 * - Admin notifications (phase 3)
 *
 * Delivering through Resend's HTTP API (POST /emails) keeps the
 * API dependency-free — no SDK, the same plain-PHP HTTP style as
 * GoogleOAuthService. No SMTP credentials are stored or handled here.
 *
 * Configuration (api/.env, both gitignored):
 *   RESEND_API_KEY    Resend API key (outbound permission).
 *   RESEND_FROM_EMAIL Sender address. Resend's free tier allows the shared
 *                     onboarding@resend.dev sender; it must be replaced with
 *                     a verified lucebianca.co address before launch.
 *
 * Failures throw \RuntimeException so the caller decides how to degrade:
 * AuthService treats the verification email as best-effort (a failed send
 * never fails registration). Order emails are best-effort too — failed sends
 * are logged but don't block order placement.
 */
final class EmailService
{
    private const RESEND_URL = 'https://api.resend.com/emails';

    /**
     * Sends a verification email to a newly registered customer.
     *
     * @param string $to               Recipient email address.
     * @param string $name             Recipient display name.
     * @param string $verificationLink Absolute URL to /verify-email?token=…
     *
     * @throws \RuntimeException When Resend is unreachable or rejects the send.
     */
    public function sendVerificationEmail(string $to, string $name, string $verificationLink): void
    {
        $this->send(
            $to,
            'Confirm your Luce Bianca account',
            $this->verificationHtml($name, $verificationLink)
        );
    }

    /**
     * Sends an order confirmation email to the customer.
     *
     * @param string $to           Customer email address.
     * @param string $customerName Customer name.
     * @param array  $orderData    Order details (id, total_amount, items, etc.)
     *
     * @throws \RuntimeException When Resend is unreachable or rejects the send.
     */
    public function sendOrderConfirmation(string $to, string $customerName, array $orderData): void
    {
        $this->send(
            $to,
            'Order Confirmation #' . $orderData['id'] . ' - Luce Bianca',
            $this->orderConfirmationHtml($customerName, $orderData)
        );
    }

    /**
     * Sends a shipping status update email to the customer.
     *
     * @param string $to           Customer email address.
     * @param string $customerName Customer name.
     * @param array  $orderData    Order details (id, status, etc.)
     *
     * @throws \RuntimeException When Resend is unreachable or rejects the send.
     */
    public function sendShippingStatusUpdate(string $to, string $customerName, array $orderData): void
    {
        $status = $orderData['status'] ?? 'processing';
        $subject = match ($status) {
            'processing' => 'Your order is being prepared',
            'shipped' => 'Your order has been shipped',
            'delivered' => 'Your order has been delivered',
            default => 'Order status update',
        };

        $this->send(
            $to,
            $subject . ' - Order #' . $orderData['id'],
            $this->shippingStatusHtml($customerName, $orderData)
        );
    }

    /**
     * Sends a notification email to admin.
     *
     * @param string $to      Admin email address.
     * @param string $subject Email subject.
     * @param string $message Email body (plain text or HTML).
     *
     * @throws \RuntimeException When Resend is unreachable or rejects the send.
     */
    public function sendAdminNotification(string $to, string $subject, string $message): void
    {
        $this->send($to, $subject, $this->adminNotificationHtml($subject, $message));
    }

    /**
     * Sends a transactional email through Resend.
     *
     * @param string $to      Recipient email address.
     * @param string $subject Email subject line.
     * @param string $html    HTML body.
     *
     * @throws \RuntimeException When Resend is unreachable or rejects the send.
     */
    public function send(string $to, string $subject, string $html): void
    {
        $apiKey = (string) Env::get('RESEND_API_KEY', '');

        if ($apiKey === '') {
            throw new \RuntimeException('RESEND_API_KEY is not configured.');
        }

        $payload = json_encode([
            'from'    => (string) Env::get('RESEND_FROM_EMAIL', 'onboarding@resend.dev'),
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new \RuntimeException('Could not encode the email payload.');
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => 15,
                'ignore_errors' => true,
                'header'        => "Authorization: Bearer $apiKey\r\nContent-Type: application/json\r\nAccept: application/json",
                'content'       => $payload,
            ],
        ]);

        $body = @file_get_contents(self::RESEND_URL, false, $context);

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        if ($body === false || $status >= 500) {
            throw new \RuntimeException('Email service is unreachable.');
        }

        if ($status >= 400) {
            throw new \RuntimeException('Email service rejected the message.');
        }
    }

    /**
     * Small, self-contained HTML template for the verification email.
     * Inline styles only (no external assets) so it renders in any client.
     */
    private function verificationHtml(string $name, string $verificationLink): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#fafafa;font-family:Arial,Helvetica,sans-serif;color:#171717;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#ffffff;border:1px solid #e5e5e5;border-radius:12px;padding:32px;">
          <tr>
            <td style="font-family:Georgia,'Times New Roman',serif;font-size:24px;color:#171717;padding-bottom:16px;">
              Luce Bianca
            </td>
          </tr>
          <tr>
            <td style="font-size:14px;line-height:1.6;color:#404040;">
              Hi $safeName,<br/><br/>
              Thanks for creating an account. Please confirm your email address to finish signing up.
            </td>
          </tr>
          <tr>
            <td style="padding:24px 0;">
              <a href="$safeLink" style="display:inline-block;background:#171717;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 24px;border-radius:8px;">
                Verify my email
              </a>
            </td>
          </tr>
          <tr>
            <td style="font-size:12px;line-height:1.6;color:#737373;">
              This link expires in 24 hours. If you did not create this account, you can ignore this email.<br/><br/>
              If the button does not work, copy this link into your browser:<br/>
              <span style="color:#404040;">$safeLink</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * HTML template for order confirmation email.
     */
    private function orderConfirmationHtml(string $customerName, array $orderData): string
    {
        $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
        $orderId = (int) $orderData['id'];
        $totalAmount = htmlspecialchars($orderData['total_amount'] ?? '0.00', ENT_QUOTES, 'UTF-8');
        $shippingAddress = htmlspecialchars($orderData['shipping_address'] ?? '', ENT_QUOTES, 'UTF-8');
        $paymentMethod = htmlspecialchars($orderData['payment_method'] ?? '', ENT_QUOTES, 'UTF-8');

        // Build items list
        $itemsHtml = '';
        if (!empty($orderData['items'])) {
            foreach ($orderData['items'] as $item) {
                $productName = htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8');
                $size = htmlspecialchars($item['size'] ?? '', ENT_QUOTES, 'UTF-8');
                $color = htmlspecialchars($item['color'] ?? '', ENT_QUOTES, 'UTF-8');
                $quantity = (int) ($item['quantity'] ?? 1);
                $price = htmlspecialchars($item['price_at_purchase'] ?? '0.00', ENT_QUOTES, 'UTF-8');

                $itemsHtml .= <<<HTML
              <tr>
                <td style="padding:8px 0;border-bottom:1px solid #e5e5e5;">
                  <strong>$productName</strong><br/>
                  <span style="color:#737373;font-size:12px;">Size: $size | Color: $color</span>
                </td>
                <td style="padding:8px 0;border-bottom:1px solid #e5e5e5;text-align:right;">$quantity</td>
                <td style="padding:8px 0;border-bottom:1px solid #e5e5e5;text-align:right;">$price MAD</td>
              </tr>
HTML;
            }
        }

        $paymentMethodText = match ($paymentMethod) {
            'cod' => 'Cash on Delivery',
            'whatsapp' => 'Order via WhatsApp',
            'card' => 'Credit/Debit Card',
            default => ucfirst($paymentMethod),
        };

        return <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#fafafa;font-family:Arial,Helvetica,sans-serif;color:#171717;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e5e5e5;border-radius:12px;padding:32px;">
          <tr>
            <td style="font-family:Georgia,'Times New Roman',serif;font-size:24px;color:#171717;padding-bottom:16px;">
              Luce Bianca
            </td>
          </tr>
          <tr>
            <td style="font-size:14px;line-height:1.6;color:#404040;">
              Hi $safeName,<br/><br/>
              Thank you for your order! We've received it and will start processing shortly.
            </td>
          </tr>
          <tr>
            <td style="padding:24px 0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;border-radius:8px;padding:16px;">
                <tr>
                  <td style="font-size:14px;font-weight:600;color:#171717;">Order #$orderId</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                <tr style="font-weight:600;border-bottom:2px solid #171717;">
                  <td style="padding:8px 0;">Item</td>
                  <td style="padding:8px 0;text-align:right;">Qty</td>
                  <td style="padding:8px 0;text-align:right;">Price</td>
                </tr>
                $itemsHtml
                <tr style="font-weight:600;">
                  <td colspan="2" style="padding:16px 0 8px 0;">Total</td>
                  <td style="padding:16px 0 8px 0;text-align:right;">$totalAmount MAD</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 0;border-top:1px solid #e5e5e5;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                <tr>
                  <td style="color:#737373;padding:4px 0;">Payment Method:</td>
                  <td style="text-align:right;padding:4px 0;">$paymentMethodText</td>
                </tr>
                <tr>
                  <td style="color:#737373;padding:4px 0;">Shipping Address:</td>
                  <td style="text-align:right;padding:4px 0;">$shippingAddress</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="font-size:12px;line-height:1.6;color:#737373;padding-top:16px;">
              We'll send you another email when your order ships. If you have any questions, feel free to reply to this email.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * HTML template for shipping status update email.
     */
    private function shippingStatusHtml(string $customerName, array $orderData): string
    {
        $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
        $orderId = (int) $orderData['id'];
        $status = $orderData['status'] ?? 'processing';

        $statusMessage = match ($status) {
            'processing' => 'Your order is being prepared and will ship soon.',
            'shipped' => 'Your order has been shipped and is on its way to you.',
            'delivered' => 'Your order has been delivered. We hope you enjoy your purchase!',
            default => 'Your order status has been updated.',
        };

        $statusColor = match ($status) {
            'processing' => '#f59e0b',
            'shipped' => '#3b82f6',
            'delivered' => '#10b981',
            default => '#737373',
        };

        return <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#fafafa;font-family:Arial,Helvetica,sans-serif;color:#171717;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#ffffff;border:1px solid #e5e5e5;border-radius:12px;padding:32px;">
          <tr>
            <td style="font-family:Georgia,'Times New Roman',serif;font-size:24px;color:#171717;padding-bottom:16px;">
              Luce Bianca
            </td>
          </tr>
          <tr>
            <td style="font-size:14px;line-height:1.6;color:#404040;">
              Hi $safeName,<br/><br/>
              $statusMessage
            </td>
          </tr>
          <tr>
            <td style="padding:24px 0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;border-radius:8px;padding:16px;">
                <tr>
                  <td style="font-size:14px;color:#737373;">Order Number</td>
                  <td style="font-size:14px;font-weight:600;text-align:right;">#$orderId</td>
                </tr>
                <tr>
                  <td colspan="2" style="padding-top:8px;">
                    <span style="display:inline-block;background:$statusColor;color:#ffffff;font-size:12px;font-weight:600;padding:4px 12px;border-radius:4px;text-transform:uppercase;">$status</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="font-size:12px;line-height:1.6;color:#737373;">
              Questions about your order? Reply to this email and we'll be happy to help.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * HTML template for admin notification emails.
     */
    private function adminNotificationHtml(string $subject, string $message): string
    {
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        return <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#fafafa;font-family:Arial,Helvetica,sans-serif;color:#171717;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#ffffff;border:1px solid #e5e5e5;border-radius:12px;padding:32px;">
          <tr>
            <td style="font-family:Georgia,'Times New Roman',serif;font-size:24px;color:#171717;padding-bottom:16px;">
              Luce Bianca Admin
            </td>
          </tr>
          <tr>
            <td style="font-size:16px;font-weight:600;color:#171717;padding-bottom:16px;">
              $safeSubject
            </td>
          </tr>
          <tr>
            <td style="font-size:14px;line-height:1.6;color:#404040;">
              $safeMessage
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}