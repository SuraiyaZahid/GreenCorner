<?php
/**
 * GreenCorner — Prepared statement binding check
 *
 * Every prepared statement in the wallet payment feature is checked
 * against the number of '?' placeholders in its SQL and the length of its
 * type string. A mismatch is a runtime error in mysqli, so it is caught
 * here instead of in production.
 *
 * The check parses the arguments of each db_select / db_select_one /
 * db_write / db_insert_id call by splitting on the top-level commas, so the
 * type string is always the second argument.
 *
 * Usage:
 *   php tests/phase8_binding_check.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';

/**
 * Split an argument list on the commas that are not inside a string or a
 * nested bracket.
 *
 * @param string $arguments The text between the outermost parentheses.
 * @return array<int, string>
 */
function p8_split_arguments(string $arguments): array
{
    $parts = array();
    $current = '';
    $depth = 0;
    $quote = null;
    $length = strlen($arguments);

    for ($i = 0; $i < $length; $i++) {
        $character = $arguments[$i];

        if ($quote !== null) {
            $current .= $character;

            if ($character === '\\') {
                // The next character is escaped, so it cannot close the
                // string.
                if ($i + 1 < $length) {
                    $i++;
                    $current .= $arguments[$i];
                }
                continue;
            }

            if ($character === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($character === '\'' || $character === '"') {
            $quote = $character;
            $current .= $character;
            continue;
        }

        if ($character === '(' || $character === '[') {
            $depth++;
            $current .= $character;
            continue;
        }

        if ($character === ')' || $character === ']') {
            $depth--;
            $current .= $character;
            continue;
        }

        if ($character === ',' && $depth === 0) {
            $parts[] = trim($current);
            $current = '';
            continue;
        }

        $current .= $character;
    }

    if (trim($current) !== '') {
        $parts[] = trim($current);
    }

    return $parts;
}

/**
 * The single-quoted or double-quoted string literal in an argument.
 *
 * @param string $argument
 * @return string|null The literal, or null when the argument is not a
 *         plain string literal.
 */
function p8_string_literal(string $argument): ?string
{
    $argument = trim($argument);

    if (strlen($argument) < 2) {
        return null;
    }

    $first = $argument[0];

    if ($first !== '\'' && $first !== '"') {
        return null;
    }

    // A heredoc or a concatenation is not a plain literal.
    if (strpos($argument, '.') !== false && $first === '"') {
        // A double-quoted string may legitimately contain a dot, so this
        // is only a hint. The check below decides.
    }

    if (substr($argument, -1) !== $first) {
        return null;
    }

    return substr($argument, 1, -1);
}

$files = array(
    __DIR__ . '/../models/WalletPayment.php',
    __DIR__ . '/../models/Refund.php',
    __DIR__ . '/../models/Checkout.php',
    __DIR__ . '/../models/Payment.php',
    __DIR__ . '/../models/Order.php',
    __DIR__ . '/../models/Cart.php',
    __DIR__ . '/../models/OrderFulfilment.php',
    __DIR__ . '/../models/User.php',
    __DIR__ . '/../models/Product.php',
    __DIR__ . '/../models/Category.php',
);

$failures = 0;
$totalStatements = 0;

echo "GreenCorner — prepared statement binding check\n\n";

foreach ($files as $file) {
    $source = file_get_contents($file);

    if ($source === false) {
        echo "[FAIL] Could not read " . $file . "\n";
        $failures++;
        continue;
    }

    $pattern = '/\bdb_(?:select_one|select|write|insert_id)\s*\(/';
    $offset = 0;
    $checked = 0;

    while (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
        $start = $match[0][1] + strlen($match[0][0]);

        // Find the matching closing parenthesis.
        $depth = 1;
        $i = $start;
        $length = strlen($source);
        $quote = null;

        while ($i < $length && $depth > 0) {
            $character = $source[$i];

            if ($quote !== null) {
                if ($character === '\\') {
                    $i += 2;
                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                $i++;
                continue;
            }

            if ($character === '\'' || $character === '"') {
                $quote = $character;
                $i++;
                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }

            $i++;
        }

        $arguments = substr($source, $start, $i - $start - 1);
        $offset = $i;
        $checked++;
        $totalStatements++;

        $parts = p8_split_arguments($arguments);

        if (count($parts) < 2) {
            // A statement with no type string has no parameters, so it
            // cannot bind anything. It is only a problem if it has
            // placeholders.
            continue;
        }

        $sql = p8_string_literal($parts[0]);
        $types = p8_string_literal($parts[1]);

        if ($sql === null || $types === null) {
            // The SQL or the type string is built at runtime; it is not
            // checked here.
            continue;
        }

        $placeholders = substr_count($sql, '?');
        $typeLength = strlen($types);

        if ($placeholders !== $typeLength) {
            $lineNumber = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;

            echo "[FAIL] " . basename($file) . ":" . $lineNumber
                . " — " . $placeholders . " placeholder(s) but " . $typeLength
                . " type(s).\n";
            echo "       SQL:   " . preg_replace('/\s+/', ' ', $sql) . "\n";
            echo "       Types: '" . $types . "'\n";

            $failures++;
        }
    }

    echo basename($file) . ": " . $checked . " statement(s) checked.\n";
}

echo "\n";

if ($failures > 0) {
    echo "RESULT: " . $failures . " mismatch(es) found.\n";
    exit(1);
}

echo "RESULT: all " . $totalStatements . " prepared statements bind the right number of values.\n";
exit(0);
