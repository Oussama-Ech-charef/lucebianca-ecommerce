<?php

namespace App\Controllers;

use App\Core\Request;

/**
 * ContactController — /contact form submissions.
 *
 * Security phase adds honeypot + fully sanitized + rate limited (prevent spam).
 */
final class ContactController extends Controller
{
    /**
     * POST /api/contact — store a contact message.
     */
    public function store(Request $request): never
    {
        $this->notImplemented('Contact form');
    }
}