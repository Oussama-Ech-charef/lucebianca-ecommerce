<?php

namespace App\Services;

use App\Models\Product;
use App\Repositories\ProductRepository;

/**
 * ProductService — business logic for products.
 *
 * Repositories own SQL; Services own business rules (price calculation,
 * stock handling, SEO payload assembly). Controllers delegate here.
 */
final class ProductService
{
    private ProductRepository $products;

    public function __construct()
    {
        $this->products = new ProductRepository();
    }

    /**
     * Public storefront listing with active-only filter.
     *
     * @return array{items: array<int, Product>, total: int}
     */
    public function listActive(int $page, int $perPage, array $filters): array
    {
        return $this->products->paginate($page, $perPage, $filters);
    }

    /**
     * Legacy no-op placeholder intent: future complex operations (bundles,
     * price rules) will be layered here without touching repositories.
     */
    public function getActiveBySlug(string $slug): ?Product
    {
        return $this->products->findActiveBySlug($slug);
    }
}