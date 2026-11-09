<?php
/**
 * GreenCorner — Cart Model
 *
 * Data access for the `cart_items` table.
 *
 * Implements:
 *   FR-3.1  adding an item from the listing, as a background request
 *   FR-3.2  the Cart page lists the items with their current prices
 *   FR-3.3  duplicate prevention — one row per (user_id, product_id)
 *   FR-3.5  removing an item
 *   FR-3.6  changing a quantity, clamped to the available stock
 *   FR-3.7  the current price is always displayed; `price_at_add` is kept for
 *           change detection only and never used to compute a charge
 *   FR-3.8  a quantity above the available stock is reduced, and an
 *           unavailable item stays in the cart and blocks checkout
 *   FR-3.9  an empty cart
 *   FR-3.10 the cart is keyed by user_id, so it survives logout and session
 *           expiry
 *   FR-3.11 the Proceed to Payment action
 *
 * The Phase 4 checkout reads this model to build the order, and clears it
 * inside the checkout transaction (FR-4.15).
 */

require_once dirname(__DIR__) . '/helpers/db.php';

class Cart
{
    /**
     * Add a product to the cart, incrementing the quantity when the product
     * is already present (FR-3.3).
     *
     * @param int $userId
     * @param int $productId
     * @param int $availableStock
     * @return bool True when the item is in the cart afterwards.
     */
    public static function addItem(int $userId, int $productId, int $availableStock): bool
    {
        if ($userId <= 0 || $productId <= 0 || $availableStock < 1) {
            return false;
        }

        $product = db_select_one(
            'SELECT `price` FROM `products` WHERE `id` = ? AND `is_deleted` = 0 LIMIT 1',
            'i',
            array($productId)
        );

        if ($product === null) {
            return false;
        }

        $price = (string) $product['price'];

        // Try to update the existing row first.
        $affected = db_write(
            'UPDATE `cart_items`
                SET `quantity` = `quantity` + 1
              WHERE `user_id` = ? AND `product_id` = ?',
            'ii',
            array($userId, $productId)
        );

        if ($affected > 0) {
            return true;
        }

        // No existing row: insert one. A concurrent request may have
        // inserted it in the meantime, so a duplicate key is retried as an
        // increment (ER-17).
        try {
            db_write(
                "INSERT INTO `cart_items`
                        (`user_id`, `product_id`, `quantity`, `price_at_add`)
                 VALUES (?, ?, 1, ?)",
                'iis',
                array($userId, $productId, $price)
            );
        } catch (RuntimeException $e) {
            if (db_error_code($e->getCode()) === 'duplicate_entry') {
                db_write(
                    'UPDATE `cart_items`
                        SET `quantity` = `quantity` + 1
                      WHERE `user_id` = ? AND `product_id` = ?',
                    'ii',
                    array($userId, $productId)
                );
                return true;
            }
            throw $e;
        }

        return true;
    }

    /**
     * The total number of items in the user's cart (the badge count).
     *
     * @param int $userId
     * @return int
     */
    public static function countItems(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $row = db_select_one(
            'SELECT COALESCE(SUM(`quantity`), 0) AS `total` FROM `cart_items` WHERE `user_id` = ?',
            'i',
            array($userId)
        );

        return $row === null ? 0 : (int) $row['total'];
    }

    // -----------------------------------------------------------------------
    // Reading the cart
    // -----------------------------------------------------------------------

    /**
     * The lines of a user's cart, with the current product data.
     *
     * The join brings in the CURRENT price and the CURRENT stock, because
     * FR-3.7 requires the cart to display the current price and FR-3.8
     * requires it to react to the current stock. `price_at_add` is carried
     * alongside them so the view can show the old and the new price when
     * they differ.
     *
     * A soft-deleted product is NOT filtered out here: FR-3.8 requires it to
     * remain visible in the cart with the message "No longer available", so
     * the join is a LEFT JOIN and the `is_deleted` flag is carried through.
     *
     * @param int $userId
     * @return array<int, array<string, mixed>>
     */
    public static function items(int $userId): array
    {
        if ($userId <= 0) {
            return array();
        }

        return db_select(
            "SELECT ci.`id` AS `cart_item_id`, ci.`product_id`, ci.`quantity`,
                    ci.`price_at_add`,
                    p.`name` AS `product_name`, p.`price` AS `current_price`,
                    p.`stock` AS `current_stock`, p.`is_deleted`,
                    p.`thumbnail_path`, p.`image_path`
               FROM `cart_items` ci
          LEFT JOIN `products` p ON p.`id` = ci.`product_id`
              WHERE ci.`user_id` = ?
              ORDER BY ci.`added_at` ASC, ci.`id` ASC",
            'i',
            array($userId)
        );
    }

