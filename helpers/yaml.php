<?php
/**
 * GreenCorner — Minimal YAML structure validator
 *
 * A dependency-free validator for the workflow and compose files.
 *
 * The project has no package manager and no build step (README), so a YAML
 * parser cannot be assumed to be present. This validator checks the
 * STRUCTURE of a YAML document: the indentation, the key/value shape, the
 * list items, the block scalars and the quoting. It is not a complete YAML
 * implementation, and it does not need to be: it is used to catch the
 * mistakes that actually happen when a workflow file is written by hand —
 * a tab, a bad indent, an unclosed quote, a list item at the wrong level.
 *
 * It is used by tests/phase6_ci_checks.php to validate the GitHub Actions
 * workflow before it is ever run.
 */

/**
 * The result of a validation run.
 */
class YamlValidationResult
{
    /** @var array<int, string> The problems found. */
    public $errors = array();

    /**
     * Whether the document is structurally valid.
     */
    public function isValid(): bool
    {
        return $this->errors === array();
    }

    /**
     * Add a problem.
     */
    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }
}

/**
 * Validate the structure of a YAML document.
 *
 * @param string $yaml The document.
 * @return YamlValidationResult
 */
function yaml_validate(string $yaml): YamlValidationResult
{
    $result = new YamlValidationResult();

    // A YAML document must not contain a tab character for indentation.
    // Tabs are illegal in YAML indentation, and their presence is the single
    // most common cause of a "mapping values are not allowed" error.
    foreach (explode("\n", $yaml) as $number => $line) {
        if (strpos($line, "\t") !== false) {
            $result->addError(
                'Line ' . ($number + 1) . ' contains a tab character, which YAML forbids in indentation.'
            );
        }
    }

    $lines = explode("\n", $yaml);
    $inBlockScalar = false;
    $blockScalarIndent = 0;
    $openQuotes = null;

    foreach ($lines as $number => $line) {
        $lineNumber = $number + 1;

        // A block scalar (| or >) swallows everything that follows it, so
        // the indentation of those lines is not checked.
        if ($inBlockScalar) {
            $indent = strlen($line) - strlen(ltrim($line, ' '));

            if (trim($line) === '' || $indent >= $blockScalarIndent) {
                continue;
            }

            $inBlockScalar = false;
        }

        $trimmed = trim($line);

        // A blank line and a comment carry no structure.
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        // A document separator.
        if ($trimmed === '---' || $trimmed === '...') {
            continue;
        }

        $indent = strlen($line) - strlen(ltrim($line, ' '));

        // The indentation must be a multiple of two, which is the
        // convention the project follows.
        if ($indent % 2 !== 0) {
            $result->addError(
                'Line ' . $lineNumber . ' has an odd indentation of ' . $indent . ' spaces.'
            );
        }

        // Track the block scalar introducer.
        if (preg_match('/:\s*[|>][-+]?\s*$/', $trimmed) === 1) {
            $inBlockScalar = true;
            $blockScalarIndent = $indent + 2;
            continue;
        }

        // A list item.
        if (preg_match('/^-\s*/', $trimmed) === 1) {
            $rest = trim(substr($trimmed, 1));

            if ($rest === '') {
                continue;
            }

            // "- key: value" and "- value" are both valid.
            if (strpos($rest, ':') !== false && strpos($rest, '"') === false && strpos($rest, "'") === false) {
                continue;
            }

            continue;
        }

        // A mapping entry: "key: value" or "key:".
        if (preg_match('/^([^:\s][^:]*):(\s.*|)$/', $trimmed, $m) !== 1) {
            $result->addError(
                'Line ' . $lineNumber . ' is neither a mapping entry nor a list item: ' . $trimmed
            );
            continue;
        }

        $key = trim($m[1]);
        $value = trim($m[2]);

        // The key must not be empty and must not contain a quote.
        if ($key === '') {
            $result->addError('Line ' . $lineNumber . ' has an empty key.');
        }

        // A quoted value must be closed on the same line.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = substr($value, -1);

            if (($first === '"' && $last !== '"') || ($first === "'" && $last !== "'")) {
                $result->addError(
                    'Line ' . $lineNumber . ' has an unclosed quoted value: ' . $trimmed
                );
            }
        } elseif ($value === '"' || $value === "'") {
            $result->addError(
                'Line ' . $lineNumber . ' has an unclosed quoted value: ' . $trimmed
            );
        }

        // An inline comment must be preceded by a space, otherwise it is
        // part of the value.
        if (strpos($value, ' #') === false && strpos($value, '"#') !== false) {
            $result->addError(
                'Line ' . $lineNumber . ' has a comment marker inside a value without a preceding space.'
            );
        }
    }

    if ($openQuotes !== null) {
        $result->addError('The document ends with an unclosed quote.');
    }

    return $result;
}

/**
 * Read the top-level keys of a YAML document.
 *
 * Only the keys at the very start of a line are returned, which is enough
 * to confirm that a workflow file declares the sections it needs.
 *
 * @param string $yaml The document.
 * @return array<int, string>
 */
function yaml_top_level_keys(string $yaml): array
{
    $keys = array();

    foreach (explode("\n", $yaml) as $line) {
        if (trim($line) === '' || strpos(ltrim($line), '#') === 0) {
            continue;
        }

        if (preg_match('/^([A-Za-z0-9_.-]+):/', $line, $m) === 1) {
            $keys[] = $m[1];
        }
    }

    return $keys;
}

/**
 * Find the indentation level of a named key.
 *
 * @param string $yaml The document.
 * @param string $key The key to find.
 * @return int|null The indentation, or null when the key is absent.
 */
function yaml_key_indent(string $yaml, string $key): ?int
{
    foreach (explode("\n", $yaml) as $line) {
        if (preg_match('/^(\s*)' . preg_quote($key, '/') . ':/', $line, $m) === 1) {
            return strlen($m[1]);
        }
    }

    return null;
}

/**
 * Find a named key and return its value.
 *
 * @param string $yaml The document.
 * @param string $key The key to find.
 * @return string|null The trimmed value, or null when the key is absent.
 */
function yaml_value(string $yaml, string $key): ?string
{
    foreach (explode("\n", $yaml) as $line) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . ':\s*(.*)$/', $line, $m) === 1) {
            return trim($m[1], " \t\"'");
        }
    }

    return null;
}
