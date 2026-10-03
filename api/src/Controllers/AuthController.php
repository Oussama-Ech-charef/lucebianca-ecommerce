<?php

namespace App\Controllers;

use App\Core\ConflictException;
use App\Core\InvalidCredentialsException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\AuthService;

/**
 * AuthController — public registration / login / Google OAuth.
 *
 * Session security: the long-lived refresh token is issued BOTH as a
 * JSON field (backward compat for existing localStorage clients) AND as
 * an HttpOnly/Secure/SameSite=Lax cookie (`lb_customer_refresh`). New
 * clients should prefer the cookie (JS cannot read it — XSS cannot steal
 * it) by sending `credentials: "include"` and omitting the body token;
 * refresh/logout accept either source (body wins when both present).
 */
final class AuthController extends Controller
{
    private const REFRESH_COOKIE = 'lb_customer_refresh';

    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    /**
     * POST /api/auth/register — creates a customer account.
     *
     * @return never 201 with the auth payload, or 422/409 on errors.
     */
    public function register(Request $request): never
    {
        $phone = $request->input('phone');

        try {
            $payload = $this->auth->register(
                (string) $request->input('name', ''),
                (string) $request->input('email', ''),
                (string) $request->input('password', ''),
                $phone === null ? null : (string) $phone
            );
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (ConflictException $e) {
            $this->error($e->getMessage(), 409);
        }

        \App\Core\Response::refreshCookie(self::REFRESH_COOKIE, (string) ($payload['refresh_token'] ?? ''), (int) \App\Core\Env::get('JWT_REFRESH_TTL_SECONDS', 604800));
        $this->json($payload, 201);
    }

    /**
     * POST /api/auth/login — email/password login.
     *
     * @return never 200 with the auth payload, 422 on bad input, 401 on
     *               invalid credentials.
     */
    public function login(Request $request): never
    {
        try {
            $payload = $this->auth->login(
                (string) $request->input('email', ''),
                (string) $request->input('password', '')
            );
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (InvalidCredentialsException $e) {
            $this->error($e->getMessage(), 401);
        }

        \App\Core\Response::refreshCookie(self::REFRESH_COOKIE, (string) ($payload['refresh_token'] ?? ''), (int) \App\Core\Env::get('JWT_REFRESH_TTL_SECONDS', 604800));
        $this->json($payload);
    }

    /**
     * POST /api/auth/refresh — exchanges a refresh token for a new pair.
     *
     * Accepts the token from JSON body OR the HttpOnly cookie (body wins).
     * Rotation + cookie re-issue keep both transports in sync.
     *
     * @return never 200 with a new auth payload, 422 on missing token,
     *               401 on unknown/revoked/expired token.
     */
    public function refresh(Request $request): never
    {
        $bodyToken = (string) $request->input('refresh_token', '');
        $cookieToken = (string) ($_COOKIE[self::REFRESH_COOKIE] ?? '');
        $presented = $bodyToken !== '' ? $bodyToken : $cookieToken;
        try {
            $payload = $this->auth->refresh($presented);
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (InvalidCredentialsException $e) {
            $this->error($e->getMessage(), 401);
        }

        \App\Core\Response::refreshCookie(self::REFRESH_COOKIE, (string) ($payload['refresh_token'] ?? ''), (int) \App\Core\Env::get('JWT_REFRESH_TTL_SECONDS', 604800));
        $this->json($payload);
    }

    /**
     * POST /api/auth/logout — revokes the client's refresh token.
     *
     * Accepts body or cookie; clears the cookie. Idempotent.
     *
     * @return never 204 on success, 422 on missing token.
     */
    public function logout(Request $request): never
    {
        $bodyToken = (string) $request->input('refresh_token', '');
        $cookieToken = (string) ($_COOKIE[self::REFRESH_COOKIE] ?? '');
        $presented = $bodyToken !== '' ? $bodyToken : $cookieToken;
        try {
            $this->auth->logout($presented);
        } catch (ValidationException $e) {
            // Still clear the cookie — the client asked to log out.
            \App\Core\Response::refreshCookie(self::REFRESH_COOKIE, '', -1);
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        }

        \App\Core\Response::refreshCookie(self::REFRESH_COOKIE, '', -1);
        $this->noContent();
    }

    /**
     * POST /api/auth/google — register/login via Google OAuth 2.0.
     *
     * Body: {"id_token": "<JWT from Google Identity Services>"}. The token is
     * verified server-side (signature via Google + aud/iss/exp/email checks in
     * App\Services\GoogleOAuthService) and the account is created or linked by
     * verified email, then the standard JWT + refresh token pair is issued.
     *
     * @return never 200 with the auth payload, 422 on a missing token,
     *               401 on an invalid/rejected token, 502 when Google is
     *               unreachable.
     */
    public function google(Request $request): never
    {
        try {
            $payload = $this->auth->loginWithGoogle((string) $request->input('id_token', ''));
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (InvalidCredentialsException $e) {
            $this->error($e->getMessage(), 401);
        } catch (\RuntimeException $e) {
            $this->error('Google sign-in is temporarily unavailable. Please try again.', 502);
        }

        \App\Core\Response::refreshCookie(self::REFRESH_COOKIE, (string) ($payload['refresh_token'] ?? ''), (int) \App\Core\Env::get('JWT_REFRESH_TTL_SECONDS', 604800));
        $this->json($payload);
    }

    /**
     * GET /api/auth/verify-email — confirm a customer's email address.
     *
     * Query: ?token=<one-time verification token from the emailed link>. On
     * success the user is marked verified and the token is cleared (single
     * use). Validation errors are 422; an unknown/already-used/expired token
     * is a clean 400 with a human-readable message.
     *
     * @return never 200 with a success message, or 422/400 on errors.
     */
    public function verifyEmail(Request $request): never
    {
        try {
            $this->auth->verifyEmail((string) $request->query('token', ''));
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (InvalidCredentialsException $e) {
            $this->error($e->getMessage(), 400);
        }

        $this->json(['message' => 'Your email address has been verified.']);
    }

    /**
     * POST /api/auth/resend-verification — re-send the verification email.
     *
     * Body: {"email": "..."}. The response is deliberately identical whether
     * the email is unknown, already verified or unverified (anti-enumeration);
     * only a real unverified account gets a fresh link, which invalidates the
     * previous one.
     *
     * @return never 200 with a generic message, 422 on a missing/invalid email.
     */
    public function resendVerification(Request $request): never
    {
        try {
            $this->auth->resendVerification((string) $request->input('email', ''));
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        }

        $this->json([
            'message' => 'If that email belongs to an unverified account, a verification email is on its way.',
        ]);
    }

    /**
     * POST /api/auth/forgot-password — request a one-time reset link.
     *
     * Anti-enumeration: identical 200 whether the email exists or not.
     * Rate-limited (see routes.php). Only a real account gets a fresh
     * single-use 1h token (old one invalidated); refresh sessions are
     * revoked on successful reset.
     *
     * @return never 200 with a generic message, 422 on invalid email.
     */
    public function forgotPassword(Request $request): never
    {
        try {
            $this->auth->requestPasswordReset((string) $request->input('email', ''));
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        }

        $this->json([
            'message' => 'If that email belongs to an account, a reset link is on its way (expires in 1 hour).',
        ]);
    }

    /**
     * POST /api/auth/reset-password — complete a reset with the emailed token.
     *
     * Body: {"token": "...", "password": "new-password-8+"}. Single-use:
     * success clears the token and revokes all refresh sessions.
     *
     * @return never 200 on success, 422 on weak input, 400 on invalid/expired token.
     */
    public function resetPassword(Request $request): never
    {
        try {
            $this->auth->resetPassword(
                (string) $request->input('token', ''),
                (string) $request->input('password', '')
            );
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (InvalidCredentialsException $e) {
            $this->error($e->getMessage(), 400);
        }

        $this->json(['message' => 'Your password has been reset. You can now sign in.']);
    }
}