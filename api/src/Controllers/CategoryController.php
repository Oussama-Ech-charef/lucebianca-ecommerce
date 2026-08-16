<?php

namespace App\Controllers;

use App\Core\Request;
use App\Models\Category;
use App\Repositories\CategoryRepository;

/**
 * CategoryController — public category listing.
 */
final class CategoryController extends Controller
{
    private CategoryRepository $categories;

    public function __construct()
    {
        $this->categories = new CategoryRepository();
    }

    /**
     * GET /api/categories — all categories ordered by name.
     */
    public function index(Request $request): never
    {
        $this->json([
            'data' => array_map(
                static fn (Category $category): array => $category->toArray(),
                $this->categories->all()
            ),
        ]);
    }
}