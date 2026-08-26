<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Repositories\ProductColorRepository;
use PDOException;

/**
 * ColorsController — admin CRUD for product_colors catalog.
 */
final class ColorsController extends Controller
{
    private ProductColorRepository $colors;

    public function __construct()
    {
        $this->colors = new ProductColorRepository();
    }

    public function index(Request $request): never
    {
        $this->json(['data' => $this->colors->all()]);
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

        if ($this->colors->findByName($name) !== null) {
            $this->error('Validation failed.', 422, ['errors' => ['name' => 'Color already exists.']]);
        }

        try {
            $id = $this->colors->create($name);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->error('Validation failed.', 422, ['errors' => ['name' => 'Color already exists.']]);
            }
            throw $e;
        }

        $row = $this->colors->findById($id);
        $this->json(['data' => $row], 201);
    }

    public function destroy(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $row = $this->colors->findById($id);
        if ($row === null) {
            $this->error('Color not found.', 404);
        }

        if ($this->colors->isUsed($row['name'])) {
            $this->error('Cannot delete color that is currently used by product variants.', 409);
        }

        $this->colors->delete($id);
        $this->noContent();
    }
}
