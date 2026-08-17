<?php

namespace App\Controllers;

use App\Core\Request;
use App\Models\Product;
use App\Models\Variant;
use App\Repositories\ProductImageRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;

/**
 * ProductController — public storefront product endpoints.
 *
 * Only returns products with is_active = 1 (soft-delete / "paused"
 * products never leak to the storefront).
 *
 * Storefront payloads include the product's variants (size/color selection,
 * per-variant price and stock) and images (gallery) so the frontend never
 * has to query anything else.
 */
final class ProductController extends Controller
{
    private ProductRepository $products;
    private ProductVariantRepository $variants;
    private ProductImageRepository $images;

    public function __construct()
    {
        $this->products = new ProductRepository();
        $this->variants = new ProductVariantRepository();
        $this->images   = new ProductImageRepository();
    }

    /**
     * GET /api/products — paginated product listing with filtering.
     *
     * Query params: page, per_page, category, size, color, min_price, max_price.
     * Each item carries its images (empty array when none) for the shop cards.
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

        // One query for every image on the page, grouped by product id.
        $imagesByProduct = [];
        foreach ($this->images->allForProducts(
            array_map(static fn (Product $p): int => $p->id, $result['items'])
        ) as $row) {
            $imagesByProduct[$row['product_id']][] = $row;
        }

        $items = array_map(
            static fn (Product $product): array => [
                ...$product->toArray(),
                'images' => $imagesByProduct[$product->id] ?? [],
            ],
            $result['items']
        );

        $this->json([
            'data' => $items,
            'meta' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $result['total'],
                'pages'    => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * GET /api/products/{slug} — single active product (with variants and
     * images) or 404.
     */
    public function show(Request $request, array $params): never
    {
        $product = $this->products->findActiveBySlug((string) ($params['slug'] ?? ''));

        if ($product === null) {
            $this->error('Product not found.', 404);
        }

        $this->json([
            'data' => [
                ...$product->toArray(),
                'variants' => array_map(
                    static fn (Variant $variant): array => $variant->toArray(),
                    $this->variants->listByProduct($product->id)
                ),
                'images'   => $this->images->listByProduct($product->id),
            ],
        ]);
    }
}