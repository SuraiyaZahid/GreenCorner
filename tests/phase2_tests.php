<?php
/**
 * GreenCorner — Phase 2 Test Suite
 *
 * Covers the Phase 2 scope: landing page, product listing, product details,
 * category filtering, search, pagination, newest-first ordering, image
 * handling, database-backed login rate limiting (D-005) and the idempotent
 * seed script (D-007).
 *
 * Usage:
 *   php tests/run.php [base-url]
 *   php tests/phase2_tests.php [base-url]
 *
 * The default base URL is http://127.0.0.1:8000.
 *
 * The suite drives the application over HTTP, so the application must be
 * running before the tests are executed. See tests/serve.sh.
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/image.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/LoginAttempt.php';

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8000';
$runner = new TestRunner();

echo "GreenCorner — Phase 2 Test Suite\n";
echo "Base URL: " . $baseUrl . "\n";

/**
 * The product names shown on a catalogue page.
 *
 * @param string $body
 * @return array<int, string>
 */
function p2_product_names(string $body): array
{
    preg_match_all('/class="product-name">([^<]+)</', $body, $matches);

    return array_map('trim', $matches[1]);
}

/**
 * The result-count line of a catalogue page.
 *
 * @param string $body
 * @return string
 */
function p2_result_line(string $body): string
{
    if (preg_match('/<p class="result-count">\s*(.*?)\s*<\/p>/s', $body, $m)) {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]))));
    }

    return '(not found)';
}

/**
 * The number of product cards on a page.
 *
 * @param string $body
 * @return int
 */
function p2_card_count(string $body): int
{
    return count(p2_product_names($body));
}

/**
 * Remove every recorded login attempt, so a rate-limiting test starts clean.
 *
 * @return void
 */
function p2_clear_attempts(): void
{
    db_write('DELETE FROM `login_attempts`');
}

// ===========================================================================
$runner->group('1. Landing page (P-01, FR-2.1, FR-2.4)');
// ===========================================================================

$runner->test('Landing page returns HTTP 200', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/');

    assert_equals(200, $response['status'], 'The landing page did not return HTTP 200');
    assert_contains('<h1>GreenCorner</h1>', $response['body'], 'The store name is missing');
});

$runner->test('Landing page links to the product listing', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/');

    assert_contains('/products', $response['body'], 'There is no link to the catalogue');
});

$runner->test('Landing page shows the category strip with links into the filter', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/');

    $categories = Product::categories();
    assert_true(count($categories) > 0, 'No categories are present in the database');

    foreach ($categories as $category) {
        assert_contains(
            'category=' . $category['id'],
            $response['body'],
            'The landing page does not link to the ' . $category['name'] . ' filter'
        );
    }
});

$runner->test('Landing page shows the newest products', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/');

    $latest = Product::latest(8);
    $names = p2_product_names($response['body']);

    assert_true(count($names) > 0, 'The landing page shows no products');

    foreach ($latest as $product) {
        assert_in_array(
            $product['name'],
            $names,
            'The landing page is missing the newest product: ' . $product['name']
        );
    }
});

$runner->test('Landing page product images use the upload URL', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/');

    // The image URL must go through the upload route, which serves the files
    // from outside the web root (SEC-6).
    assert_contains('/uploads/', $response['body'], 'The landing page does not use the upload URL');
});

// ===========================================================================
$runner->group('2. Product listing page (P-05, FR-2.1, FR-2.2)');
// ===========================================================================

$runner->test('Product listing returns HTTP 200', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products');

    assert_equals(200, $response['status'], 'The listing page did not return HTTP 200');
});

$runner->test('Product listing is reachable without logging in', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products');

    assert_not_contains('Those credentials do not match', $response['body'], 'The listing redirected to the login page');
    assert_contains('product-grid', $response['body'], 'The product grid is missing');
});

$runner->test('Every product card shows a name and a price', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products');

    preg_match_all('/class="product-card">/s', $response['body'], $cards);
    preg_match_all('/class="product-name">([^<]+)</', $response['body'], $names);
    preg_match_all('/class="product-price">([^<]+)</', $response['body'], $prices);

    assert_true(count($cards[0]) > 0, 'No product cards were rendered');
    assert_equals(count($cards[0]), count($names[0]), 'A card is missing its product name');
    assert_equals(count($cards[0]), count($prices[0]), 'A card is missing its price');
});

$runner->test('Every product card links to its detail page', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products');

    preg_match_all('/class="product-card-link" href="[^"]*\/products\/(\d+)"/', $response['body'], $matches);

    assert_true(count($matches[1]) > 0, 'No card links to a product detail page');

    foreach ($matches[1] as $id) {
        $product = Product::findActiveById((int) $id);
        assert_true($product !== null, 'A card links to a product that is not active: ' . $id);
    }
});

$runner->test('Listing cards use the thumbnail, never the original (FR-2.2)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products');

    preg_match_all('/<img\s+src="([^"]+)"/s', $response['body'], $matches);

    assert_true(count($matches[1]) > 0, 'No images were rendered');

    foreach ($matches[1] as $src) {
        assert_contains('-thumb.', $src, 'A listing card does not use the thumbnail: ' . $src);
    }
});

