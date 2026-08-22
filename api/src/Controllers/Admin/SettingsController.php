<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Repositories\SettingsRepository;

/**
 * SettingsController — admin settings management.
 *
 * Provides a simple key-value settings API. Settings stored in the `settings`
 * table affect live site behavior (shipping costs, payment methods, etc.).
 */
final class SettingsController extends Controller
{
    private SettingsRepository $settings;

    public function __construct()
    {
        $this->settings = new SettingsRepository();
    }

    /**
     * GET /api/admin/settings — get all settings.
     */
    public function index(Request $request): never
    {
        $settings = $this->settings->getAll();
        $this->json(['data' => $settings]);
    }

    /**
     * PUT /api/admin/settings — update multiple settings.
     *
     * Body: {"store_name": "Luce Bianca", "store_email": "...", ...}
     */
    public function update(Request $request): never
    {
        $body = $request->all();

        // Filter out non-string values
        $filtered = [];
        foreach ($body as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $filtered[$key] = (string) $value;
            }
        }

        if (empty($filtered)) {
            $this->error('No valid settings provided.', 422);
        }

        $this->settings->setMultiple($filtered);

        $this->json(['message' => 'Settings updated successfully.']);
    }
}
