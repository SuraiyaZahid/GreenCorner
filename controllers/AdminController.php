<?php
/**
 * GreenCorner — Admin Controller
 *
 * The Admin panel (P-14, P-15, P-16).
 *
 * Implements:
 *   FR-6.1  the Admin dashboard shows counts of products, orders and users;
 *           the product count excludes soft-deleted products
 *   FR-6.2  create a product, with a mandatory image
 *   FR-6.3  update any product field, including the image
 *   FR-6.4  atomic persistence of the image and the database row
 *   FR-6.5  removal is always a soft delete
 *   FR-6.6  category management
 *
 * Every action starts with require_capability('admin.…'), so a User is
 * refused with HTTP 403 before any state change (FR-7.4). Every state
 * change is protected by a CSRF check (FR-7.7).
 */

require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/authorization.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/image.php';
require_once __DIR__ . '/../models/ProductAdmin.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Product.php';

class AdminController
{
    // -------------------------------------------------------------------------
    // P-14 — Admin Dashboard (FR-6.1)
    // -------------------------------------------------------------------------

    /**
     * The Admin Dashboard.
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function dashboard(array $parameters = array()): void
    {
        require_capability('admin.dashboard');

        $productCount = ProductAdmin::countActive();
        $deletedCount = ProductAdmin::countDeleted();
        $userCount = $this->countUsers();
        $orderCount = $this->countOrders();

        require __DIR__ . '/../views/admin/dashboard.php';
    }

    /**
     * The number of registered accounts.
     *
     * FR-6.1 asks for a count of users. The `users` table exists from
     * Phase 1, so this count is always available.
     *
     * @return int
     */
    private function countUsers(): int
    {
        $row = db_select_one('SELECT COUNT(*) AS `total` FROM `users`');

        return (int) ($row['total'] ?? 0);
    }