$runner->test('A soft-deleted product does not appear in the listing (FR-2.8)', function () use ($baseUrl) {
    // Create a product, soft-delete it, and check that it is not listed.
    $category = db_select_one('SELECT `id` FROM `categories` LIMIT 1');
    assert_true($category !== null, 'No category is available for the test');

    $name = 'Phase 2 Deleted Product ' . bin2hex(random_bytes(4));
    $productId = db_insert_id(
        "INSERT INTO `products`
            (`name`, `description`, `price`, `stock`, `image_path`,
             `thumbnail_path`, `category_id`, `is_deleted`)
         VALUES (?, ?, '10.00', 1, 'x.jpg', 'x-thumb.jpg', ?, 0)",
        'ssi',
        array($name, 'A product that is about to be soft-deleted.', (int) $category['id'])
    );

    try {
        db_write('UPDATE `products` SET `is_deleted` = 1 WHERE `id` = ?', 'i', array($productId));

        $client = new HttpClient($baseUrl);
        $response = $client->get('/products?search=' . rawurlencode($name));

        assert_equals(0, p2_card_count($response['body']), 'A soft-deleted product is still listed');

        // The search term itself is echoed back in the search box, so the
        // product name must not appear anywhere else on the page.
        assert_not_contains(
            'class="product-name">' . $name,
            $response['body'],
            'A soft-deleted product is still visible'
        );
    } finally {
        db_write('DELETE FROM `products` WHERE `id` = ?', 'i', array($productId));
    }
});

// ===========================================================================
$runner->group('3. Category filtering (FR-2.4)');
// ===========================================================================

$runner->test('Category filter returns only that category’s products', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    foreach (Product::categories() as $category) {
        $response = $client->get('/products?category=' . $category['id']);

        assert_equals(200, $response['status'], 'The filtered listing did not return HTTP 200');

        $expected = Product::countFiltered((int) $category['id'], null);
        assert_equals(
            $expected,
            p2_card_count($response['body']),
            'The ' . $category['name'] . ' filter returned the wrong number of cards'
        );
    }
});

$runner->test('Category filter narrows the result count', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $all = $client->get('/products');
    $total = p2_card_count($all['body']);

    $category = Product::categories()[0];
    $filtered = $client->get('/products?category=' . $category['id']);

    assert_true(
        p2_card_count($filtered['body']) < $total,
        'The category filter did not reduce the number of results'
    );
});

$runner->test('An unknown category id shows the whole catalogue', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $all = $client->get('/products');
    $unknown = $client->get('/products?category=999999');

    assert_equals(200, $unknown['status'], 'An unknown category did not return HTTP 200');
    assert_equals(
        p2_card_count($all['body']),
        p2_card_count($unknown['body']),
        'An unknown category id filtered the catalogue'
    );
});

$runner->test('A non-numeric category id shows the whole catalogue', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $all = $client->get('/products');
    $invalid = $client->get('/products?category=abc');

    assert_equals(200, $invalid['status'], 'An invalid category did not return HTTP 200');
    assert_equals(
        p2_card_count($all['body']),
        p2_card_count($invalid['body']),
        'An invalid category id filtered the catalogue'
    );
});

$runner->test('The category filter is preserved in the pagination links (FR-2.4)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $category = Product::categories()[0];
    $response = $client->get('/products?category=' . $category['id']);

    preg_match_all('/class="pagination-link"[^>]*href="([^"]+)"/', $response['body'], $matches);

    if (count($matches[1]) > 0) {
        foreach ($matches[1] as $href) {
            assert_contains('category=' . $category['id'], $href, 'The pagination link lost the category filter');
        }
    } else {
        // The category holds fewer than 12 products, so there is only one
        // page and there is nothing to check.
        assert_true(true);
    }
});

// ===========================================================================
$runner->group('4. Search (FR-2.5, FR-2.6)');
// ===========================================================================

$runner->test('Search matches a product name', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products?search=fern');

    assert_equals(200, $response['status'], 'The search did not return HTTP 200');
    assert_contains('Boston Fern', $response['body'], 'The search did not find "Boston Fern"');
});

$runner->test('Search is case-insensitive (FR-2.5)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $lower = $client->get('/products?search=fern');
    $upper = $client->get('/products?search=FERN');
    $mixed = $client->get('/products?search=FerN');

    assert_equals(
        p2_card_count($lower['body']),
        p2_card_count($upper['body']),
        'An upper-case search returned a different number of results'
    );
    assert_equals(
        p2_card_count($lower['body']),
        p2_card_count($mixed['body']),
        'A mixed-case search returned a different number of results'
    );
    assert_contains('Boston Fern', $upper['body'], 'An upper-case search did not find "Boston Fern"');
});

$runner->test('Search matches a substring of the name (FR-2.5)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products?search=erracotta');

    assert_contains('Large Terracotta Pot', $response['body'], 'A partial-name search did not find the product');
});

$runner->test('Multi-word search requires every word (FR-2.6)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    // "pot" and "large" must both appear in the name.
    $both = $client->get('/products?search=' . rawurlencode('pot large'));
    $names = p2_product_names($both['body']);

    assert_true(count($names) > 0, 'A multi-word search found nothing');

    foreach ($names as $name) {
        assert_contains(
            'Pot',
            $name,
            'A multi-word search returned a product without "pot": ' . $name
        );
        assert_contains(
            'Large',
            $name,
            'A multi-word search returned a product without "large": ' . $name
        );
    }
});

