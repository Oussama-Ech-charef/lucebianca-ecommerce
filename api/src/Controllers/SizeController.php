<?php

namespace App\Controllers;

use App\Core\Request;
use App\Repositories\ProductSizeRepository;

/**
 * SizeController — public read for product form selects.
 */
final class SizeController extends Controller
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
}
