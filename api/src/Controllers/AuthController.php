<?php

namespace App\Controllers;

use App\Core\Request;

/**
 * AuthController — public registration / login / Google OAuth.
 *
 * Phase 3 fills these in (password_hash validation, JWT issuance via
 * App\Core\Auth, Google OAuth 2.0 token exchange).
 */
final class AuthController extends Controller
{
    /**
     * POST /api/auth/register — creates a customer account.
     */
    public function register(Request $request): never
    {
        $this->notImplemented('Registration');
    }

    /**
     * POST /api/auth/login — email/password login, returns JWT + refresh token.
     */
    public function login(Request $request): never
    {
        $this->notImplemented('Login');
    }

    /**
     * POST /api/auth/google — register/login via Google OAuth 2.0.
     */
    public function google(Request $request): never
    {
        $this->notImplemented('Google Sign-In');
    }
}