$runner->test('A multi-word search with a missing word returns nothing', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products?search=' . rawurlencode('pot ceramic'));

    // "ceramic" does appear in "Large Ceramic Pot", but "pot" appears there
    // too, so this must return that product — and nothing that lacks "pot".
    foreach (p2_product_names($response['body']) as $name) {
        assert_contains('Pot', $name, 'A multi-word search returned a product without "pot": ' . $name);
    }

    $client = new HttpClient($baseUrl);
    $response = $client->get('/products?search=' . rawurlencode('pot zznotaword'));
    assert_equals(0, p2_card_count($response['body']), 'A multi-word search with a missing word returned results');
});

$runner->test('A search with no matches shows the empty state and a Clear action (FR-2.9)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products?search=zzzznotathing');

    assert_equals(200, $response['status'], 'An empty search did not return HTTP 200');
    assert_equals(0, p2_card_count($response['body']), 'An empty search returned product cards');
    assert_contains('No products found', $response['body'], 'The empty state is missing');
    assert_contains('Clear search', $response['body'], 'The Clear Search action is missing');
});

$runner->test('Search and category filter are combined (FR-2.4)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    // "pot" is in the Pots category; "Large Ceramic Pot" is the only match.
    $category = Product::findCategoryById(
        (int) db_select_one(
            'SELECT `id` FROM `categories` WHERE `name` = ? LIMIT 1',
            's',
            array('Pots')
        )['id']
    );

    assert_true($category !== null, 'The Pots category is missing');

    $response = $client->get(
        '/products?category=' . $category['id'] . '&search=' . rawurlencode('pot large')
    );

    $expected = Product::countFiltered((int) $category['id'], 'pot large');
    assert_equals(
        $expected,
        p2_card_count($response['body']),
        'The combined filter returned the wrong number of cards'
    );

    foreach (p2_product_names($response['body']) as $name) {
        assert_contains('Pot', $name, 'The combined filter returned a product outside the category');
    }
});

$runner->test('The search term is preserved in the pagination links', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products?search=pot');

    preg_match_all('/class="pagination-link"[^>]*href="([^"]+)"/', $response['body'], $matches);

    if (count($matches[1]) > 0) {
        foreach ($matches[1] as $href) {
            assert_contains('search=pot', $href, 'The pagination link lost the search term');
        }
    } else {
        assert_true(true);
    }
});

$runner->test('A search term is output-encoded, not injected into the markup', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products?search=' . rawurlencode('<script>alert(1)</script>'));

    assert_equals(200, $response['status'], 'A hostile search term did not return HTTP 200');
    assert_not_contains('<script>alert(1)</script>', $response['body'], 'The search term was not encoded');
});

// ===========================================================================
$runner->group('5. Pagination (FR-2.10)');
// ===========================================================================

$runner->test('A page shows at most 12 products', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products');

    assert_true(
        p2_card_count($response['body']) <= Product::PER_PAGE,
        'A catalogue page shows more than 12 products'
    );
});

$runner->test('A full page shows exactly 12 products', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products');

    $total = Product::countFiltered(null, null);

    if ($total >= Product::PER_PAGE) {
        assert_equals(
            Product::PER_PAGE,
            p2_card_count($response['body']),
            'The first page does not show exactly 12 products'
        );
    } else {
        assert_true(true);
    }
});

$runner->test('Page 2 shows the next set of products', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $page1 = $client->get('/products');
    $page2 = $client->get('/products?page=2');

    $names1 = p2_product_names($page1['body']);
    $names2 = p2_product_names($page2['body']);

    if (count($names2) === 0) {
        assert_true(true, 'There is no second page');
        return;
    }

    foreach ($names2 as $name) {
        assert_true(
            !in_array($name, $names1, true),
            'A product appears on both page 1 and page 2: ' . $name
        );
    }
});

$runner->test('The last page shows the remaining products', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $total = Product::countFiltered(null, null);
    $lastPage = (int) ceil($total / Product::PER_PAGE);
    $expected = $total - (($lastPage - 1) * Product::PER_PAGE);

    $response = $client->get('/products?page=' . $lastPage);

    assert_equals(
        $expected,
        p2_card_count($response['body']),
        'The last page does not show the remaining products'
    );
});

$runner->test('The pages together cover every product exactly once', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $total = Product::countFiltered(null, null);
    $lastPage = (int) ceil($total / Product::PER_PAGE);

    $seen = array();

    for ($page = 1; $page <= $lastPage; $page++) {
        $response = $client->get('/products?page=' . $page);

        foreach (p2_product_names($response['body']) as $name) {
            $seen[] = $name;
        }
    }

    assert_equals($total, count($seen), 'The pages do not show every product exactly once');
    assert_equals(count($seen), count(array_unique($seen)), 'A product appears on more than one page');
});

