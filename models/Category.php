<?php
/**
 * GreenCorner — Category Model (Admin)
 *
 * Category management for the Admin panel (P-16).
 *
 * Implements FR-6.6:
 *   An Admin may add, rename and remove categories. A category that still
 *   has products attached cannot be removed until those products have been
 *   reassigned to another category by hand. There is no "Uncategorised"
 *   fallback: reassignment is always an explicit choice by the Admin.
 *
 * The removal check counts EVERY product attached to the category,
 * including soft-deleted ones. A soft-deleted product still holds its
 * `category_id`, so it must be reassigned too — otherwise the row would be
 * left pointing at a category that no longer exists.
 */

require_once __DIR__ . '/../helpers/db.php';

class Category
{
    /**
     * All categories, with the number of products attached to each.
     *
     * The count includes soft-deleted products, because those rows still
     * reference the category and must be reassigned before it is removed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return db_select(
            "SELECT c.`id`, c.`name`, c.`description`,
                    (SELECT COUNT(*) FROM `products` p
                      WHERE p.`category_id` = c.`id`) AS `product_count`
               FROM `categories` c
              ORDER BY c.`name` ASC"
        );
    }

    /**
     * Find a category by its id.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return db_select_one(
            'SELECT `id`, `name`, `description` FROM `categories` WHERE `id` = ? LIMIT 1',
            'i',
            array($id)
        );
    }

    /**
     * Find a category by its exact name.
     *
     * @param string $name
     * @return array<string, mixed>|null
     */
    public static function findByName(string $name): ?array
    {
        return db_select_one(
            'SELECT `id`, `name`, `description` FROM `categories` WHERE `name` = ? LIMIT 1',
            's',
            array($name)
        );
    }

    /**
     * The products attached to a category, for naming them when a removal
     * is refused (FR-6.6, AC-27).
     *
     * Soft-deleted products are included: they also have to be reassigned.
     *
     * @param int $categoryId
     * @return array<int, array<string, mixed>>
     */
    public static function productsIn(int $categoryId): array
    {
        return db_select(
            'SELECT `id`, `name`, `is_deleted`
               FROM `products`
              WHERE `category_id` = ?
              ORDER BY `name` ASC',
            'i',
            array($categoryId)
        );
    }

    /**
     * The number of products attached to a category.
     *
     * @param int $categoryId
     * @return int
     */
    public static function productCount(int $categoryId): int
    {
        $row = db_select_one(
            'SELECT COUNT(*) AS `total` FROM `products` WHERE `category_id` = ?',
            'i',
            array($categoryId)
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Create a category.
     *
     * @param string $name
     * @param string $description
     * @return int The new category id.
     */
    public static function create(string $name, string $description = ''): int
    {
        return db_insert_id(
            'INSERT INTO `categories` (`name`, `description`) VALUES (?, ?)',
            'ss',
            array($name, $description)
        );
    }

    /**
     * Rename a category, and optionally change its description.
     *
     * @param int    $id
     * @param string $name
     * @param string $description
     * @return int The number of affected rows.
     */
    public static function update(int $id, string $name, string $description = ''): int
    {
        return db_write(
            'UPDATE `categories` SET `name` = ?, `description` = ? WHERE `id` = ?',
            'ssi',
            array($name, $description, $id)
        );
    }

    /**
     * Remove a category.
     *
     * The caller must have established that no products are attached. This
     * method refuses to remove a category that still has products, so a
     * mistake in the caller cannot orphan a product row (FR-6.6).
     *
     * @param int $id
     * @return bool True when the category was removed, false when products
     *              are still attached.
     */
    public static function delete(int $id): bool
    {
        if (self::productCount($id) > 0) {
            return false;
        }

        db_write('DELETE FROM `categories` WHERE `id` = ?', 'i', array($id));

        return true;
    }
}
