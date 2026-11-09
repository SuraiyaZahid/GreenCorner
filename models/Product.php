<?php
/**
 * GreenCorner — Product Model
 *
 * Data access for the `products` and `categories` tables.
 *
 * Implements:
 *   FR-2.1  product listing page, soft-deleted products excluded
 *   FR-2.2  listing cards use the generated thumbnail
 *   FR-2.3  every product belongs to exactly one category
 *   FR-2.4  category filter, combined with an active search term
 *   FR-2.5  search by name: case-insensitive substring
 *   FR-2.6  multi-word search: every word must appear in the name
 *   FR-2.8  soft-deleted products are excluded from the storefront
 *   FR-2.10 pagination, 12 products per page
 *   FR-2.11 ordering: newest first, deterministic
 */

require_once __DIR__ . '/../helpers/db.php';

class Product
{
    /**
     * The number of products shown on one page of the listing (FR-2.10).
     */
    public const PER_PAGE = 12;

    /**
     * Find an active (not soft-deleted) product by id.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public static function findActiveById(int $id): ?array
    {
        return db_select_one(
            "SELECT p.`id`, p.`name`, p.`description`, p.`price`, p.`stock`,
                    p.`image_path`, p.`thumbnail_path`, p.`category_id`,
                    p.`is_deleted`, p.`created_at`,
                    c.`name` AS `category_name`
               FROM `products` p
               JOIN `categories` c ON c.`id` = p.`category_id`
              WHERE p.`id` = ? AND p.`is_deleted` = 0
              LIMIT 1",
            'i',
            array($id)
        );
    }

    /**
     * The newest products, for the landing page preview (FR-2.11).
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function latest(int $limit = 8): array
    {
        $limit = max(1, min($limit, 24));

        return db_select(
            "SELECT p.`id`, p.`name`, p.`price`, p.`stock`,
                    p.`thumbnail_path`, c.`name` AS `category_name`
               FROM `products` p
               JOIN `categories` c ON c.`id` = p.`category_id`
              WHERE p.`is_deleted` = 0
              ORDER BY p.`created_at` DESC, p.`id` DESC
              LIMIT ?",
            'i',
            array($limit)
        );
    }

    /**
     * All categories, for the navigation strip.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categories(): array
    {
        return db_select(
            "SELECT c.`id`, c.`name`, c.`description`,
                    (SELECT COUNT(*) FROM `products` p
                      WHERE p.`category_id` = c.`id` AND p.`is_deleted` = 0) AS `product_count`
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
    public static function findCategoryById(int $id): ?array
    {
        return db_select_one(
            'SELECT `id`, `name`, `description` FROM `categories` WHERE `id` = ? LIMIT 1',
            'i',
            array($id)
        );
    }

    /**
     * Build the WHERE clause and bound values for a catalogue query.
     *
     * The clause always excludes soft-deleted products (FR-2.8). A category
     * filter and a search term are combined with AND, so the result set is
     * the intersection of the two (FR-2.4).
     *
     * @param int|null    $categoryId The selected category, or null.
     * @param string|null $search     The search term, or null.
     * @return array{where: string, types: string, values: array<int, mixed>}
     */
    private static function buildFilters(?int $categoryId, ?string $search): array
    {
        $clauses = array('p.`is_deleted` = 0');
        $types = '';
        $values = array();

        // FR-2.4: the category filter.
        if ($categoryId !== null && $categoryId > 0) {
            $clauses[] = 'p.`category_id` = ?';
            $types .= 'i';
            $values[] = $categoryId;
        }

        // FR-2.5 and FR-2.6: search by name. Each word of the term must
        // appear somewhere in the product name.
        if ($search !== null && $search !== '') {
            $words = preg_split('/\s+/', trim($search)) ?: array();

            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }

                // The LIKE pattern is built here and bound as a parameter,
                // so the term is never concatenated into the SQL text.
                $clauses[] = 'p.`name` LIKE ?';
                $types .= 's';
                $values[] = '%' . self::escapeLike($word) . '%';
            }
        }

        return array(
            'where'  => implode(' AND ', $clauses),
            'types'  => $types,
            'values' => $values,
        );
    }

    /**
     * Escape the wildcard characters in a search word.
     *
     * The user supplies the text; the LIKE wildcards are added here. A
     * literal % or _ in the term is escaped so it matches only itself.
     *
     * @param string $word
     * @return string
     */
    private static function escapeLike(string $word): string
    {
        return str_replace(
            array('\\', '%', '_'),
            array('\\\\', '\\%', '\\_'),
            $word
        );
    }

    /**
     * Count the products matching a category filter and a search term.
     *
     * @param int|null    $categoryId
     * @param string|null $search
     * @return int
     */
    public static function countFiltered(?int $categoryId = null, ?string $search = null): int
    {
        $filters = self::buildFilters($categoryId, $search);

        $row = db_select_one(
            'SELECT COUNT(*) AS `total`
               FROM `products` p
              WHERE ' . $filters['where'],
            $filters['types'],
            $filters['values']
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * One page of products matching a category filter and a search term.
     *
     * Only the current page is loaded, not the whole catalogue (FR-2.1
     * acceptance criterion 3, FR-2.10 acceptance criterion 6).
     *
     * The ordering is newest first, with the id as a tie-breaker so that
     * products created within the same second still appear in a stable,
     * defined order (FR-2.11).
     *
     * @param int         $page       The page number, starting at 1.
     * @param int|null    $categoryId The selected category, or null.
     * @param string|null $search     The search term, or null.
     * @param int         $perPage    The number of products per page.
     * @return array<int, array<string, mixed>>
     */
    public static function paginate(
        int $page = 1,
        ?int $categoryId = null,
        ?string $search = null,
        int $perPage = self::PER_PAGE
    ): array {
        $perPage = max(1, min($perPage, 100));
        $offset = ($page - 1) * $perPage;

        $filters = self::buildFilters($categoryId, $search);

        // The LIMIT and OFFSET are bound as parameters, so they cannot be
        // used to inject SQL.
        $sql = 'SELECT p.`id`, p.`name`, p.`description`, p.`price`, p.`stock`,
                       p.`image_path`, p.`thumbnail_path`, p.`category_id`,
                       p.`created_at`, c.`name` AS `category_name`
                  FROM `products` p
                  JOIN `categories` c ON c.`id` = p.`category_id`
                 WHERE ' . $filters['where'] . '
                 ORDER BY p.`created_at` DESC, p.`id` DESC
                 LIMIT ? OFFSET ?';

        $types = $filters['types'] . 'ii';
        $values = $filters['values'];
        $values[] = $perPage;
        $values[] = $offset;

        return db_select($sql, $types, $values);
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
     * A page number that is not numeric, is zero or negative, or is beyond
     * the last page falls back to a valid page (FR-2.10 acceptance criterion
     * 5).
     *
     * @param mixed $requested The requested page number.
     * @param int   $lastPage   The last valid page number.
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
}
