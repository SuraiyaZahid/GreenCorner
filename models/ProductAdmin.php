<?php
/**
 * GreenCorner — Product Model (Admin)
 *
 * Product management for the Admin panel (P-15).
 *
 * Implements:
 *   FR-6.2  create a product: name, description, price, stock, category and
 *           a mandatory image. The product appears in the storefront
 *           immediately.
 *   FR-6.3  update any product field, including the image. The image is
 *           optional on update: the existing image is kept when no new file
 *           is supplied.
 *   FR-6.4  atomic persistence — the files are written first, the database
 *           row second. A failed write deletes the files again, and the
 *           previous image files are removed only after the new ones are
 *           safely stored.
 *   FR-6.5  removal is always a soft delete. The row is retained with
 *           `is_deleted = 1` and is never hard-deleted.
 *
 * The product table has NO `is_active` column. The approved deletion
 * mechanism is `is_deleted` alone (SRS §14.9, FR-2.8, FR-6.5). The
 * `is_active` column belongs to the `users` table and backs FR-7.3 and
 * FR-6.11; it has no meaning for a product and is not used here.
 */

require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/image.php';

class ProductAdmin
{
    /**
     * The number of products shown on one page of the Admin product list.
     */
    public const PER_PAGE = 20;

    /**
     * Find a product by id, including soft-deleted ones.
     *
     * The Admin list and the edit form need to reach a soft-deleted product
     * so it can be identified and, if the Admin chooses, restored by editing
     * it back to a live state.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return db_select_one(
            "SELECT p.`id`, p.`name`, p.`description`, p.`price`, p.`stock`,
                    p.`image_path`, p.`thumbnail_path`, p.`category_id`,
                    p.`is_deleted`, p.`created_at`, p.`updated_at`,
                    c.`name` AS `category_name`
               FROM `products` p
               JOIN `categories` c ON c.`id` = p.`category_id`
              WHERE p.`id` = ?
              LIMIT 1",
            'i',
            array($id)
        );
    }

    /**
     * The number of products, excluding soft-deleted ones (FR-6.1).
     *
     * @return int
     */
    public static function countActive(): int
    {
        $row = db_select_one(
            'SELECT COUNT(*) AS `total` FROM `products` WHERE `is_deleted` = 0'
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * The number of products, including soft-deleted ones.
     *
     * @return int
     */
    public static function countAll(): int
    {
        $row = db_select_one('SELECT COUNT(*) AS `total` FROM `products`');

        return (int) ($row['total'] ?? 0);
    }

    /**
     * The number of soft-deleted products.
     *
     * @return int
     */
    public static function countDeleted(): int
    {
        $row = db_select_one(
            'SELECT COUNT(*) AS `total` FROM `products` WHERE `is_deleted` = 1'
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * One page of products for the Admin list.
     *
     * Unlike the storefront listing, this list shows BOTH active and
     * soft-deleted products, so the Admin can see the full catalogue
     * history. Soft-deleted rows are returned with their `is_deleted` flag
     * so the view can mark them.
     *
     * @param int $page    The page number, starting at 1.
     * @param int $perPage The number of products per page.
     * @return array<int, array<string, mixed>>
     */
    public static function paginate(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, min($perPage, 100));
        $offset = ($page - 1) * $perPage;

        // The LIMIT and OFFSET are bound as parameters, so they cannot be
        // used to inject SQL.
        return db_select(
            "SELECT p.`id`, p.`name`, p.`description`, p.`price`, p.`stock`,
                    p.`image_path`, p.`thumbnail_path`, p.`category_id`,
                    p.`is_deleted`, p.`created_at`,
                    c.`name` AS `category_name`
               FROM `products` p
               JOIN `categories` c ON c.`id` = p.`category_id`
              ORDER BY p.`created_at` DESC, p.`id` DESC
              LIMIT ? OFFSET ?",
            'ii',
            array($perPage, $offset)
        );
    }

    /**
     * The number of pages needed for a result count.
     *
     * @param int $totalResults
     * @param int $perPage
     * @return int
     */
    public static function pageCount(int $totalResults, int $perPage = self::PER_PAGE): int
    {
        $perPage = max(1, $perPage);

        if ($totalResults <= 0) {
            return 1;
        }

        return (int) ceil($totalResults / $perPage);
    }

    /**
     * Normalise a requested page number to a valid one.
     *
     * @param mixed $requested
     * @param int   $lastPage
     * @return int
     */
    public static function normalisePage($requested, int $lastPage): int
    {
        $lastPage = max(1, $lastPage);

        if (!is_scalar($requested)) {
            return 1;
        }

        $value = trim((string) $requested);

        if ($value === '' || !ctype_digit($value)) {
            return 1;
        }

        $page = (int) $value;

        if ($page < 1) {
            return 1;
        }

        if ($page > $lastPage) {
            return $lastPage;
        }

        return $page;
    }

    /**
     * The current time, formatted for a DATETIME column.
     *
     * The `products` and `users` tables use DATETIME columns, while the
     * session and login-attempt tables use Unix timestamps. This helper
     * keeps the two apart in one place.
     *
     * @return string The current time, 'Y-m-d H:i:s'.
     */
    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Create a product.
     *
     * The image files must already be stored: the caller writes them first
     * and, if this write fails, deletes them again (FR-6.4).
     *
     * @param array<string, mixed> $data
     * @return int The new product id.
     */
    public static function create(array $data): int
    {
        return db_insert_id(
            'INSERT INTO `products`
                (`name`, `description`, `price`, `stock`, `image_path`,
                 `thumbnail_path`, `category_id`, `is_deleted`)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
            'ssdissi',
            array(
                (string) $data['name'],
                (string) $data['description'],
                (string) $data['price'],
                (int) $data['stock'],
                (string) $data['image_path'],
                (string) $data['thumbnail_path'],
                (int) $data['category_id'],
            )
        );
    }

    /**
     * Update a product.
     *
     * When the image is not being changed, the existing image paths are
     * kept, so the update never orphans the product's image (FR-6.3).
     *
     * @param int                 $id
     * @param array<string,mixed> $data
     * @return int The number of affected rows.
     */
    public static function update(int $id, array $data): int
    {
        return db_write(
            'UPDATE `products`
                SET `name` = ?, `description` = ?, `price` = ?, `stock` = ?,
                    `category_id` = ?, `updated_at` = ?
              WHERE `id` = ?',
            'ssdissi',
            array(
                (string) $data['name'],
                (string) $data['description'],
                (string) $data['price'],
                (int) $data['stock'],
                (int) $data['category_id'],
                self::now(),
                $id,
            )
        );
    }

    /**
     * Replace a product's image.
     *
     * @param int    $id
     * @param string $originalName
     * @param string $thumbnailName
     * @return int The number of affected rows.
     */
    public static function replaceImage(int $id, string $originalName, string $thumbnailName): int
    {
        return db_write(
            'UPDATE `products`
                SET `image_path` = ?, `thumbnail_path` = ?, `updated_at` = ?
              WHERE `id` = ?',
            'sssi',
            array($originalName, $thumbnailName, self::now(), $id)
        );
    }

    /**
     * Soft-delete a product (FR-6.5).
     *
     * The row is retained with `is_deleted = 1`. The row is NEVER
     * hard-deleted, and the image files are deliberately KEPT: a
     * soft-deleted product may still be referenced by a historical order.
     *
     * @param int $id
     * @return int The number of affected rows.
     */
    public static function softDelete(int $id): int
    {
        return db_write(
            'UPDATE `products` SET `is_deleted` = 1, `updated_at` = ? WHERE `id` = ?',
            'si',
            array(self::now(), $id)
        );
    }

    /**
     * Restore a soft-deleted product.
     *
     * @param int $id
     * @return int The number of affected rows.
     */
    public static function restore(int $id): int
    {
        return db_write(
            'UPDATE `products` SET `is_deleted` = 0, `updated_at` = ? WHERE `id` = ?',
            'si',
            array(self::now(), $id)
        );
    }
}
