<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Validator;
use App\Repositories\ProductRepository;
use App\Repositories\ReviewRepository;

/**
 * ReviewController — public review submission.
 */
final class ReviewController extends Controller
{
    private ReviewRepository $reviews;
    private ProductRepository $products;

    public function __construct()
    {
        $this->reviews = new ReviewRepository();
        $this->products = new ProductRepository();
    }

    /**
     * POST /api/products/{slug}/reviews — submit a review (requires auth).
     */
    public function store(Request $request, array $params): never
    {
        $slug = $params['slug'] ?? '';
        $product = $this->products->findActiveBySlug($slug);

        if ($product === null) {
            $this->error('Product not found.', 404);
        }

        // Require authentication
        $token = $request->bearerToken();
        if ($token === null) {
            $this->error('Authentication required to submit a review.', 401);
        }

        try {
            $claims = Auth::verify($token);
        } catch (\RuntimeException) {
            $this->error('Invalid or expired token.', 401);
        }

        if (($claims['role'] ?? '') !== 'user') {
            $this->error('Only customers can submit reviews.', 403);
        }

        $userId = (int) $claims['sub'];
        // Email-verification policy (enforced server-side): only verified
        // accounts may publish reviews. Google accounts are verified at
        // creation; password accounts must complete /verify-email first.
        $userRepo = new \App\Repositories\UserRepository();
        $author = $userRepo->findById($userId);
        if ($author === null) {
            $this->error('Account not found.', 404);
        }
        if (!$author->emailVerified) {
            $this->error('Please verify your email before submitting a review.', 403);
        }
        $rating = $request->input('rating');
        $comment = trim((string) $request->input('comment', ''));

        $errors = Validator::validate(
            ['rating' => $rating],
            ['rating' => ['required']]
        );

        if (!is_numeric($rating) || (int) $rating < 1 || (int) $rating > 5) {
            $errors['rating'] = 'Rating must be between 1 and 5.';
        }

        if ($errors !== []) {
            $this->error('Validation failed.', 422, ['errors' => $errors]);
        }

        $reviewId = $this->reviews->create(
            $product->id,
            $userId,
            (int) $rating,
            $comment !== '' ? $comment : null
        );

        $this->json([
            'data' => [
                'id' => $reviewId,
                'message' => 'Review submitted successfully. It will appear after admin approval.',
            ],
        ], 201);
    }
}
