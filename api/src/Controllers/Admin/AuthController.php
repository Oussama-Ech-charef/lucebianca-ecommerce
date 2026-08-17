<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\InvalidCredentialsException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\AdminAuthService;

/**
 * AuthController (admin) — public admin login.
 *
 * This is the ONE public admin endpoint. There is no public admin
 * registration: accounts are created offline via api/scripts/create-admin.php.
 */
final class AuthController extends Controller
{
    private AdminAuthService $auth;

    public function __construct()
    {
        $this->auth = new AdminAuthService();
    }

    /**
     * POST /api/admin/auth/login — admin email/password login.
     *
     * @return never 200 with the admin auth payload, 422 on bad input,
     *               401 on invalid credentials.
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
}