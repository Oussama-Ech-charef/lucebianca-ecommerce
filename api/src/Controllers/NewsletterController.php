<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\NewsletterRepository;

/**
 * NewsletterController — public newsletter signup.
 */
final class NewsletterController extends Controller
{
    private NewsletterRepository $newsletter;

    public function __construct()
    {
        $this->newsletter = new NewsletterRepository();
    }

    /**
     * POST /api/newsletter/subscribe — subscribe to newsletter.
     *
     * Body: {"email": "user@example.com"}
     */
    public function subscribe(Request $request): never
    {
        $email = trim((string) $request->input('email', ''));

        $errors = Validator::validate(
            ['email' => $email],
            ['email' => ['required', 'email']]
        );

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $subscribed = $this->newsletter->subscribe($email);

        $this->json([
            'data' => [
                'subscribed' => $subscribed,
                'message' => $subscribed
                    ? 'Successfully subscribed to newsletter!'
                    : 'Already subscribed.',
            ],
        ], $subscribed ? 201 : 200);
    }

    /**
     * POST /api/newsletter/unsubscribe — unsubscribe from newsletter.
     *
     * Body: {"email": "user@example.com"}
     */
    public function unsubscribe(Request $request): never
    {
        $email = trim((string) $request->input('email', ''));

        $errors = Validator::validate(
            ['email' => $email],
            ['email' => ['required', 'email']]
        );

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $unsubscribed = $this->newsletter->unsubscribe($email);

        $this->json([
            'data' => [
                'unsubscribed' => $unsubscribed,
                'message' => $unsubscribed
                    ? 'Successfully unsubscribed from newsletter.'
                    : 'Email not found in newsletter list.',
            ],
        ]);
    }
}
