<?php
/**
 * GreenCorner — Admin pagination partial.
 *
 * The Previous / numbered / Next controls of an Admin list.
 *
 * Expects:
 *   $page      int                  The current page number.
 *   $lastPage  int                  The last valid page number.
 *   $query     array<string,string> The query parameters to preserve.
 *   $basePath  string               The path of the list being paginated,
 *                                  for example '/admin/products'. It
 *                                  defaults to the Admin product list, so
 *                                  the existing behaviour is unchanged.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

$page = (int) ($page ?? 1);
$lastPage = max(1, (int) ($lastPage ?? 1));
$query = $query ?? array();
$basePath = $basePath ?? '/admin/products';

/**
 * Build the URL of one page of the Admin list being paginated.
 *
 * @param int $pageNumber
 * @return string
 */
function admin_pagination_url(int $pageNumber): string
{
    global $query, $basePath;

    $parameters = $query;
    $parameters['page'] = (string) $pageNumber;

    return APP_URL . $basePath . '?' . http_build_query($parameters);
}

$window = 2;
$start = max(1, $page - $window);
$end = min($lastPage, $page + $window);
?>
<nav class="pagination" aria-label="Admin list pages">
    <?php if ($page > 1): ?>
        <a class="pagination-step" href="<?= e(admin_pagination_url($page - 1)) ?>" rel="prev">
            &larr; Previous
        </a>
    <?php else: ?>
        <span class="pagination-step is-disabled" aria-disabled="true">&larr; Previous</span>
    <?php endif; ?>

    <ol class="pagination-numbers">
        <?php if ($start > 1): ?>
            <li>
                <a href="<?= e(admin_pagination_url(1)) ?>">1</a>
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
                    <a href="<?= e(admin_pagination_url($number)) ?>"><?= e((string) $number) ?></a>
                <?php endif; ?>
            </li>
        <?php endfor; ?>

        <?php if ($end < $lastPage): ?>
            <?php if ($end < $lastPage - 1): ?>
                <li class="pagination-gap" aria-hidden="true">&hellip;</li>
            <?php endif; ?>
            <li>
                <a href="<?= e(admin_pagination_url($lastPage)) ?>"><?= e((string) $lastPage) ?></a>
            </li>
        <?php endif; ?>
    </ol>

    <?php if ($page < $lastPage): ?>
        <a class="pagination-step" href="<?= e(admin_pagination_url($page + 1)) ?>" rel="next">
            Next &rarr;
        </a>
    <?php else: ?>
        <span class="pagination-step is-disabled" aria-disabled="true">Next &rarr;</span>
    <?php endif; ?>
</nav>
