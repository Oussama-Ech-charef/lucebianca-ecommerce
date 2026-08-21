<?php

namespace App\Services;

use App\Core\Env;

/**
 * EmailService — transactional email via the Resend REST API.
 *
 * Phase 16 sends a one-time email-verification link to newly registered
 * customers. Delivering through Resend's HTTP API (POST /emails) keeps the
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
 * never fails registration).
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
}