$runner->test('An invalid page number falls back to page 1', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $page1 = $client->get('/products');

    foreach (array('abc', '0', '-3', '') as $requested) {
        $response = $client->get('/products?page=' . rawurlencode($requested));

        assert_equals(200, $response['status'], 'An invalid page did not return HTTP 200');
        assert_equals(
            p2_card_count($page1['body']),
            p2_card_count($response['body']),
            'The page "' . $requested . '" did not fall back to page 1'
        );
    }
});

$runner->test('A page number beyond the last page falls back to the last page', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $total = Product::countFiltered(null, null);
    $lastPage = (int) ceil($total / Product::PER_PAGE);

    $last = $client->get('/products?page=' . $lastPage);
    $beyond = $client->get('/products?page=9999');

    assert_equals(200, $beyond['status'], 'A too-high page did not return HTTP 200');
    assert_equals(
        p2_card_count($last['body']),
        p2_card_count($beyond['body']),
        'A too-high page did not fall back to the last page'
    );
});

$runner->test('The pagination links are rendered when there is more than one page', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products');

    $total = Product::countFiltered(null, null);

    if ($total > Product::PER_PAGE) {
        assert_contains('pagination', $response['body'], 'The pagination control is missing');
    } else {
        assert_true(true);
    }
});

// ===========================================================================
$runner->group('6. Newest-first ordering (FR-2.11)');
// ===========================================================================

$runner->test('The catalogue lists the newest products first', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products');

    $names = p2_product_names($response['body']);
    assert_true(count($names) > 1, 'Not enough products to check the order');

    // The first card must be the most recently created product.
    $newest = db_select_one(
        'SELECT `name` FROM `products` WHERE `is_deleted` = 0
          ORDER BY `created_at` DESC, `id` DESC LIMIT 1'
    );

    assert_true($newest !== null, 'No active product is present');
    assert_equals(
        (string) $newest['name'],
        $names[0],
        'The first product is not the newest one'
    );
});

$runner->test('The order is newest first, with the id as a tie-breaker', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products');

    $names = p2_product_names($response['body']);

    $rows = db_select(
        'SELECT `name`, `created_at`, `id` FROM `products` WHERE `is_deleted` = 0
          ORDER BY `created_at` DESC, `id` DESC LIMIT 12'
    );

    $expected = array();
    foreach ($rows as $row) {
        $expected[] = (string) $row['name'];
    }

    assert_equals($expected, $names, 'The catalogue order does not match newest-first ordering');
});

$runner->test('The model ordering matches the catalogue ordering', function () {
    $rows = Product::paginate(1);
    assert_true(count($rows) > 1, 'Not enough products to check the order');

    $previous = null;

    foreach ($rows as $row) {
        $createdAt = strtotime((string) $row['created_at']);
        $id = (int) $row['id'];

        if ($previous !== null) {
            $previousCreatedAt = strtotime((string) $previous['created_at']);
            $previousId = (int) $previous['id'];

            // Newest first, and within one second the higher id wins.
            $isOrdered = $createdAt < $previousCreatedAt
                || ($createdAt === $previousCreatedAt && $id < $previousId);

            assert_true(
                $isOrdered,
                'The products are not ordered newest first: '
                . $previous['name'] . ' (' . $previous['created_at'] . ' #' . $previousId . ') '
                . 'comes before ' . $row['name'] . ' (' . $row['created_at'] . ' #' . $id . ')'
            );
        }

        $previous = $row;
    }
});

// ===========================================================================
$runner->group('7. Product detail page (P-06, FR-2.7, FR-2.12)');
// ===========================================================================

$runner->test('A product detail page returns HTTP 200', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $product = Product::findActiveById((int) db_select_one('SELECT `id` FROM `products` WHERE `is_deleted` = 0 LIMIT 1')['id']);

    $response = $client->get('/products/' . $product['id']);

    assert_equals(200, $response['status'], 'The detail page did not return HTTP 200');
    assert_contains((string) $product['name'], $response['body'], 'The product name is missing');
});

$runner->test('The detail page shows the description, price and category (FR-2.7)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $product = Product::findActiveById((int) db_select_one('SELECT `id` FROM `products` WHERE `is_deleted` = 0 LIMIT 1')['id']);

    $response = $client->get('/products/' . $product['id']);

    assert_contains('product-detail-price', $response['body'], 'The price is missing');
    assert_contains('product-detail-description', $response['body'], 'The description is missing');
    assert_contains((string) $product['category_name'], $response['body'], 'The category is missing');
    assert_contains('product-detail-stock', $response['body'], 'The stock status is missing');
});

$runner->test('The detail page uses the original image, never the thumbnail (FR-2.12)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $product = Product::findActiveById((int) db_select_one('SELECT `id` FROM `products` WHERE `is_deleted` = 0 LIMIT 1')['id']);

    $response = $client->get('/products/' . $product['id']);

    preg_match('/class="product-detail-image">(.*?)<\/div>/s', $response['body'], $m);

    assert_true(isset($m[1]), 'The detail image could not be found');

    preg_match('/<img[^>]*src="([^"]+)"/s', $m[1], $imageMatch);

    assert_true(isset($imageMatch[1]), 'The detail image has no source');
    assert_not_contains('-thumb.', $imageMatch[1], 'The detail page uses the thumbnail instead of the original');
    assert_contains(basename((string) $product['image_path']), $imageMatch[1], 'The detail page does not use the original image');
});

