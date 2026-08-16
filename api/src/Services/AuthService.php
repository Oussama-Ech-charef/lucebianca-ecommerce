<?php

namespace App\Services;

use App\Core\Auth;
use App\Repositories\UserRepository;
use App\Core\Env;

/**
 * AuthService — authentication business logic.
 *
 * Phase 3 implements: registration (password_hash, duplicate-email guard),
 * login (password_verify + JWT + refresh token issuance), and Google OAuth
 * (code exchange, account matching by email).
 */
final class AuthService
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    /**
     * Registers a new customer account.
     *
     * @throws \RuntimeException When the email is already registered.
     *
     * @return array{token: string, refresh_token: string, user: array} Auth payload.
     */
    public function register(string $name, string $email, string $password, ?string $phone): array
    {
        // Placeholder for phase 3 — the repository + Auth::issue are ready.
        throw new \RuntimeException('Registration not implemented yet (phase 3).');
    }

    /**
     * Validates credentials and issues tokens.
     *
     * @throws \RuntimeException On invalid credentials.
     *
     * @return array{token: string, refresh_token: string, user: array} Auth payload.
     */
    public function login(string $email, string $password): array
    {
        // Placeholder for phase 3.
        throw new \RuntimeException('Login not implemented yet (phase 3).');
    }

    /**
     * Exchange a Google OAuth code for tokens/account.
     *
     * @throws \RuntimeException When the code exchange fails.
     *
     * @return array{token: string, refresh_token: string, user: array} Auth payload.
     */
    public function loginWithGoogle(string $code): array
    {
        // Placeholder for phase 3.
        throw new \RuntimeException('Google Sign-In not implemented yet (phase 3).');
    }

    /**
     * Returns issuer time-to-live for logging/debugging (kept for phase 3).
     */
    public function tokenTtl(): int
    {
        return (int) Env::get('JWT_TTL_SECONDS', 3600);
    }
}