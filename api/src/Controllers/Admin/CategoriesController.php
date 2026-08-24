<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\ConflictException;
use App\Core\Request;
use App\Core\Slugger;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\CategoryRepository;

/**
 * CategoriesController — admin category management (CRUD).
 */
final class CategoriesController extends Controller
{
    private CategoryRepository $categories;

    public function __construct()
    {
        $this->categories = new CategoryRepository();
    }

    /**
     * GET /api/admin/categories — all categories.
     */
    public function index(Request $request): never
    {
        $categories = $this->categories->all();

        $this->json([
            'data' => array_map(
                static fn ($category): array => $category->toArray(),
                $categories
            ),
        ]);
    }

    /**
     * POST /api/admin/categories — create a category.
     */
    public function store(Request $request): never
    {
        $name = trim((string) $request->input('name', ''));

        $errors = Validator::validate(
            ['name' => $name],
            ['name' => ['required']]
        );

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $givenSlug = trim((string) ($request->input('slug') ?? ''));
        $baseSlug = Slugger::make($givenSlug !== '' ? $givenSlug : $name);
        $slug = $baseSlug;
        $n = 2;

        try {
            while ($this->categories->slugExists($slug)) {
                if ($givenSlug !== '') {
                    throw new ConflictException('Slug already exists.');
                }
                $slug = "{$baseSlug}-{$n}";
                $n++;
            }

            $id = $this->categories->create($name, $slug);
        } catch (ConflictException $e) {
            $this->error($e->getMessage(), 409);
        }
        $category = $this->categories->findById($id);

        $this->json(['data' => $category->toArray()], 201);
    }

    /**
     * PUT /api/admin/categories/{id} — update a category.
     */
    public function update(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $category = $this->categories->findById($id);

        if ($category === null) {
            $this->error('Category not found.', 404);
        }

        $errors = [];
        $fields = [];
        $body = $request->all();

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                $errors['name'] = 'Name is required.';
            } else {
                $fields['name'] = $name;
            }
        }

        if (isset($body['slug'])) {
            $slug = Slugger::make((string) $body['slug'], Slugger::make($category->name));
            if ($slug === '') {
                $errors['slug'] = 'Slug is invalid.';
            } elseif ($this->categories->slugExists($slug, $id)) {
                $this->error('Slug already exists.', 409);
            } else {
                $fields['slug'] = $slug;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->categories->update($id, $fields);
        $updated = $this->categories->findById($id);

        $this->json(['data' => $updated->toArray()]);
    }

    /**
     * DELETE /api/admin/categories/{id} — delete a category.
     *
     * Products in this category will have category_id set to NULL
     * (ON DELETE SET NULL in schema).
     */
    public function destroy(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $category = $this->categories->findById($id);

        if ($category === null) {
            $this->error('Category not found.', 404);
        }

        $this->categories->delete($id);
        $this->noContent();
    }
}
