<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\ConflictException;
use App\Core\Env;
use App\Core\InvalidCredentialsException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\User;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;

/**
 * AuthService — authentication business logic.
 *
 * Implements registration (password_hash, duplicate-email guard) and
 * login (password_verify + JWT + refresh token issuance). Google OAuth
 * is deliberately left as a stub — separate future task.
 *
 * Password rule: MIN_PASSWORD_LENGTH = 8. A reasonable floor for this
 * store's audience; not enforced anywhere client-side alone — the rule
 * is enforced server-side here and documented in the phase doc.
 */
final class AuthService
{
    private const MIN_PASSWORD_LENGTH = 8;

    private UserRepository $users;
    private RefreshTokenRepository $refreshTokens;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->refreshTokens = new RefreshTokenRepository();
    }

    /**
     * Registers a new customer account and returns signed tokens.
     *
     * Validates every field server-side (spec 3.1), rejects a duplicate
     * email, hashes the password with password_hash(PASSWORD_DEFAULT),
     * then issues an access + refresh token pair.
     *
     * @throws ValidationException   When any input field fails validation.
     * @throws ConflictException     When the email is already registered.
     *
     * @return array{token: string, refresh_token: string, user: array} Auth payload
     *         to return verbatim to the client.
     */
    public function register(string $name, string $email, string $password, ?string $phone): array
    {
        $errors = Validator::validate(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name'     => ['required'],
                'email'    => ['required', 'email'],
                'password' => ['required', ['min', self::MIN_PASSWORD_LENGTH]],
            ]
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $email = strtolower(trim($email));
        $name  = trim($name);
        $phone = $phone === null ? null : trim($phone);

        if ($this->users->findByEmail($email) !== null) {
            // Simple message is fine for this store (not a high-security app).
            throw new ConflictException('Email already registered.');
        }

        $userId = $this->users->create(
            $name,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $phone
        );

        return $this->issueTokenPair($userId);
    }

    /**
     * Authenticates a customer and returns signed tokens.
     *
     * Looks up by email; the same generic exception is thrown whether the
     * email is unknown or the password is wrong, so the response never
     * reveals which one failed.
     *
     * @throws ValidationException         When required inputs are missing/invalid.
     * @throws InvalidCredentialsException When the credentials do not match.
     *
     * @return array{token: string, refresh_token: string, user: array} Auth payload.
     */
    public function login(string $email, string $password): array
    {
        $errors = Validator::validate(
            ['email' => $email, 'password' => $password],
            [
                'email'    => ['required', 'email'],
                'password' => ['required'],
            ]
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user = $this->users->findByEmail(strtolower(trim($email)));

        if ($user === null || !password_verify($password, $user->passwordHash)) {
            throw new InvalidCredentialsException('Invalid credentials.');
        }

        return $this->issueTokenPair($user->id);
    }

    /**
     * Exchange a Google OAuth code for tokens/account.
     *
     * NOT IMPLEMENTED — separate future task. Route stays a 501 stub.
     *
     * @throws \RuntimeException When the code exchange fails.
     *
     * @return array{token: string, refresh_token: string, user: array} Auth payload.
     */
    public function loginWithGoogle(string $code): array
    {
        throw new \RuntimeException('Google Sign-In not implemented yet (phase 3).');
    }

    /**
     * Exchanges a still-valid refresh token for a fresh token pair.
     *
     * Rotation: the presented token is revoked here and only the newly
     * issued pair can be used from now on, so a stolen token works at
     * most once. The raw token is never compared — only its hash.
     *
     * @throws ValidationException         When no refresh token is supplied.
     * @throws InvalidCredentialsException When the token is unknown, revoked
     *                                     or expired.
     *
     * @return array{token: string, refresh_token: string, user: array} New
     *         auth payload (same shape as login/register).
     */
    public function refresh(string $refreshToken): array
    {
        $errors = Validator::validate(
            ['refresh_token' => $refreshToken],
            ['refresh_token' => ['required']]
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $hash = $this->hashToken($refreshToken);
        $row  = $this->refreshTokens->findActive($hash);

        if ($row === null) {
            throw new InvalidCredentialsException('Invalid or expired refresh token.');
        }

        // Rotate: the presented token is single-use — revoke it and issue
        // a fresh access + refresh pair.
        $this->refreshTokens->revoke($hash);

        try {
            return $this->issueTokenPair((int) $row['user_id']);
        } catch (\Throwable $e) {
            // If the new pair cannot be issued, restore the old token so the
            // client can retry instead of being locked out.
            $this->refreshTokens->create((int) $row['user_id'], $hash, $row['expires_at']);
            throw $e;
        }
    }

    /**
     * Logs a client out by revoking its refresh token.
     *
     * Idempotent: revoking an unknown/already-revoked token is a no-op, so
     * the client always gets a success response and repeat logouts are safe.
     *
     * @throws ValidationException When no refresh token is supplied.
     */
    public function logout(string $refreshToken): void
    {
        $errors = Validator::validate(
            ['refresh_token' => $refreshToken],
            ['refresh_token' => ['required']]
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->refreshTokens->revoke($this->hashToken($refreshToken));
    }

    /**
     * Returns issuer time-to-live for logging/debugging.
     */
    public function tokenTtl(): int
    {
        return (int) Env::get('JWT_TTL_SECONDS', 3600);
    }

    /**
     * Issues an access token + a revocable refresh token, persists the
     * refresh token (hashed) and returns the client-facing auth payload.
     *
     * @param int $userId The authenticated/registered user's id.
     *
     * @return array{token: string, refresh_token: string, user: array}
     */
    private function issueTokenPair(int $userId): array
    {
        $accessTtl  = (int) Env::get('JWT_TTL_SECONDS', 3600);
        $refreshTtl = (int) Env::get('JWT_REFRESH_TTL_SECONDS', 604800);

        // Access token: short-lived JWT (stateless).
        $accessToken = Auth::issue($userId, 'user', $accessTtl);

        // Refresh token: 48 random bytes base64url-encoded (well above the
        // required 32). Only its SHA-256 hash is persisted — never the raw value.
        $refreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $expiresAt    = date('Y-m-d H:i:s', time() + $refreshTtl);

        $this->refreshTokens->create($userId, $this->hashToken($refreshToken), $expiresAt);

        /** @var User $user The user was just created/verified, so a row must exist. */
        $user = $this->users->findById($userId);

        return [
            'token'         => $accessToken,
            'refresh_token' => $refreshToken,
            'user'          => $user->toArray(),
        ];
    }

    /**
     * Deterministic SHA-256 hex hash of a refresh token — the only form the
     * raw token is ever persisted in.
     */
    private function hashToken(string $refreshToken): string
    {
        return hash('sha256', $refreshToken);
    }
}