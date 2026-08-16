<?php

namespace App\Controllers;

use App\Core\Env;
use App\Core\Request;

/**
 * HealthController — smoke test proving router + config work.
 */
final class HealthController extends Controller
{
    /**
     * GET /api/health — returns service status and the active environment.
     */
    public function show(Request $request): never
    {
        $this->json([
            'status'  => 'ok',
            'service' => 'lucebianca-api',
            'env'     => Env::get('APP_ENV', 'local'),
            'time'    => date('c'),
        ]);
    }
}