<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Validator;
use App\Repositories\ContactMessageRepository;

/**
 * ContactController — /contact form submissions.
 *
 * Validates name/email/message server-side (required, email, min lengths),
 * then stores the message in contact_messages (is_read = 0) via
 * App\Repositories\ContactMessageRepository. Prepared statements protect
 * against injection. Rate limiting (RateLimitMiddleware) prevents spam floods.
 * Honeypot field (website) catches bots — real users never fill it.
 */
final class ContactController extends Controller
{
    private const MESSAGE_MIN_LENGTH = 10;

    private ContactMessageRepository $messages;

    public function __construct()
    {
        $this->messages = new ContactMessageRepository();
    }

    /**
     * POST /api/contact — store a contact message.
     *
     * Honeypot: rejects submissions where the 'website' field is non-empty
     * (bots fill hidden fields; humans never see it). Returns 422 like
     * validation failures to avoid revealing the honeypot.
     *
     * @return never 201 with {data: {id}}, or 422 on validation/bot detection.
     */
    public function store(Request $request): never
    {
        $name    = trim((string) $request->input('name', ''));
        $email   = trim((string) $request->input('email', ''));
        $message = trim((string) $request->input('message', ''));
        $website = trim((string) $request->input('website', ''));

        // Honeypot check — if the 'website' field is filled, it's a bot
        if ($website !== '') {
            // Return 422 like a validation error to avoid revealing the trap
            $this->error('Validation failed.', 422, ['errors' => [
                'message' => ['Message could not be submitted. Please try again.'],
            ]]);
        }

        $errors = Validator::validate(
            ['name' => $name, 'email' => $email, 'message' => $message],
            [
                'name'    => ['required', ['min', 2]],
                'email'   => ['required', 'email'],
                'message' => ['required', ['min', self::MESSAGE_MIN_LENGTH]],
            ]
        );

        if ($errors !== []) {
            $this->error('Validation failed.', 422, ['errors' => $errors]);
        }

        $id = $this->messages->create($name, $email, $message);

        $this->json(['data' => ['id' => $id]], 201);
    }
}