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
 * register, login, refresh and logout are implemented (password_hash, JWT +
 * refresh token issuance via App\Services\AuthService). google() verifies a
 * Google ID token server-side and creates-or-links the account by email.
 * Server-side validation errors become 422 with per-field details; a
 * duplicate email is 409; bad credentials are 401; a failed Google
 * verification is 401; Google being unreachable is 502.
 */
final class AuthController extends Controller
{
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

        $this->json($payload);
    }

    /**
     * POST /api/auth/refresh — exchanges a refresh token for a new pair.
     *
     * The presented refresh token is rotated (revoked + replaced), so it can
     * only be used once; the access JWT returned is a fresh short-lived token.
     *
     * @return never 200 with a new auth payload, 422 on missing token,
     *               401 on unknown/revoked/expired token.
     */
    public function refresh(Request $request): never
    {
        try {
            $payload = $this->auth->refresh((string) $request->input('refresh_token', ''));
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (InvalidCredentialsException $e) {
            $this->error($e->getMessage(), 401);
        }

        $this->json($payload);
    }

    /**
     * POST /api/auth/logout — revokes the client's refresh token.
     *
     * Idempotent: a missing/unknown token still yields 204, so repeat
     * logouts never fail. The access JWT stays valid until its own TTL
     * (short-lived by design).
     *
     * @return never 204 on success, 422 on missing token.
     */
    public function logout(Request $request): never
    {
        try {
            $this->auth->logout((string) $request->input('refresh_token', ''));
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        }

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
}