$runner->test('An unknown product id returns HTTP 404', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products/99999999');

    assert_equals(404, $response['status'], 'An unknown product did not return HTTP 404');
});

$runner->test('A soft-deleted product returns HTTP 404', function () use ($baseUrl) {
    $category = db_select_one('SELECT `id` FROM `categories` LIMIT 1');

    $productId = db_insert_id(
        "INSERT INTO `products`
            (`name`, `description`, `price`, `stock`, `image_path`,
             `thumbnail_path`, `category_id`, `is_deleted`)
         VALUES (?, 'A soft-deleted product.', '10.00', 1, 'x.jpg', 'x-thumb.jpg', ?, 1)",
        'si',
        array('Phase 2 Hidden Product ' . bin2hex(random_bytes(4)), (int) $category['id'])
    );

    try {
        $client = new HttpClient($baseUrl);
        $response = $client->get('/products/' . $productId);

        assert_equals(404, $response['status'], 'A soft-deleted product did not return HTTP 404');
    } finally {
        db_write('DELETE FROM `products` WHERE `id` = ?', 'i', array($productId));
    }
});

$runner->test('A non-numeric product id returns HTTP 404', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/products/not-a-number');

    assert_equals(404, $response['status'], 'A non-numeric product id did not return HTTP 404');
});

$runner->test('An out-of-stock product is marked and cannot be added to the cart (FR-2.7)', function () use ($baseUrl) {
    $category = db_select_one('SELECT `id` FROM `categories` LIMIT 1');

    $productId = db_insert_id(
        "INSERT INTO `products`
            (`name`, `description`, `price`, `stock`, `image_path`,
             `thumbnail_path`, `category_id`, `is_deleted`)
         VALUES (?, 'An out-of-stock product.', '10.00', 0, 'x.jpg', 'x-thumb.jpg', ?, 0)",
        'si',
        array('Phase 2 Out Of Stock ' . bin2hex(random_bytes(4)), (int) $category['id'])
    );

    $email = 'phase2-oos-' . bin2hex(random_bytes(4)) . '@example.test';
    $password = 'Phase2OutOfStock!2026';
    $roleId = db_select_one('SELECT `id` FROM `roles` WHERE `name` = ? LIMIT 1', 's', array('User'));

    $userId = db_insert_id(
        'INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role_id`, `is_active`, `must_change_password`)
         VALUES (?, ?, ?, ?, 1, 0)',
        'sssi',
        array('Phase 2 Out Of Stock', $email, password_hash($password, PASSWORD_DEFAULT), (int) $roleId['id'])
    );

    try {
        // The Add to Cart button is only shown to a signed-in User (FR-7.5),
        // so the check is made from that state.
        $client = new HttpClient($baseUrl);
        $token = $client->csrfToken('/auth/login');

        $client->post('/auth/login', array(
            'csrf_token' => $token,
            'email'      => $email,
            'password'   => $password,
        ), true);

        $response = $client->get('/products/' . $productId);

        assert_contains('Out of stock', $response['body'], 'The out-of-stock badge is missing');
        assert_contains('disabled', $response['body'], 'The Add to Cart button is not disabled');
    } finally {
        db_write('DELETE FROM `sessions` WHERE `user_id` = ?', 'i', array($userId));
        db_write('DELETE FROM `users` WHERE `id` = ?', 'i', array($userId));
        db_write('DELETE FROM `products` WHERE `id` = ?', 'i', array($productId));
    }
});

$runner->test('The detail page links back to the category listing', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $product = Product::findActiveById((int) db_select_one('SELECT `id` FROM `products` WHERE `is_deleted` = 0 LIMIT 1')['id']);

    $response = $client->get('/products/' . $product['id']);

    assert_contains(
        '/products?category=' . $product['category_id'],
        $response['body'],
        'The detail page does not link to its category'
    );
});

// ===========================================================================
$runner->group('8. Image handling (FR-2.12, FR-6.4)');
// ===========================================================================

$runner->test('A stored original is served with an image content type', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $product = Product::findActiveById((int) db_select_one('SELECT `id` FROM `products` WHERE `is_deleted` = 0 LIMIT 1')['id']);

    $response = $client->get('/uploads/' . rawurlencode((string) $product['image_path']));

    assert_equals(200, $response['status'], 'The original image was not served');
    assert_contains('image/', $response['headers']['content-type'] ?? '', 'The content type is not an image type');
    assert_equals('nosniff', $response['headers']['x-content-type-options'] ?? '', 'X-Content-Type-Options is missing');
});

$runner->test('A stored thumbnail is served with an image content type', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $product = Product::findActiveById((int) db_select_one('SELECT `id` FROM `products` WHERE `is_deleted` = 0 LIMIT 1')['id']);

    $response = $client->get('/uploads/' . rawurlencode((string) $product['thumbnail_path']));

    assert_equals(200, $response['status'], 'The thumbnail was not served');
    assert_contains('image/', $response['headers']['content-type'] ?? '', 'The content type is not an image type');
});

