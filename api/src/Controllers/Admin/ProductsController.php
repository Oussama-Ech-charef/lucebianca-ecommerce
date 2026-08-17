<?php

namespace App\Controllers\Admin;

use App\Core\ConflictException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Slugger;
use App\Core\Validator;
use App\Controllers\Controller;
use App\Models\Product;
use App\Models\Variant;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductImageRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;
use App\Services\CloudinaryService;
use PDO;
use PDOException;
use RuntimeException;

/**
 * ProductsController — admin product management (protected by JWT admin role).
 *
 * Full CRUD + multi-image upload (Cloudinary).
 *
 * destroy() refuses hard-deletion when any variant of the product appears in
 * order_items (spec section 4.1: the schema's product_variants -> order_items
 * FK is ON DELETE RESTRICT, so deleting an ordered product would fail at the
 * database layer). Such products can only be deactivated via is_active.
 */
final class ProductsController extends Controller
{
    private ProductRepository $products;
    private ProductVariantRepository $variants;
    private ProductImageRepository $images;
    private CategoryRepository $categories;

    public function __construct()
    {
        $this->products   = new ProductRepository();
        $this->variants   = new ProductVariantRepository();
        $this->images     = new ProductImageRepository();
        $this->categories = new CategoryRepository();
        // CloudinaryService is created lazily inside uploadImages() so product
        // CRUD keeps working even if Cloudinary is down or misconfigured.
    }

