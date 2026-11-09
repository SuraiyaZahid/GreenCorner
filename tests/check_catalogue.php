<?php
/**
 * GreenCorner — Catalogue behaviour check.
 *
 * Exercises the listing page over HTTP and prints what it found. Used while
 * building Phase 2 and as a quick manual check of the catalogue.
 *
 * Usage:
 *   php tests/check_catalogue.php [base-url]
 */

require_once __DIR__ . '/HttpClient.php';

$base = $argv[1] ?? 'http://127.0.0.1:8010';
$client = new HttpClient($base);

/**
 * The product names shown on a page.
 */
function product_names(string $body): array
{
    preg_match_all('/class="product-name">([^<]+)</', $body, $matches);

    return array_map('trim', $matches[1]);
}

/**
 * The result-count line.
 */
function result_line(string $body): string
{
    if (preg_match('/<p class="result-count">\s*(.*?)\s*<\/p>/s', $body, $m)) {
        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    return '(not found)';
}

echo 'Base URL: ' . $base . "\n\n";

$checks = array(
    'page 1'          => '/products',
    'page 2'          => '/products?page=2',
    'page 3'          => '/products?page=3',
    'page invalid'    => '/products?page=abc',
    'page zero'       => '/products?page=0',
    'page negative'   => '/products?page=-3',
    'page too high'   => '/products?page=9999',
    'search fern'     => '/products?search=fern',
    'search FERN'     => '/products?search=FERN',
    'search pot large'=> '/products?search=pot%20large',
    'search pot ceram'=> '/products?search=pot%20ceramic',
    'search nomatch'  => '/products?search=zzzznotathing',
);

foreach ($checks as $label => $path) {
    $response = $client->get($path);
    $names = product_names($response['body']);

    echo str_pad($label, 18) . 'status=' . $response['status']
        . ' cards=' . count($names)
        . '  ' . result_line($response['body']) . "\n";

    if ($names !== array() && count($names) <= 6) {
        echo '                   -> ' . implode(', ', $names) . "\n";
    }
}

echo "\n--- category filter ---\n";

$response = $client->get('/products');
preg_match_all('/category=(\d+)[^"]*"[^>]*>\s*([^<]+)/', $response['body'], $matches, PREG_SET_ORDER);

foreach ($matches as $match) {
    $id = $match[1];
    $name = trim($match[2]);
    $filtered = $client->get('/products?category=' . $id);
    echo str_pad($name, 18) . 'cards=' . count(product_names($filtered['body']))
        . '  ' . result_line($filtered['body']) . "\n";
}