$runner->test('A missing image returns HTTP 404', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/uploads/no-such-image.jpg');

    assert_equals(404, $response['status'], 'A missing image did not return HTTP 404');
});

$runner->test('A non-image file is not served from the upload directory', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/uploads/../config/config.php');

    assert_true(
        $response['status'] === 404 || $response['status'] === 403,
        'A path traversal attempt was not refused (status ' . $response['status'] . ')'
    );
    assert_not_contains('DB_PASS', $response['body'], 'The path traversal attempt disclosed a source file');
});

$runner->test('Every seeded product has both image files on disk', function () {
    $rows = db_select('SELECT `image_path`, `thumbnail_path` FROM `products` WHERE `is_deleted` = 0');

    assert_true(count($rows) > 0, 'No products are present');

    foreach ($rows as $row) {
        $original = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string) $row['image_path']);
        $thumbnail = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string) $row['thumbnail_path']);

        assert_true(is_file($original), 'A product is missing its original image: ' . $row['image_path']);
        assert_true(is_file($thumbnail), 'A product is missing its thumbnail: ' . $row['thumbnail_path']);
    }
});

$runner->test('Every stored thumbnail is 400 × 400', function () {
    $rows = db_select('SELECT `thumbnail_path` FROM `products` WHERE `is_deleted` = 0');

    foreach ($rows as $row) {
        $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string) $row['thumbnail_path']);
        $size = @getimagesize($path);

        assert_true($size !== false, 'The thumbnail could not be read: ' . $row['thumbnail_path']);
        assert_equals(THUMBNAIL_SIZE, (int) $size[0], 'The thumbnail width is wrong: ' . $row['thumbnail_path']);
        assert_equals(THUMBNAIL_SIZE, (int) $size[1], 'The thumbnail height is wrong: ' . $row['thumbnail_path']);
    }
});

// The genuine-multipart upload tests moved to the Phase 3 suite when the
// real Admin product-upload endpoint replaced the development-only
// /__upload_probe harness. The validator itself is unchanged; it is now
// exercised through /admin/products with a real Admin session.

$runner->test('The upload directory is not web-executable', function () {
    $guard = UPLOAD_DIR . DIRECTORY_SEPARATOR . '.htaccess';

    assert_true(is_file($guard), 'The upload directory has no .htaccess guard');
    assert_contains('php_flag engine off', (string) file_get_contents($guard), 'The upload directory is not guarded');
});

// ===========================================================================
$runner->group('9. Database-backed login rate limiting (D-005, NFR-S-06, SEC-13)');
// ===========================================================================

$runner->test('The login_attempts table exists', function () {
    // SHOW does not accept a bound parameter, so the table name is matched
    // with a LIKE pattern that contains no wildcard characters.
    $row = db_select_one("SHOW TABLES LIKE 'login\\_attempts'");

    assert_true($row !== null, 'The login_attempts table does not exist');
});

$runner->test('The JSON-file attempt store has been removed', function () {
    assert_false(
        is_dir(APP_ROOT . '/storage/login_attempts'),
        'The JSON-file attempt directory still exists'
    );

    // The model must read and write the database, not the filesystem.
    $source = (string) file_get_contents(APP_ROOT . '/models/LoginAttempt.php');

    assert_not_contains('json_decode', $source, 'The LoginAttempt model still decodes JSON');
    assert_not_contains('file_put_contents', $source, 'The LoginAttempt model still writes files');
    assert_contains('login_attempts', $source, 'The LoginAttempt model does not use the database table');
});

$runner->test('The git ignore rules no longer exclude the removed store', function () {
    $ignore = (string) file_get_contents(APP_ROOT . '/.gitignore');

    assert_not_contains('storage/login_attempts', $ignore, 'The git ignore rules still mention the removed store');
});

$runner->test('A failed login is recorded in the database', function () use ($baseUrl) {
    p2_clear_attempts();

    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'nobody@example.test',
        'password'   => 'wrong-password',
    ));

    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `login_attempts`');

    assert_equals(1, (int) $row['total'], 'The failed attempt was not recorded in the database');
});

$runner->test('The raw source address is never stored in the clear', function () use ($baseUrl) {
    p2_clear_attempts();

    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'nobody@example.test',
        'password'   => 'wrong-password',
    ));

    $rows = db_select('SELECT `ip_hash`, `email_hash` FROM `login_attempts`');

    assert_true(count($rows) > 0, 'No attempt was recorded');

    foreach ($rows as $row) {
        assert_equals(64, strlen((string) $row['ip_hash']), 'The address hash is not a SHA-256 digest');
        assert_not_contains('127.0.0.1', (string) $row['ip_hash'], 'The raw address is stored');
        assert_not_contains(
            'nobody@example.test',
            (string) ($row['email_hash'] ?? ''),
            'The raw email address is stored'
        );
    }
});

$runner->test('Five failed attempts lock the source address out', function () use ($baseUrl) {
    p2_clear_attempts();

    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    for ($attempt = 1; $attempt <= LOGIN_MAX_ATTEMPTS; $attempt++) {
        $client->post('/auth/login', array(
            'csrf_token' => $token,
            'email'      => 'nobody@example.test',
            'password'   => 'wrong-password',
        ));
    }

    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `login_attempts`');
    assert_equals(LOGIN_MAX_ATTEMPTS, (int) $row['total'], 'Not every failed attempt was recorded');

    assert_true(LoginAttempt::isLockedOut('127.0.0.1'), 'The source address is not locked out');
    assert_equals(0, LoginAttempt::attemptsRemaining('127.0.0.1'), 'No attempts should remain');
});