    /**
     * GET /api/admin/products — all products (active AND paused) with pagination.
     *
     * Same pagination shape as the public endpoint, but is_active is not
     * forced on (activeOnly = false) so paused products show here too.
     */
    public function index(Request $request): never
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 12)));

        $result = $this->products->paginate($page, $perPage, [], false);

        $this->json([
            'data' => array_map(
                static fn (Product $product): array => $product->toArray(),
                $result['items']
            ),
            'meta' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $result['total'],
                'pages'    => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * POST /api/admin/products — create a product, optionally with variants.
     *
     * Product + variants are inserted in ONE transaction: if any variant
     * insert fails, the whole creation rolls back. Duplicate SKU / size+color
     * combos are rejected.
     */
    public function store(Request $request): never
    {
        $name      = trim((string) $request->input('name', ''));
        $basePrice = (string) $request->input('base_price', '');
        $metaTitle = trim((string) $request->input('meta_title', ''));
        $metaDesc  = trim((string) $request->input('meta_description', ''));

        $errors = Validator::validate(
            [
                'name'             => $name,
                'base_price'       => $basePrice,
                'meta_title'       => $metaTitle,
                'meta_description' => $metaDesc,
            ],
            [
                'name'             => ['required'],
                'base_price'       => ['required'],
                'meta_title'       => ['required'],
                'meta_description' => ['required'],
            ]
        );

        // base_price is DECIMAL(10,2); accept up to two decimals, no sign.
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $basePrice)) {
            $errors['base_price'] = 'Base price must be a valid amount (max 2 decimals).';
        }

        $categoryId = $request->input('category_id');
        if ($categoryId !== null && $categoryId !== '') {
            $categoryId = (int) $categoryId;
            if ($categoryId < 1) {
                $errors['category_id'] = 'Category must be a valid id.';
            } elseif (!$this->categories->exists($categoryId)) {
                $errors['category_id'] = 'Category does not exist.';
            }
        } else {
            $categoryId = null;
        }

        $isActiveInput = $request->input('is_active', 1);
        if (is_bool($isActiveInput)) {
            $isActive = $isActiveInput ? 1 : 0;
        } elseif (in_array((string) $isActiveInput, ['0', '1'], true)) {
            $isActive = (int) $isActiveInput;
        } else {
            $errors['is_active'] = 'is_active must be 0 or 1.';
            $isActive            = 1;
        }

        $description = $request->input('description');

        // --- Variants: each requires size/color/sku; price & stock optional. ---
        $variants         = [];
        $variantsInput    = $request->input('variants');
        $seenSkus         = [];
        $seenCombos       = [];
        if ($variantsInput !== null) {
            if (!is_array($variantsInput)) {
                $errors['variants'] = 'Variants must be an array.';
            } else {
                foreach ($variantsInput as $i => $raw) {
                    $v     = is_array($raw) ? $raw : [];
                    $size  = trim((string) ($v['size'] ?? ''));
                    $color = trim((string) ($v['color'] ?? ''));
                    $sku   = trim((string) ($v['sku'] ?? ''));

                    if ($size === '') {
                        $errors["variants.{$i}.size"] = 'Size is required.';
                    }
                    if ($color === '') {
                        $errors["variants.{$i}.color"] = 'Color is required.';
                    }
                    if ($sku === '') {
                        $errors["variants.{$i}.sku"] = 'SKU is required.';
                    } elseif (isset($seenSkus[$sku])) {
                        $errors["variants.{$i}.sku"] = 'Duplicate SKU within the same request.';
                    }
                    $seenSkus[$sku] = true;

                    $combo = "{$size}|{$color}";
                    if (isset($seenCombos[$combo])) {
                        $errors["variants.{$i}.color"] = 'Duplicate size/color combination within the same request.';
                    }
                    $seenCombos[$combo] = true;

                    $price = $v['price'] ?? null;
                    if ($price !== null && $price !== '' && !preg_match('/^\d{1,8}(\.\d{1,2})?$/', (string) $price)) {
                        $errors["variants.{$i}.price"] = 'Price must be a valid amount (max 2 decimals).';
                    }

                    $stock = 0;
                    if (isset($v['stock_quantity']) && $v['stock_quantity'] !== '') {
                        if (ctype_digit((string) $v['stock_quantity'])) {
                            $stock = (int) $v['stock_quantity'];
                        } else {
                            $errors["variants.{$i}.stock_quantity"] = 'Stock quantity must be a non-negative integer.';
                        }
                    }

                    $variants[] = [
                        'size'  => $size,
                        'color' => $color,
                        'sku'   => $sku,
                        'price' => $price === null || $price === '' ? null : (string) $price,
                        'stock' => $stock,
                    ];
                }
            }
        }

        if ($errors !== []) {
            $this->error('Validation failed.', 422, ['errors' => $errors]);
        }

        // --- Slug: use the provided one, or auto-generate from name. ---
        $givenSlug = trim((string) ($request->input('slug') ?? ''));
        $baseSlug  = Slugger::make($givenSlug !== '' ? $givenSlug : $name);
        $slug      = $baseSlug;
        $n         = 2;
        while ($this->products->slugExists($slug)) {
            if ($givenSlug !== '') {
                throw new ConflictException('Slug already exists.');
            }
            $slug = "{$baseSlug}-{$n}";
            $n++;
        }

        // --- Product + variants in a single transaction. ---
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $productId = $this->products->create([
                'name'             => $name,
                'slug'             => $slug,
                'description'      => $description === null || $description === '' ? null : (string) $description,
                'base_price'       => $basePrice,
                'category_id'      => $categoryId,
                'is_active'        => $isActive,
                'meta_title'       => $metaTitle,
                'meta_description' => $metaDesc,
            ]);

            foreach ($variants as $variant) {
                $this->variants->create(
                    $productId,
                    $variant['size'],
                    $variant['color'],
                    $variant['sku'],
                    $variant['price'],
                    $variant['stock']
                );
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23000') {
                // Duplicate SKU (uq_variant_sku) or size/color combo (uq_variant_combo).
                throw new ConflictException('A variant SKU or size/color combination already exists.');
            }
            throw $e;
        }

        $product = $this->products->findById($productId);
        $variantRows = array_map(
            static fn (Variant $variant): array => $variant->toArray(),
            $this->variants->listByProduct($productId)
        );

        $this->json(['data' => array_merge($product->toArray(), ['variants' => $variantRows])], 201);
    }

    /**
     * PUT /api/admin/products/{id} — update product fields.
     *
     * Accepts a full or partial body; variants are deliberately NOT touched
     * here (adding/removing variants is a later refinement — see report).
     */
    public function update(Request $request, array $params): never
    {
        $id      = (int) ($params['id'] ?? 0);
        $product = $this->products->findById($id);
        if ($product === null) {
            $this->error('Product not found.', 404);
        }

        $errors  = [];
        $fields  = [];
        $body    = $request->all();

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                $errors['name'] = 'Name is required.';
            } else {
                $fields['name'] = $name;
            }
        }

        if (isset($body['slug'])) {
            $slug = Slugger::make((string) $body['slug'], Slugger::make($product->name));
            if ($slug === '') {
                $errors['slug'] = 'Slug is invalid.';
            } elseif ($this->products->slugExists($slug, $id)) {
                throw new ConflictException('Slug already exists.');
            } else {
                $fields['slug'] = $slug;
            }
        }

        if (array_key_exists('description', $body)) {
            $desc = $body['description'];
            $fields['description'] = $desc === null || $desc === '' ? null : (string) $desc;
        }

        if (isset($body['base_price'])) {
            $price = (string) $body['base_price'];
            if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $price)) {
                $errors['base_price'] = 'Base price must be a valid amount (max 2 decimals).';
            } else {
                $fields['base_price'] = $price;
            }
        }

        if (array_key_exists('category_id', $body)) {
            $cat = $body['category_id'];
            if ($cat === null || $cat === '') {
                $fields['category_id'] = null;
            } else {
                $cat = (int) $cat;
                if ($cat < 1) {
                    $errors['category_id'] = 'Category must be a valid id.';
                } elseif (!$this->categories->exists($cat)) {
                    $errors['category_id'] = 'Category does not exist.';
                } else {
                    $fields['category_id'] = $cat;
                }
            }
        }

        if (array_key_exists('is_active', $body)) {
            $active = $body['is_active'];
            if (is_bool($active)) {
                $fields['is_active'] = $active ? 1 : 0;
            } elseif (in_array((string) $active, ['0', '1'], true)) {
                $fields['is_active'] = (int) $active;
            } else {
                $errors['is_active'] = 'is_active must be 0 or 1.';
            }
        }

        foreach (['meta_title' => 'Meta title', 'meta_description' => 'Meta description'] as $key => $label) {
            $value = $body[$key] ?? null;
            if ($value !== null) {
                $value = trim((string) $value);
                if ($value === '') {
                    $errors[$key] = "{$label} is required.";
                } else {
                    $fields[$key] = $value;
                }
            }
        }

        if ($errors !== []) {
            $this->error('Validation failed.', 422, ['errors' => $errors]);
        }

        $this->products->update($id, $fields);

        $updated = $this->products->findById($id);
        $this->json(['data' => $updated->toArray()]);
    }

    /**
     * DELETE /api/admin/products/{id} — hard-delete, blocked when ordered.
     *
     * The schema's order_items -> product_variants FK is ON DELETE RESTRICT:
     * deleting a product whose variant has rows there would fail at the DB.
     * We check first and reject with 409 (deactivate instead). Otherwise the
     * hard delete is safe and cascades to variants / images.
     */
    public function destroy(Request $request, array $params): never
    {
        $id      = (int) ($params['id'] ?? 0);
        $product = $this->products->findById($id);
        if ($product === null) {
            $this->error('Product not found.', 404);
        }

        if ($this->variants->isOrdered($id)) {
            $this->error('Product has been ordered and cannot be deleted; deactivate it instead.', 409);
        }

        $this->products->delete($id);
        $this->noContent();
    }

    /**
     * POST /api/admin/products/{id}/images — upload multiple images to Cloudinary.
     *
     * Accepts multipart form data with an `images[]` field. Each file is
     * validated as a REAL image by inspecting its content (getimagesize), not
     * by extension or client-declared MIME. Only the Cloudinary secure URL is
     * stored in product_images (spec section 4). First upload becomes the main
     * image when the product has none yet; is_main (index) / sort_order[] may
     * be provided in the multipart body to override defaults.
     */
    public function uploadImages(Request $request, array $params): never
    {
        $id      = (int) ($params['id'] ?? 0);
        $product = $this->products->findById($id);
        if ($product === null) {
            $this->error('Product not found.', 404);
        }

        $files = $request->files('images');
        if ($files === []) {
            $this->error('Validation failed.', 422, ['errors' => ['images' => 'At least one image is required.']]);
        }

        $mimeMap   = [
            IMAGETYPE_GIF  => 'image/gif',
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG  => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
        ];
        $maxBytes  = 5 * 1024 * 1024;
        $errors    = [];
        $valid     = [];
        foreach ($files as $i => $file) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['tmp_name'] === '' || !is_file($file['tmp_name'])) {
                $errors["images.{$i}"] = 'Upload failed.';
                continue;
            }
            if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
                $errors["images.{$i}"] = 'Image size must be larger than 0 bytes and at most 5 MB.';
                continue;
            }
            $info = @getimagesize($file['tmp_name']);
            $mime = ($info !== false && isset($mimeMap[$info[2]])) ? $mimeMap[$info[2]] : null;
            if ($mime === null) {
                $errors["images.{$i}"] = 'Image must be a valid JPEG, PNG, WebP or GIF file.';
                continue;
            }
            $valid[] = $file;
        }
        if ($errors !== []) {
            $this->error('Validation failed.', 422, ['errors' => $errors]);
        }

        // Upload every validated image to Cloudinary first.
        $cloudinary = new CloudinaryService();
        $urls       = [];
        try {
            foreach ($valid as $file) {
                $urls[] = $cloudinary->upload($file['tmp_name']);
            }
        } catch (RuntimeException $e) {
            $this->error('Image upload failed: ' . $e->getMessage(), 502);
        }

        // --- is_main / sort_order handling ---
        $existingCount     = $this->images->countByProduct($id);
        $existingRows      = $this->images->listByProduct($id);
        $baseSort          = 0;
        foreach ($existingRows as $row) {
            $baseSort = max($baseSort, (int) $row['sort_order']);
        }

        // Multipart scalars arrive in $_POST (exposed via query()).
        $mainRequested = $request->query('is_main');
        $targetMain    = null;
        if ($mainRequested !== null && $mainRequested !== '') {
            if (is_numeric($mainRequested)) {
                $targetMain = (int) $mainRequested;
            } elseif (in_array((string) $mainRequested, ['1', 'true', 'on', 'yes'], true)) {
                $targetMain = 0;
            }
        } elseif ($existingCount === 0) {
            $targetMain = 0; // first image of a product with no images yet = main
        }

        $sortsInput = $request->query('sort_order');
        $sorts      = is_array($sortsInput) ? $sortsInput : [];

        if ($targetMain !== null && $targetMain >= 0 && $targetMain < count($urls)) {
            $this->images->clearMain($id); // keep exactly one main image
        }

        $inserted = [];
        foreach ($urls as $i => $url) {
            $sort  = isset($sorts[$i]) && is_numeric($sorts[$i]) ? (int) $sorts[$i] : $baseSort + 1 + $i;
            $isMain = ($targetMain !== null && $targetMain === $i) ? 1 : 0;
            $imageId = $this->images->create($id, $url, $isMain, $sort);
            $inserted[] = [
                'id'         => $imageId,
                'image_url'  => $url,
                'is_main'    => $isMain,
                'sort_order' => $sort,
            ];
        }

        $this->json(['data' => ['product_id' => $id, 'images' => $inserted]], 201);
    }
}