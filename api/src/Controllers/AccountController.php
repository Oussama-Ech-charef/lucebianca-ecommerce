<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\RequestContext;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;

/**
 * AccountController — the logged-in customer's own profile + order history.
 *
 * Every route here is protected by AuthMiddleware('user'), so the request
 * always carries a verified customer JWT whose `sub` is the user id. All data
 * is scoped to that id: a customer can only ever read their own profile and
 * their own orders. Order payloads reuse the light admin pattern (summary
 * fields + item_count); full line items come from the existing public
 * GET /api/orders/{id}.
 */
final class AccountController extends Controller
{
    private UserRepository $users;
    private OrderRepository $orders;

    public function __construct()
    {
        $this->users  = new UserRepository();
        $this->orders = new OrderRepository();
    }

    /**
     * GET /api/account — the authenticated customer's profile.
     *
     * @return never 200 with {data: user} (never includes the password hash).
     */
    public function me(Request $request): never
    {
        $userId = $this->authenticatedUserId();
        $user   = $this->users->findById($userId);

        if ($user === null) {
            // The token is valid but the row is gone (account deleted).
            $this->error('Account not found.', 404);
        }

        $this->json(['data' => $user->toArray()]);
    }

    /**
     * GET /api/account/orders — the customer's own orders, paginated.
     *
     * Query params: page, per_page. Same {data, meta} pagination shape as the
     * admin listing, filtered to orders.user_id = the authenticated customer.
     *
     * @return never 200 with {data, meta}.
     */
    public function orders(Request $request): never
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 12)));

        $userId = $this->authenticatedUserId();
        $result = $this->orders->paginateForUser($userId, $page, $perPage);

        $this->json([
            'data' => $result['items'],
            'meta' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $result['total'],
                'pages'    => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * The authenticated user's id from the verified JWT claims.
     *
     * @return int Guaranteed present on protected routes (AuthMiddleware has
     *             already verified the token and its role).
     */
    private function authenticatedUserId(): int
    {
        $claims = RequestContext::get('auth', []);

        return (int) ($claims['sub'] ?? 0);
    }
}