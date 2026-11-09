<?php
/**
 * GreenCorner — Pagination partial.
 *
 * The Previous / numbered / Next controls of the product listing
 * (FR-2.10).
 *
 * The active search term and the selected category are preserved in every
 * link, so moving between pages keeps the same result set (FR-2.10
 * acceptance criterion 3).
 *
 * Expects:
 *   $page        int              The current page number.
 *   $lastPage    int              The last valid page number.
 *   $query       array<string,string>  The query parameters to preserve.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

$page = (int) ($page ?? 1);
$lastPage = max(1, (int) ($lastPage ?? 1));
$query = $query ?? array();

/**
 * Build the URL of one page, preserving the active filters.
 *
 * @param int $pageNumber
 * @return string
 */
function pagination_url(int $pageNumber): string
{
    global $query;

    $parameters = $query;
    $parameters['page'] = (string) $pageNumber;

    return APP_URL . '/products?' . http_build_query($parameters);
}

// The numbers to show around the current page. The first and the last page
// are always shown, so the visitor can always reach the end of the list.
$window = 2;
$start = max(1, $page - $window);
$end = min($lastPage, $page + $window);
?>
<?php if ($lastPage > 1): ?>
    <nav class="pagination" aria-label="Catalogue pages">
        <?php if ($page > 1): ?>
            <a class="pagination-step" href="<?= e(pagination_url($page - 1)) ?>" rel="prev">
                &larr; Previous
            </a>
        <?php else: ?>
            <span class="pagination-step is-disabled" aria-disabled="true">&larr; Previous</span>
        <?php endif; ?>

        <ol class="pagination-numbers">
            <?php if ($start > 1): ?>
                <li>
                    <a href="<?= e(pagination_url(1)) ?>">1</a>
                </li>
                <?php if ($start > 2): ?>
                    <li class="pagination-gap" aria-hidden="true">&hellip;</li>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($number = $start; $number <= $end; $number++): ?>
                <li>
                    <?php if ($number === $page): ?>
                        <span class="is-current" aria-current="page"><?= e((string) $number) ?></span>
                    <?php else: ?>
                        <a href="<?= e(pagination_url($number)) ?>"><?= e((string) $number) ?></a>
                    <?php endif; ?>
                </li>
            <?php endfor; ?>

            <?php if ($end < $lastPage): ?>
                <?php if ($end < $lastPage - 1): ?>
                    <li class="pagination-gap" aria-hidden="true">&hellip;</li>
                <?php endif; ?>
                <li>
                    <a href="<?= e(pagination_url($lastPage)) ?>"><?= e((string) $lastPage) ?></a>
                </li>
            <?php endif; ?>
        </ol>

        <?php if ($page < $lastPage): ?>
            <a class="pagination-step" href="<?= e(pagination_url($page + 1)) ?>" rel="next">
                Next &rarr;
            </a>
        <?php else: ?>
            <span class="pagination-step is-disabled" aria-disabled="true">Next &rarr;</span>
        <?php endif; ?>
    </nav>
<?php endif; ?>
