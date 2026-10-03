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

        \App\Core\Response::refreshCookie('lb_admin_refresh', (string) ($payload['refresh_token'] ?? ''), (int) \App\Core\Env::get('JWT_REFRESH_TTL_SECONDS', 604800));
        $this->json($payload);
    }

    /**
     * GET /api/admin/auth/me — verifies the admin JWT is still valid.
     * Used by the admin shell's real route guard (beyond cookie presence):
     * 200 = session valid, 401 = expired/revoked (client clears + redirects).
     *
     * @return never 200 with the admin profile, 401 otherwise (via middleware).
     */
    public function me(Request $request): never
    {
        $claims = \App\Core\RequestContext::get('auth', []);
        $adminId = (int) ($claims['sub'] ?? 0);
        $repo = new \App\Repositories\AdminRepository();
        $admin = $repo->findById($adminId);
        if ($admin === null) {
            $this->error('Account not found.', 404);
        }
        $this->json(['data' => $admin->toArray()]);
    }
}