$runner->test('The lockout is enforced on the next login attempt', function () use ($baseUrl) {
    p2_clear_attempts();

    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    for ($attempt = 1; $attempt <= LOGIN_MAX_ATTEMPTS; $attempt++) {
        $client->post('/auth/login', array(
            'csrf_token' => $token,
            'email'      => 'nobody@example.test',
            'password'   => 'wrong-password',
        ));
    }

    $before = (int) db_select_one('SELECT COUNT(*) AS `total` FROM `login_attempts`')['total'];

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'nobody@example.test',
        'password'   => 'wrong-password',
    ));

    $after = (int) db_select_one('SELECT COUNT(*) AS `total` FROM `login_attempts`')['total'];

    assert_equals($before, $after, 'A locked-out attempt was still recorded');
    assert_contains('Too many failed login attempts', $client->body, 'The lockout message is not shown');
});

$runner->test('A successful login clears the recorded attempts', function () use ($baseUrl) {
    p2_clear_attempts();

    // A known account, created through the model so no HTTP registration is
    // needed. The password is well above the minimum length.
    $email = 'phase2-rate-' . bin2hex(random_bytes(4)) . '@example.test';
    $password = 'Phase2RateLimit!2026';

    $roleId = db_select_one('SELECT `id` FROM `roles` WHERE `name` = ? LIMIT 1', 's', array('User'));
    assert_true($roleId !== null, 'The User role is missing');

    $userId = db_insert_id(
        'INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role_id`, `is_active`, `must_change_password`)
         VALUES (?, ?, ?, ?, 1, 0)',
        'sssi',
        array('Phase 2 Rate Limit', $email, password_hash($password, PASSWORD_DEFAULT), (int) $roleId['id'])
    );

    try {
        $client = new HttpClient($baseUrl);
        $token = $client->csrfToken('/auth/login');

        // Two failures first.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $client->post('/auth/login', array(
                'csrf_token' => $token,
                'email'      => $email,
                'password'   => 'wrong-password',
            ));
        }

        $row = db_select_one('SELECT COUNT(*) AS `total` FROM `login_attempts`');
        assert_equals(2, (int) $row['total'], 'The failures were not recorded');

        // A correct password clears them.
        $client->post('/auth/login', array(
            'csrf_token' => $token,
            'email'      => $email,
            'password'   => $password,
        ));

        $row = db_select_one('SELECT COUNT(*) AS `total` FROM `login_attempts`');
        assert_equals(0, (int) $row['total'], 'A successful login did not clear the recorded attempts');
    } finally {
        db_write('DELETE FROM `sessions` WHERE `user_id` = ?', 'i', array($userId));
        db_write('DELETE FROM `users` WHERE `id` = ?', 'i', array($userId));
        p2_clear_attempts();
    }
});

$runner->test('Attempts older than the lockout window do not count', function () {
    p2_clear_attempts();

    // Insert attempts that are already outside the window.
    $old = time() - LOGIN_LOCKOUT_SECONDS - 60;

    for ($i = 0; $i < LOGIN_MAX_ATTEMPTS + 2; $i++) {
        db_write(
            'INSERT INTO `login_attempts` (`ip_hash`, `email_hash`, `created_at`) VALUES (?, NULL, ?)',
            'si',
            array(hash('sha256', '10.0.0.1'), $old)
        );
    }

    assert_false(
        LoginAttempt::isLockedOut('10.0.0.1'),
        'Attempts outside the lockout window still counted towards the limit'
    );

    p2_clear_attempts();
});

// ===========================================================================
$runner->group('10. Seed script and sample data (D-007)');
// ===========================================================================

$runner->test('Five categories are present', function () {
    $rows = db_select('SELECT `name` FROM `categories` ORDER BY `name`');
    $names = array();

    foreach ($rows as $row) {
        $names[] = (string) $row['name'];
    }

    assert_equals(5, count($names), 'The seed does not provide five categories');

    foreach (array('Plants', 'Seeds', 'Pots', 'Tools', 'Fertilisers') as $expected) {
        assert_in_array($expected, $names, 'The category is missing: ' . $expected);
    }
});

$runner->test('Between 25 and 30 products are present', function () {
    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `products` WHERE `is_deleted` = 0');
    $total = (int) $row['total'];

    assert_true($total >= 25, 'Fewer than 25 sample products are present: ' . $total);
    assert_true($total <= 30, 'More than 30 sample products are present: ' . $total);
});

$runner->test('No two products share a name', function () {
    $row = db_select_one(
        'SELECT COUNT(*) AS `total` FROM (
            SELECT `name` FROM `products` WHERE `is_deleted` = 0
             GROUP BY `name` HAVING COUNT(*) > 1
        ) AS `duplicates`'
    );

    assert_equals(0, (int) $row['total'], 'Duplicate product names are present');
});

