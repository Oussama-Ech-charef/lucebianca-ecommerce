<?php

namespace App\Controllers;

use App\Core\CsrfProtection;
use App\Core\Request;

/**
 * CsrfController — provides CSRF tokens to clients.
 *
 * GET /api/csrf-token returns a fresh token that the client includes in
 * subsequent state-changing requests (POST/PUT/DELETE) via the X-CSRF-Token
 * header or _csrf_token body field.
 */
final class CsrfController extends Controller
{
    /**
     * GET /api/csrf-token — generate a CSRF token for the client.
     *
     * @return never 200 with {csrf_token: "..."}
     */
    public function show(Request $request): never
    {
        $token = CsrfProtection::generateToken();

        $this->json([
            'csrf_token' => $token,
            'expires_in' => 3600, // 1 hour
        ]);
    }
}
