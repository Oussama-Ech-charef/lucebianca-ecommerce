<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Repositories\ProductSizeRepository;
use PDOException;

/**
 * SizesController — admin CRUD for product_sizes catalog.
 */
final class SizesController extends Controller
{
    private ProductSizeRepository $sizes;

    public function __construct()
    {
        $this->sizes = new ProductSizeRepository();
    }

    public function index(Request $request): never
    {
        $this->json(['data' => $this->sizes->all()]);
    }

    public function store(Request $request): never
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $this->error('Validation failed.', 422, ['errors' => ['name' => 'Name is required.']]);
        }
        if (mb_strlen($name) > 50) {
            $this->error('Validation failed.', 422, ['errors' => ['name' => 'Name must be at most 50 characters.']]);
        }
        if (mb_strlen($name) < 1) {
            $this->error('Validation failed.', 422, ['errors' => ['name' => 'Name is required.']]);
        }

        if ($this->sizes->findByName($name) !== null) {
            $this->error('Validation failed.', 422, ['errors' => ['name' => 'Size already exists.']]);
        }

        try {
            $id = $this->sizes->create($name);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->error('Validation failed.', 422, ['errors' => ['name' => 'Size already exists.']]);
            }
            throw $e;
        }

        $row = $this->sizes->findById($id);
        $this->json(['data' => $row], 201);
    }

    public function destroy(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $row = $this->sizes->findById($id);
        if ($row === null) {
            $this->error('Size not found.', 404);
        }

        if ($this->sizes->isUsed($row['name'])) {
            $this->error('Cannot delete size that is currently used by product variants.', 409);
        }

        $this->sizes->delete($id);
        $this->noContent();
    }
}
