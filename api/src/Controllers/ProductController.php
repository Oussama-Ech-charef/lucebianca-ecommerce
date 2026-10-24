<?php

namespace App\Controllers;

use App\Core\Cache;
use App\Core\Request;
use App\Models\Product;
use App\Models\Variant;
use App\Repositories\ProductImageRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;
use App\Repositories\ReviewRepository;

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
    private ReviewRepository $reviews;
    private Cache $cache;

    public function __construct()
    {
        $this->products = new ProductRepository();
        $this->variants = new ProductVariantRepository();
        $this->images   = new ProductImageRepository();
        $this->reviews  = new ReviewRepository();
        $this->cache    = new Cache(null, 300); // 5 minutes cache
    }

    /**
     * GET /api/products — paginated product listing with filtering.
     *
     * Query params: page, per_page, search, category, size, color, min_price, max_price.
     * Each item carries its images (empty array when none) for the shop cards.
     */
    public function index(Request $request): never
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 12)));

        $search = trim((string) $request->query('search', ''));
        if (mb_strlen($search) > 100) {
            $search = mb_substr($search, 0, 100);
        }

        $filters = [
            'search'    => $search,
            'category'  => (string) $request->query('category', ''),
            'size'      => (string) $request->query('size', ''),
            'color'     => (string) $request->query('color', ''),
            'min_price' => $request->query('min_price'),
            'max_price' => $request->query('max_price'),
        ];

        // Cache key includes all query params to avoid cache collision
        $cacheKey = 'products:list:' . md5(json_encode(['page' => $page, 'per_page' => $perPage, 'filters' => $filters]));

        $result = $this->cache->remember($cacheKey, function () use ($page, $perPage, $filters) {
            return $this->products->paginate($page, $perPage, $filters, true);
        }, 300);

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
                'reviews'  => $this->reviews->getApprovedByProduct($product->id),
                'average_rating' => $this->reviews->getAverageRating($product->id),
            ],
        ]);
    }

    /**
     * GET /api/products/new-arrivals — newest active products (for homepage).
     *
     * Returns the same product shape as GET /api/products (including images),
     * so ProductCard and other storefront components can be reused without
     * branching on the source endpoint.
     */
    public function newArrivals(Request $request): never
    {
        $limit = min(20, max(1, (int) $request->query('limit', 8)));
        $products = $this->products->getNewestActive($limit);

        $this->json(['data' => $this->withImages($products)]);
    }

    /**
     * GET /api/products/special-offers — active products with valid sale price.
     *
     * A valid sale price is > 0 and strictly less than base_price. Returns the
     * same product shape as GET /api/products (including images).
     */
    public function specialOffers(Request $request): never
    {
        $limit = min(20, max(1, (int) $request->query('limit', 8)));
        $products = $this->products->getDiscountedActive($limit);

        $this->json(['data' => $this->withImages($products)]);
    }

    /**
     * Attaches images to a list of products using the same mapping as index().
     *
     * @param array<int, Product> $products
     * @return array<int, array<string, mixed>>
     */
    private function withImages(array $products): array
    {
        if ($products === []) {
            return [];
        }

        $imagesByProduct = [];
        foreach ($this->images->allForProducts(
            array_map(static fn (Product $p): int => $p->id, $products)
        ) as $row) {
            $imagesByProduct[$row['product_id']][] = $row;
        }

        return array_map(
            static fn (Product $product): array => [
                ...$product->toArray(),
                'images' => $imagesByProduct[$product->id] ?? [],
            ],
            $products
        );
    }
}