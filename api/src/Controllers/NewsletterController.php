<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Validator;
use App\Repositories\NewsletterRepository;

/**
 * NewsletterController — public newsletter signup with signed unsubscribe.
 *
 * Unsubscribe requires a single-purpose signed token (HMAC with JWT_SECRET)
 * issued at subscribe time and emailed as a link — an email address alone
 * is NOT sufficient (prevents third parties unsubscribing anyone).
 */
final class NewsletterController extends Controller
{
    private NewsletterRepository $newsletter;

    public function __construct()
    {
        $this->newsletter = new NewsletterRepository();
    }

    /**
     * POST /api/newsletter/subscribe — subscribe to newsletter.
     *
     * Body: {"email": "user@example.com"}
     * Returns only the success message: the signed unsubscribe URL is
     * delivered by welcome email (best-effort, never blocks the subscribe),
     * not exposed in the public API response.
     */
    public function subscribe(Request $request): never
    {
        $email = trim((string) $request->input('email', ''));

        $errors = Validator::validate(
            ['email' => $email],
            ['email' => ['required', 'email']]
        );

        if ($errors !== []) {
            $this->error('Validation failed.', 422, ['errors' => $errors]);
        }

        $subscribed = $this->newsletter->subscribe($email);

        // New subscriptions and reactivations get a welcome email carrying
        // the signed one-click unsubscribe link. Best-effort by design (no
        // mailer configured locally, provider outages): failures are logged
        // and never fail the subscribe.
        if ($subscribed) {
            try {
                $emailService = new \App\Services\EmailService();
                $emailService->sendNewsletterWelcome($email, $this->unsubscribeUrl($email));
            } catch (\Throwable $e) {
                error_log('Luce Bianca: newsletter welcome email not sent: ' . $e->getMessage());
            }
        }

        $this->json([
            'data' => [
                'subscribed' => $subscribed,
                'message' => $subscribed
                    ? 'Successfully subscribed to newsletter!'
                    : 'Already subscribed.',
            ],
        ], $subscribed ? 201 : 200);
    }

    /**
     * GET /api/newsletter/unsubscribe — validate a signed unsubscribe link.
     *
     * READ-ONLY: never mutates subscription state (email scanners and link
     * previews routinely GET every link). Returns whether the link is valid
     * so the confirmation page can render accordingly; the actual
     * unsubscribe happens via POST (user confirmation click).
     */
    public function verify(Request $request): never
    {
        $email = trim((string) $request->query('email', ''));
        $token = (string) $request->query('token', '');

        $errors = Validator::validate(
            ['email' => $email, 'token' => $token],
            ['email' => ['required', 'email'], 'token' => ['required']]
        );

        if ($errors !== []) {
            $this->error('Validation failed.', 422, ['errors' => $errors]);
        }

        if (!self::verifyUnsubscribeToken($email, $token)) {
            $this->error('Invalid or expired unsubscribe link.', 403);
        }

        $this->json([
            'data' => [
                'valid' => true,
                'email' => strtolower($email),
                'subscribed' => $this->newsletter->isSubscribed($email),
            ],
        ]);
    }

    /**
     * POST /api/newsletter/unsubscribe — signed unsubscribe (state-changing).
     *
     * Body: {"email": "...", "token": "<signed token from the welcome email>"}.
     * Both fields required; token must verify (HMAC + expiry). Query params
     * are intentionally NOT accepted here: unsubscribing is a state change
     * and must come from an explicit POST (confirmation click), never from a
     * GET that scanners/prefetchers can trigger.
     */
    public function unsubscribe(Request $request): never
    {
        $email = trim((string) $request->input('email', ''));
        $token = (string) $request->input('token', '');

        $errors = Validator::validate(
            ['email' => $email, 'token' => $token],
            ['email' => ['required', 'email'], 'token' => ['required']]
        );

        if ($errors !== []) {
            $this->error('Validation failed.', 422, ['errors' => $errors]);
        }

        if (!self::verifyUnsubscribeToken($email, $token)) {
            $this->error('Invalid or expired unsubscribe link.', 403);
        }

        $unsubscribed = $this->newsletter->unsubscribe($email);

        $this->json([
            'data' => [
                'unsubscribed' => $unsubscribed,
                'message' => $unsubscribed
                    ? 'Successfully unsubscribed from newsletter.'
                    : 'Email not found in newsletter list.',
            ],
        ]);
    }

    /**
     * Builds the signed one-click unsubscribe URL emailed on subscribe.
     * Fail-closed when JWT_SECRET is misconfigured (issueUnsubscribeToken
     * throws) — the caller treats that as best-effort email failure.
     */
    private function unsubscribeUrl(string $email): string
    {
        $token = self::issueUnsubscribeToken($email);
        $site = rtrim((string) \App\Core\Env::get('SITE_URL', ''), '/');

        return $site . '/newsletter/unsubscribe?email=' . rawurlencode(strtolower(trim($email))) . '&token=' . rawurlencode($token);
    }

    /**
     * Issues a signed unsubscribe token: base64url(email|expiry|hmac).
     * 1-year TTL — long enough for email links, short enough to rotate
     * with secret changes. Fail-closed when JWT_SECRET is misconfigured.
     */
    public static function issueUnsubscribeToken(string $email): string
    {
        $secret = (string) \App\Core\Env::get('JWT_SECRET', '');
        if (trim($secret) === '' || mb_strlen(trim($secret)) < 32) {
            throw new \RuntimeException('JWT_SECRET is not configured — failing closed.');
        }
        $normalized = strtolower(trim($email));
        $expiry = time() + 365 * 86400;
        $payload = $normalized . '|' . $expiry;
        $sig = hash_hmac('sha256', $payload, trim($secret));
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }

    /**
     * Verifies a signed unsubscribe token for the given email.
     */
    public static function verifyUnsubscribeToken(string $email, string $token): bool
    {
        $secret = (string) \App\Core\Env::get('JWT_SECRET', '');
        if (trim($secret) === '' || mb_strlen(trim($secret)) < 32) {
            return false;
        }
        $padded = strtr($token, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            return false;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            return false;
        }
        [$tokenEmail, $expiry, $sig] = $parts;
        if (strtolower(trim($email)) !== strtolower(trim($tokenEmail))) {
            return false;
        }
        if (!ctype_digit($expiry) || (int) $expiry < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $tokenEmail . '|' . $expiry, trim($secret));
        return hash_equals($expected, $sig);
    }
}
