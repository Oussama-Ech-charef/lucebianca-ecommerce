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
 * register and login are implemented (password_hash, JWT + refresh
 * token issuance via App\Services\AuthService). Server-side validation
 * errors become 422 with per-field details; a duplicate email is 409;
 * bad credentials are 401. google() remains a 501 stub — separate task.
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
     * POST /api/auth/google — register/login via Google OAuth 2.0.
     *
     * NOT implemented yet — separate future task.
     */
    public function google(Request $request): never
    {
        $this->notImplemented('Google Sign-In');
    }
}