    /**
     * The number of orders.
     *
     * The `orders` table arrives in Phase 4, so the count is reported as
     * zero until that phase creates the table. The query is guarded so the
     * dashboard never fails on a database that does not yet have the table.
     *
     * @return int
     */
    private function countOrders(): int
    {
        try {
            $row = db_select_one('SELECT COUNT(*) AS `total` FROM `orders`');
        } catch (Throwable $e) {
            // The orders table does not exist yet (Phase 4).
            return 0;
        }

        return (int) ($row['total'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // P-15 — Admin Product Management (FR-6.2, FR-6.3, FR-6.5)
    // -------------------------------------------------------------------------

    /**
     * The Admin product list.
     *
     * Shows both active and soft-deleted products, 20 per page.
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function products(array $parameters = array()): void
    {
        require_capability('admin.products');

        $totalResults = ProductAdmin::countAll();
        $lastPage = ProductAdmin::pageCount($totalResults, ProductAdmin::PER_PAGE);
        $page = ProductAdmin::normalisePage(get_string('page', '1'), $lastPage);

        $products = ProductAdmin::paginate($page, ProductAdmin::PER_PAGE);
        $categories = Category::all();

        $query = array();

        require __DIR__ . '/../views/admin/products.php';
    }

    /**
     * The form for creating a product.
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function createProductForm(array $parameters = array()): void
    {
        require_capability('admin.products');

        $categories = Category::all();
        $product = null;
        $errors = array();

        require __DIR__ . '/../views/admin/product_form.php';
    }

    /**
     * Refuse a request whose body PHP discarded because it was too large.
     *
     * PHP empties `$_POST` and `$_FILES` when the body exceeds the
     * `post_max_size` directive, which would otherwise look like an empty
     * form. The upload is refused with a clear message instead.
     *
     * @param int $productId The product being edited, or 0 when creating.
     * @return void
     */
    private function refuseOversizedRequest(int $productId = 0): void
    {
        if (!request_body_was_discarded()) {
            return;
        }

        http_response_code(422);

        $message = 'The upload is too large. Please choose a smaller image.';

        $categories = Category::all();
        $product = $productId > 0 ? ProductAdmin::find($productId) : null;
        $errors = array('image' => $message);

        require __DIR__ . '/../views/admin/product_form.php';
        exit;
    }

    /**
     * Store a new product (FR-6.2).
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function createProduct(array $parameters = array()): void
    {
        require_capability('admin.products');

        // The size check comes before the CSRF check: a body that PHP
        // discarded also took the CSRF token with it, so the token would
        // be missing and the visitor would be told their session had
        // expired instead of being told the upload was too large.
        $this->refuseOversizedRequest();

        csrf_require(false, 'admin_product');

        $categories = Category::all();
        $product = null;
        $errors = array();

        $name        = post_string('name');
        $description = post_string('description');
        $price       = post_string('price');
        $stock       = post_string('stock');
        $categoryId  = post_int('category_id');

        $this->validateProductInput(
            $name,
            $description,
            $price,
            $stock,
            $categoryId,
            $categories,
            $errors
        );

        // The image is mandatory on create (FR-2.12, FR-6.3).
        $stored = null;
        if ($errors === array()) {
            if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
                $errors['image'] = 'Please choose an image file.';
            } else {
                $stored = store_image_upload($_FILES['image']);
                if (!$stored['ok']) {
                    $errors['image'] = (string) $stored['error'];
                }
            }
        }

        if ($errors !== array()) {
            $product = array(
                'name'        => $name,
                'description' => $description,
                'price'       => $price,
                'stock'       => $stock,
                'category_id' => $categoryId,
            );

            http_response_code(422);
            require __DIR__ . '/../views/admin/product_form.php';
            return;
        }

        // FR-6.4: the files are already written, so the database write is
        // the last step. If it fails, the files are deleted again and the
        // product is not created.
        try {
            $productId = ProductAdmin::create(array(
                'name'          => $name,
                'description'   => $description,
                'price'         => $price,
                'stock'         => (int) $stock,
                'category_id'   => $categoryId,
                'image_path'    => (string) $stored['original'],
                'thumbnail_path' => (string) $stored['thumbnail'],
            ));
        } catch (Throwable $e) {
            // FR-6.4 / ER-21 / NFR-R-04: the database write failed, so the
            // files that were already written are explicitly deleted.
            delete_stored_images(
                $stored === null ? null : $stored['original'],
                $stored === null ? null : $stored['thumbnail']
            );

            set_flash('error', 'The product could not be saved. Please try again.');
            redirect('/admin/products');
        }

        set_flash('success', 'The product "' . $name . '" was created.');
        redirect('/admin/products');
    }

    /**
     * The form for editing a product.
     *
     * @param array<string, mixed> $parameters The URL parameters.
     */
    public function editProductForm(array $parameters = array()): void
    {
        require_capability('admin.products');

        $productId = (int) ($parameters['id'] ?? 0);
        $product = ProductAdmin::find($productId);

        if ($product === null) {
            not_found('That product does not exist.');
        }

        $categories = Category::all();
        $errors = array();

        require __DIR__ . '/../views/admin/product_form.php';
    }

    /**
     * Save a product edit (FR-6.3).
     *
     * @param array<string, mixed> $parameters The URL parameters.
     */
    public function updateProduct(array $parameters = array()): void
    {
        require_capability('admin.products');

        // The size check comes before the CSRF check, for the reason
        // explained in createProduct().
        $this->refuseOversizedRequest((int) ($parameters['id'] ?? 0));

        csrf_require(false, 'admin_product', array('id' => (int) ($parameters['id'] ?? 0)));

        $productId = (int) ($parameters['id'] ?? 0);
        $product = ProductAdmin::find($productId);

        if ($product === null) {
            not_found('That product does not exist.');
        }

        $categories = Category::all();
        $errors = array();

        $name        = post_string('name');
        $description = post_string('description');
        $price       = post_string('price');
        $stock       = post_string('stock');
        $categoryId  = post_int('category_id');

        $this->validateProductInput(
            $name,
            $description,
            $price,
            $stock,
            $categoryId,
            $categories,
            $errors
        );

        // The image is OPTIONAL on update (FR-6.3). When no file is
        // supplied, the existing image is kept.
        $stored = null;
        $newImageSupplied = isset($_FILES['image'])
            && is_array($_FILES['image'])
            && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($errors === array() && $newImageSupplied) {
            $stored = store_image_upload($_FILES['image']);
            if (!$stored['ok']) {
                $errors['image'] = (string) $stored['error'];
            }
        }

        if ($errors !== array()) {
            $product = array_merge($product, array(
                'name'        => $name,
                'description' => $description,
                'price'       => $price,
                'stock'       => $stock,
                'category_id' => $categoryId,
            ));

            http_response_code(422);
            require __DIR__ . '/../views/admin/product_form.php';
            return;
        }

        $previousOriginal  = (string) $product['image_path'];
        $previousThumbnail = (string) $product['thumbnail_path'];

        try {
            ProductAdmin::update($productId, array(
                'name'        => $name,
                'description' => $description,
                'price'       => $price,
                'stock'       => (int) $stock,
                'category_id' => $categoryId,
            ));

            if ($stored !== null && $stored['ok']) {
                ProductAdmin::replaceImage(
                    $productId,
                    (string) $stored['original'],
                    (string) $stored['thumbnail']
                );
            }
        } catch (Throwable $e) {
            // FR-6.4: the database write failed, so the newly written files
            // are deleted again. The product's previous image still works,
            // because it was never touched.
            delete_stored_images(
                $stored === null ? null : $stored['original'],
                $stored === null ? null : $stored['thumbnail']
            );

            set_flash('error', 'The product could not be saved. Please try again.');
            redirect('/admin/products');
        }

        // FR-6.3 / FR-6.4: the previous files are deleted ONLY after the new
        // ones are safely stored and the database write has succeeded.
        if ($stored !== null && $stored['ok']) {
            delete_stored_images($previousOriginal, $previousThumbnail);
        }

        set_flash('success', 'The product "' . $name . '" was updated.');
        redirect('/admin/products');
    }

    /**
     * Soft-delete a product (FR-6.5).
     *
     * @param array<string, mixed> $parameters The URL parameters.
     */
    public function deleteProduct(array $parameters = array()): void
    {
        require_capability('admin.products');
        csrf_require(false, 'admin_product');

        $productId = (int) ($parameters['id'] ?? 0);
        $product = ProductAdmin::find($productId);

        if ($product === null) {
            not_found('That product does not exist.');
        }

        ProductAdmin::softDelete($productId);

        set_flash('success', 'The product "' . $product['name'] . '" was removed.');
        redirect('/admin/products');
    }

    /**
     * Restore a soft-deleted product.
     *
     * @param array<string, mixed> $parameters The URL parameters.
     */
    public function restoreProduct(array $parameters = array()): void
    {
        require_capability('admin.products');
        csrf_require(false, 'admin_product');

        $productId = (int) ($parameters['id'] ?? 0);
        $product = ProductAdmin::find($productId);

        if ($product === null) {
            not_found('That product does not exist.');
        }

        ProductAdmin::restore($productId);

        set_flash('success', 'The product "' . $product['name'] . '" was restored.');
        redirect('/admin/products');
    }

    /**
     * Validate the product fields shared by the create and update forms.
     *
     * @param string                          $name
     * @param string                          $description
     * @param string                          $price
     * @param string                          $stock
     * @param int                             $categoryId
     * @param array<int, array<string,mixed>> $categories
     * @param array<string,string>            $errors  Filled with field-level messages.
     * @return void
     */
    private function validateProductInput(
        string $name,
        string $description,
        string $price,
        string $stock,
        int $categoryId,
        array $categories,
        array &$errors
    ): void {
        if ($name === '') {
            $errors['name'] = 'Please enter a product name.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'The product name may not be longer than 150 characters.';
        }

        if (mb_strlen($description) > 5000) {
            $errors['description'] = 'The description may not be longer than 5,000 characters.';
        }

        // BR-1 / R-SQL-2: the price is a DECIMAL(10,2) value. It is
        // validated as a decimal string here and stored as a string, so no
        // floating-point arithmetic is ever applied to money.
        if ($price === '') {
            $errors['price'] = 'Please enter a price.';
        } elseif (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $price)) {
            $errors['price'] = 'Please enter a price with up to two decimal places.';
        } else {
            $priceValue = (float) $price;
            if ($priceValue < 0) {
                $errors['price'] = 'The price may not be negative.';
            } elseif ($priceValue > 99999999.99) {
                $errors['price'] = 'That price is too large.';
            }
        }

        if ($stock === '') {
            $errors['stock'] = 'Please enter the stock quantity.';
        } elseif (!ctype_digit($stock)) {
            $errors['stock'] = 'Please enter the stock quantity as a whole number.';
        } elseif ((int) $stock < 0) {
            $errors['stock'] = 'The stock quantity may not be negative.';
        } elseif ((int) $stock > 1000000) {
            $errors['stock'] = 'That stock quantity is too large.';
        }

        // FR-2.3: every product belongs to exactly one existing category.
        $categoryExists = false;
        foreach ($categories as $category) {
            if ((int) $category['id'] === $categoryId) {
                $categoryExists = true;
                break;
            }
        }

        if ($categoryId <= 0 || !$categoryExists) {
            $errors['category_id'] = 'Please choose a category.';
        }
    }

    // -------------------------------------------------------------------------
    // P-16 — Admin Category Management (FR-6.6)
    // -------------------------------------------------------------------------

    /**
     * The Admin category list.
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function categories(array $parameters = array()): void
    {
        require_capability('admin.categories');

        $categories = Category::all();
        $errors = array();
        $blockedProducts = array();

        require __DIR__ . '/../views/admin/categories.php';
    }

    /**
     * Create a category (FR-6.6).
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function createCategory(array $parameters = array()): void
    {
        require_capability('admin.categories');
        csrf_require(false, 'admin_category');

        $categories = Category::all();
        $errors = array();
        $blockedProducts = array();

        $name        = post_string('name');
        $description = post_string('description');

        if ($name === '') {
            $errors['name'] = 'Please enter a category name.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'The category name may not be longer than 100 characters.';
        } elseif (Category::findByName($name) !== null) {
            $errors['name'] = 'A category with that name already exists.';
        }

        if (mb_strlen($description) > 255) {
            $errors['description'] = 'The description may not be longer than 255 characters.';
        }

        if ($errors !== array()) {
            http_response_code(422);
            require __DIR__ . '/../views/admin/categories.php';
            return;
        }

        Category::create($name, $description);

        set_flash('success', 'The category "' . $name . '" was created.');
        redirect('/admin/categories');
    }

    /**
     * Rename a category (FR-6.6).
     *
     * @param array<string, mixed> $parameters The URL parameters.
     */
    public function updateCategory(array $parameters = array()): void
    {
        require_capability('admin.categories');
        csrf_require(false, 'admin_category', array('id' => (int) ($parameters['id'] ?? 0)));

        $categoryId = (int) ($parameters['id'] ?? 0);
        $category = Category::find($categoryId);

        if ($category === null) {
            not_found('That category does not exist.');
        }

        $categories = Category::all();
        $errors = array();
        $blockedProducts = array();

        $name        = post_string('name');
        $description = post_string('description');

        if ($name === '') {
            $errors['name'] = 'Please enter a category name.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'The category name may not be longer than 100 characters.';
        } else {
            $existing = Category::findByName($name);
            if ($existing !== null && (int) $existing['id'] !== $categoryId) {
                $errors['name'] = 'A category with that name already exists.';
            }
        }

        if (mb_strlen($description) > 255) {
            $errors['description'] = 'The description may not be longer than 255 characters.';
        }

        if ($errors !== array()) {
            http_response_code(422);
            require __DIR__ . '/../views/admin/categories.php';
            return;
        }

        Category::update($categoryId, $name, $description);

        set_flash('success', 'The category "' . $name . '" was updated.');
        redirect('/admin/categories');
    }

    /**
     * Remove a category (FR-6.6).
     *
     * A category that still has products attached is NOT removed. The
     * blocking products are named, so the Admin can reassign them by hand.
     *
     * @param array<string, mixed> $parameters The URL parameters.
     */
    public function deleteCategory(array $parameters = array()): void
    {
        require_capability('admin.categories');
        csrf_require(false, 'admin_category');

        $categoryId = (int) ($parameters['id'] ?? 0);
        $category = Category::find($categoryId);

        if ($category === null) {
            not_found('That category does not exist.');
        }

        // FR-6.6 / AC-27: the removal is refused while products are
        // attached, and the blocking products are named.
        $blocking = Category::productsIn($categoryId);

        if ($blocking !== array()) {
            $categories = Category::all();
            $errors = array(
                'delete' => 'This category still has '
                    . count($blocking)
                    . ' product(s) attached. Reassign them to another category first.',
            );
            $blockedProducts = $blocking;

            http_response_code(422);
            require __DIR__ . '/../views/admin/categories.php';
            return;
        }

        Category::delete($categoryId);

        set_flash('success', 'The category "' . $category['name'] . '" was removed.');
        redirect('/admin/categories');
    }
}
