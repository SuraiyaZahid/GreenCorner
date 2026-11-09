<?php
/**
 * GreenCorner — Catalogue Controller
 *
 * Serves the public storefront pages:
 *   P-05  Product Listing  (FR-2.1 … FR-2.6, FR-2.9 … FR-2.11)
 *   P-06  Product Detail   (FR-2.7, FR-2.8, FR-2.12)
 *
 * The catalogue is public: a visitor, a User and an Admin may all browse it
 * (SRS §14.3). An Admin may not shop, so the listing shows no Add to Cart
 * button for an Admin (FR-7.5).
 */

require_once dirname(__DIR__) . '/helpers/functions.php';
require_once dirname(__DIR__) . '/helpers/authorization.php';
require_once dirname(__DIR__) . '/models/Product.php';

class CatalogController
{
    /**
     * The product listing page (P-05).
     *
     * Reads the search term, the category filter and the page number from
     * the query string. The search term and the category are combined with
     * AND, and both are preserved in the page links (FR-2.4, FR-2.10).
     */
    public function listing(): void
    {
        // FR-2.5: the search term is trimmed before it is matched.
        $search = get_string('search');
        if ($search === '') {
            $search = null;
        }

        // FR-2.4: the category filter. A category id that does not exist is
        // treated as no filter, rather than as an error.
        $categoryId = null;
        $requestedCategory = get_string('category');
        if ($requestedCategory !== '' && ctype_digit($requestedCategory)) {
            $candidate = (int) $requestedCategory;
            if ($candidate > 0 && Product::findCategoryById($candidate) !== null) {
                $categoryId = $candidate;
            }
        }

        $totalResults = Product::countFiltered($categoryId, $search);
        $lastPage = Product::pageCount($totalResults);

        // FR-2.10: an invalid page number falls back to a valid page.
        $page = Product::normalisePage($_GET['page'] ?? '', $lastPage);

        $products = Product::paginate($page, $categoryId, $search);
        $categories = Product::categories();

        $selectedCategory = null;
        if ($categoryId !== null) {
            $selectedCategory = Product::findCategoryById($categoryId);
        }

        // The query string carried by the pagination links.
        $query = array();
        if ($search !== null) {
            $query['search'] = $search;
        }
        if ($categoryId !== null) {
            $query['category'] = (string) $categoryId;
        }

        require __DIR__ . '/../views/catalog/listing.php';
    }

    /**
     * The product detail page (P-06).
     *
     * A product that does not exist, or that has been soft-deleted, is
     * answered with HTTP 404 and the "no longer available" wording
     * (FR-2.8, FR-2.13).
     *
     * @param array<string, mixed> $parameters The URL parameters. 'id' holds
     *                                         the product id.
     */
    public function detail(array $parameters = array()): void
    {
        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        $product = $id > 0 ? Product::findActiveById($id) : null;

        if ($product === null) {
            // FR-2.8: the same page and status serve the "Product no longer
            // available" response, with the wording adapted.
            $pageTitle = 'Product no longer available';
            $message = 'This product is no longer available. Browse the catalogue to find something else.';

            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        // The products in the same category, for the "more from this
        // category" links.
        $related = Product::paginate(1, (int) $product['category_id'], null, 4);
        $related = array_values(array_filter(
            $related,
            static function (array $item) use ($product): bool {
                return (int) $item['id'] !== (int) $product['id'];
            }
        ));

        require __DIR__ . '/../views/catalog/detail.php';
    }
}
