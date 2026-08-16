<?php

namespace App\Controllers;

use App\Core\Request;

/**
 * CartController — cart management.
 *
 * The cart endpoint persists a cart payload (products, sizes, colors).
 * Phase 7 (Cart + checkout) implements the real logic.
 */
final class CartController extends Controller
{
    /**
     * POST /api/cart — validate + persist the cart contents.
     */
    public function store(Request $request): never
    {
        $this->notImplemented('Cart');
    }
}