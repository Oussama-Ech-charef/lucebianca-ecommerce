<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Env;
use App\Core\InvalidCredentialsException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Admin;
use App\Repositories\AdminRefreshTokenRepository;
use App\Repositories\AdminRepository;

/**
 * AdminAuthService — admin panel authentication logic.
 *
 * Deliberately separate from AuthService (customers): admins are a fully
 * separate table (spec section 4 security comment). Password_verify on the
 * admins table, JWT with role 'admin', refresh tokens stored in their own
 * admin_refresh_tokens table (see migration 003 for the FK decision).
 */
final class AdminAuthService
{
    private const MIN_PASSWORD_LENGTH = 8;

    private AdminRepository $admins;
    private AdminRefreshTokenRepository $refreshTokens;

    public function __construct()
    {
        $this->admins         = new AdminRepository();
        $this->refreshTokens  = new AdminRefreshTokenRepository();
    }

    /**
     * Authenticates an admin and returns signed tokens.
     *
     * The same generic exception is thrown whether the email is unknown or
     * the password is wrong, so the response never reveals which one failed.
     *
     * There is intentionally NO public "register as admin" path — the only
     * way to create an admin is the offline CLI script (create-admin.php).
     *
     * @throws ValidationException         When required inputs are missing/invalid.
     * @throws InvalidCredentialsException When the credentials do not match.
     *
     * @return array{token: string, refresh_token: string, admin: array} Auth payload.
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

        $admin = $this->admins->findByEmail(strtolower(trim($email)));

        if ($admin === null || !password_verify($password, $admin->passwordHash)) {
            throw new InvalidCredentialsException('Invalid credentials.');
        }

        return $this->issueTokenPair($admin);
    }

    /**
     * Returns issuer time-to-live for logging/debugging.
     */
    public function tokenTtl(): int
    {
        return (int) Env::get('JWT_TTL_SECONDS', 3600);
    }

    /**
     * Issues an access token (role "admin") + a refresh token (stored
     * hashed in admin_refresh_tokens) and returns the auth payload.
     *
     * @return array{token: string, refresh_token: string, admin: array}
     */
    private function issueTokenPair(Admin $admin): array
    {
        $accessTtl  = (int) Env::get('JWT_TTL_SECONDS', 3600);
        $refreshTtl = (int) Env::get('JWT_REFRESH_TTL_SECONDS', 604800);

        $accessToken = Auth::issue($admin->id, 'admin', $accessTtl);

        $refreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $expiresAt    = date('Y-m-d H:i:s', time() + $refreshTtl);

        $this->refreshTokens->create($admin->id, hash('sha256', $refreshToken), $expiresAt);

        return [
            'token'         => $accessToken,
            'refresh_token' => $refreshToken,
            'admin'         => $admin->toArray(),
        ];
    }
}