    /**
     * The number of lines in the cart.
     *
     * @param int $userId
     * @return int
     */
    public static function countLines(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $row = db_select_one(
            'SELECT COUNT(*) AS `total` FROM `cart_items` WHERE `user_id` = ?',
            'i',
            array($userId)
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Whether the cart holds a given product.
     *
     * @param int $userId
     * @param int $productId
     * @return bool
     */
    public static function hasProduct(int $userId, int $productId): bool
    {
        if ($userId <= 0 || $productId <= 0) {
            return false;
        }

        $row = db_select_one(
            'SELECT `id` FROM `cart_items` WHERE `user_id` = ? AND `product_id` = ? LIMIT 1',
            'ii',
            array($userId, $productId)
        );

        return $row !== null;
    }

    /**
     * The quantity of one product in the cart.
     *
     * @param int $userId
     * @param int $productId
     * @return int
     */
    public static function quantityOf(int $userId, int $productId): int
    {
        if ($userId <= 0 || $productId <= 0) {
            return 0;
        }

        $row = db_select_one(
            'SELECT `quantity` FROM `cart_items` WHERE `user_id` = ? AND `product_id` = ? LIMIT 1',
            'ii',
            array($userId, $productId)
        );

        return $row === null ? 0 : (int) $row['quantity'];
    }

    // -----------------------------------------------------------------------
    // Changing the cart
    // -----------------------------------------------------------------------

    /**
     * Remove one line from the cart (FR-3.5).
     *
     * @param int $userId
     * @param int $productId
     * @return bool True when a row was removed.
     */
    public static function removeItem(int $userId, int $productId): bool
    {
        if ($userId <= 0 || $productId <= 0) {
            return false;
        }

        $affected = db_write(
            'DELETE FROM `cart_items` WHERE `user_id` = ? AND `product_id` = ?',
            'ii',
            array($userId, $productId)
        );

        return $affected > 0;
    }

    /**
     * Empty the cart (FR-4.15: the cart is cleared inside the checkout
     * transaction).
     *
     * @param int $userId
     * @return int The number of lines removed.
     */
    public static function clear(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return db_write('DELETE FROM `cart_items` WHERE `user_id` = ?', 'i', array($userId));
    }

    /**
     * Change the quantity of one line (FR-3.6).
     *
     * The quantity is clamped to the available stock and the caller is told
     * what happened, so the user can be notified (FR-3.8).
     *
     * A quantity below 1 is rejected outright: the row is not removed as a
     * side effect.
     *
     * @param int $userId
     * @param int $productId
     * @param int $quantity
     * @param int $availableStock
     * @return array{ok: bool, quantity: int, clamped: bool, message: string}
     */
    public static function setQuantity(int $userId, int $productId, int $quantity, int $availableStock): array
    {
        $result = array(
            'ok'       => false,
            'quantity' => 0,
            'clamped'  => false,
            'message'  => '',
        );

        if ($userId <= 0 || $productId <= 0) {
            $result['message'] = 'That item could not be found in your cart.';
            return $result;
        }

        if ($quantity < 1) {
            $result['message'] = 'The quantity must be at least 1.';
            return $result;
        }

        if ($availableStock < 1) {
            $result['message'] = 'That product is no longer available. Please remove it from your cart.';
            return $result;
        }

        // FR-3.6 / FR-3.8: a quantity above the available stock is clamped
        // down, and the user is notified.
        if ($quantity > $availableStock) {
            $quantity = $availableStock;
            $result['clamped'] = true;
            $result['message'] = 'Only ' . $availableStock . ' of that product are available, so the quantity was reduced.';
        }

        $affected = db_write(
            'UPDATE `cart_items` SET `quantity` = ? WHERE `user_id` = ? AND `product_id` = ?',
            'iii',
            array($quantity, $userId, $productId)
        );

        if ($affected <= 0) {
            $result['message'] = 'That item could not be found in your cart.';
            return $result;
        }

        $result['ok'] = true;
        $result['quantity'] = $quantity;

        return $result;
    }
}