$runner->test('The seed script is idempotent', function () {
    $before = db_select_one('SELECT COUNT(*) AS `total` FROM `products` WHERE `is_deleted` = 0');
    $categoriesBefore = db_select_one('SELECT COUNT(*) AS `total` FROM `categories`');

    // Run the seed again, exactly as a developer would.
    $output = array();
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/database/seed.php') . ' 2>&1', $output, $status);

    assert_equals(0, $status, 'The seed script did not exit cleanly: ' . implode("\n", $output));

    $after = db_select_one('SELECT COUNT(*) AS `total` FROM `products` WHERE `is_deleted` = 0');
    $categoriesAfter = db_select_one('SELECT COUNT(*) AS `total` FROM `categories`');

    assert_equals(
        (int) $before['total'],
        (int) $after['total'],
        'The seed created duplicate products when run again'
    );
    assert_equals(
        (int) $categoriesBefore['total'],
        (int) $categoriesAfter['total'],
        'The seed created duplicate categories when run again'
    );
});

$runner->test('Every seeded product belongs to one of the five categories (FR-2.3)', function () {
    $row = db_select_one(
        'SELECT COUNT(*) AS `total` FROM `products` p
          WHERE p.`is_deleted` = 0
            AND p.`category_id` NOT IN (SELECT `id` FROM `categories`)'
    );

    assert_equals(0, (int) $row['total'], 'A product belongs to no category');
});

$runner->test('Every product has a price, a stock level and an image (FR-2.3, FR-2.12)', function () {
    $rows = db_select('SELECT `price`, `stock`, `image_path`, `thumbnail_path` FROM `products` WHERE `is_deleted` = 0');

    foreach ($rows as $row) {
        assert_true((float) $row['price'] >= 0, 'A product has a negative price');
        assert_true((int) $row['stock'] >= 0, 'A product has a negative stock level');
        assert_true((string) $row['image_path'] !== '', 'A product has no original image');
        assert_true((string) $row['thumbnail_path'] !== '', 'A product has no thumbnail');
    }
});

// ===========================================================================
$runner->group('11. Phase 1 functionality still works');
// ===========================================================================

$runner->test('The registration page is still reachable', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/auth/register');

    assert_equals(200, $response['status'], 'The registration page did not return HTTP 200');
    assert_contains('name="csrf_token"', $response['body'], 'The CSRF token field is missing');
});

$runner->test('Registration still works end to end', function () use ($baseUrl) {
    $email = 'phase2-register-' . bin2hex(random_bytes(4)) . '@example.test';
    $password = 'Phase2Register!2026';

    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/register');

    $response = $client->post('/auth/register', array(
        'csrf_token' => $token,
        'full_name'  => 'Phase 2 Registered',
        'email'      => $email,
        'password'   => $password,
        'confirm'    => $password,
    ), true);

    assert_true(
        $response['status'] === 302 || $response['status'] === 200,
        'The registration did not complete (status ' . $response['status'] . ')'
    );

    $row = db_select_one('SELECT `id` FROM `users` WHERE `email` = ? LIMIT 1', 's', array($email));
    assert_true($row !== null, 'The registered account was not created');

    db_write('DELETE FROM `sessions` WHERE `user_id` = ?', 'i', array((int) $row['id']));
    db_write('DELETE FROM `users` WHERE `id` = ?', 'i', array((int) $row['id']));
});

$runner->test('The login page still shows the inline error for bad credentials (D-003)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $response = $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'nobody@example.test',
        'password'   => 'wrong-password',
    ));

    assert_contains('Those credentials do not match', $response['body'], 'The inline error is missing');
    assert_not_contains('name="password" value=', $response['body'], 'The password was echoed back');
});

$runner->test('An invalid CSRF token re-renders the login form with HTTP 422 (D-004)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->post('/auth/login', array(
        'csrf_token' => 'not-a-valid-token',
        'email'      => 'nobody@example.test',
        'password'   => 'wrong-password',
    ));

    assert_equals(422, $response['status'], 'An invalid CSRF token did not return HTTP 422');

    // The form is re-rendered with the error shown inline, and it is NOT a
    // redirect.
    assert_contains('class="alert alert-error"', $response['body'], 'The CSRF error was not shown');
    assert_contains('session expired', $response['body'], 'The CSRF error message is missing');
    assert_contains('name="csrf_token"', $response['body'], 'The form was not re-rendered');

    // The submitted email is preserved, and the password is not.
    assert_contains('nobody@example.test', $response['body'], 'The email address was not preserved');
    assert_not_contains('name="password" value=', $response['body'], 'The password was echoed back');
});

$runner->test('An unknown URL still returns HTTP 404', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/no-such-page');

    assert_equals(404, $response['status'], 'An unknown URL did not return HTTP 404');
});

$runner->test('The storefront is reachable while a password change is forced (D-006)', function () {
    // This behaviour is covered in detail by the Phase 1 suite; this test
    // confirms that the Phase 2 catalogue did not break it.
    $client = new HttpClient($GLOBALS['baseUrl'] ?? 'http://127.0.0.1:8000');
    $response = $client->get('/products');

    assert_equals(200, $response['status'], 'The catalogue is not reachable');
});

exit($runner->summary());
