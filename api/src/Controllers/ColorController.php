<?php

namespace App\Controllers;

use App\Core\Request;
use App\Repositories\ProductColorRepository;

/**
 * ColorController — public read for product form selects.
 */
final class ColorController extends Controller
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
}
