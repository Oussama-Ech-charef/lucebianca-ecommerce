<?php

namespace App\Controllers;

use App\Core\Request;
use App\Models\Product;
use App\Repositories\ProductRepository;

/**
 * ProductController — public storefront product endpoints.
 *
 * Only returns products with is_active = 1 (soft-delete / "paused"
 * products never leak to the storefront).
 */
final class ProductController extends Controller
{
    private ProductRepository $products;

    public function __construct()
    {
        $this->products = new ProductRepository();
    }

    /**
     * GET /api/products — paginated product listing with filtering.
     *
     * Query params: page, per_page, category, size, color, min_price, max_price.
     */
    public function index(Request $request): never
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 12)));

        $filters = [
            'category'  => (string) $request->query('category', ''),
            'size'      => (string) $request->query('size', ''),
            'color'     => (string) $request->query('color', ''),
            'min_price' => $request->query('min_price'),
            'max_price' => $request->query('max_price'),
        ];

        $result = $this->products->paginate($page, $perPage, $filters);

        $this->json([
            'data'  => array_map(
                static fn (Product $product): array => $product->toArray(),
                $result['items']
            ),
            'meta'  => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $result['total'],
                'pages'    => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * GET /api/products/{slug} — single active product or 404.
     */
    public function show(Request $request, array $params): never
    {
        $product = $this->products->findActiveBySlug((string) ($params['slug'] ?? ''));

        if ($product === null) {
            $this->error('Product not found.', 404);
        }

        $this->json(['data' => $product->toArray()]);
    }
}