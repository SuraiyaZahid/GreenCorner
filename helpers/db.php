<?php
/**
 * GreenCorner — Database Connection (MySQLi)
 *
 * The single, centralised database connection for the whole application.
 * Every query in the project goes through this file, so the strict SQL mode
 * requirement is enforced in exactly one place.
 *
 * Implements:
 *   R-SQL-1  STRICT_TRANS_TABLES is configured at the server level
 *   R-SQL-2  the session mode is set explicitly, naming STRICT_TRANS_TABLES
 *   R-SQL-3  the effective mode is verified after connecting and a
 *            connection without STRICT_TRANS_TABLES is rejected and logged
 *   R-SQL-4  no code path weakens the mode after the check has passed
 *   R-SQL-7  the IGNORE write variants are never used
 *   R-SQL-8  the warning count is captured immediately after a write
 *   R-SQL-9  affected_rows = 0 is never treated as proof of non-existence
 */

require_once __DIR__ . '/../config/bootstrap.php';

/**
 * The one and only MySQLi connection handle for the request.
 *
 * @var mysqli|null
 */
$GLOBALS['gc_db'] = null;

/**
 * When true, every attempt to reach the database fails as if the server were
 * unreachable.
 *
 * This exists so that the error-handling paths can be tested over HTTP. It is
 * set by the Phase 6 fault-injection controller, which refuses to act in
 * production and for any account that is not an Admin (decision D-018).
 *
 * @var bool
 */
$GLOBALS['gc_db_unreachable'] = false;

/**
 * Make the database unreachable for the rest of this request.
 *
 * Used only by the Phase 6 fault-injection controller. The handle is closed
 * and the cached instance discarded, so the next call to db() attempts a
 * fresh connection which is then refused.
 *
 * @return void
 */
function simulate_database_outage(): void
{
    if (isset($GLOBALS['gc_db']) && $GLOBALS['gc_db'] instanceof mysqli) {
        @$GLOBALS['gc_db']->close();
    }

    $GLOBALS['gc_db'] = null;
    $GLOBALS['gc_db_unreachable'] = true;
}

/**
 * Open the database connection, set and verify the strict SQL mode, and
 * return the shared handle.
 *
 * @return mysqli
 * @throws RuntimeException if the connection or the SQL mode check fails.
 */
function db()
{
    // Return the already-open handle if it exists.
    if ($GLOBALS['gc_db'] instanceof mysqli) {
        return $GLOBALS['gc_db'];
    }

    // The Phase 6 fault injection makes the connection fail exactly as it
    // would if the database server were down.
    if (!empty($GLOBALS['gc_db_unreachable'])) {
        throw new RuntimeException('Database connection failed: injected outage');
    }

    // Every database setting must be present before the connection is opened.
    // The check lives here, at the point where the credentials are actually
    // used, so that a file which merely READS the configuration - a static
    // check, for example - can load it without the credentials of this
    // installation being present. The application itself never connects
    // without them: it stops here (decision D-001).
    green_corner_require_database_settings();

    $mysqli = @new mysqli(
        DB_HOST,
        DB_USER,
        DB_PASS,
        DB_NAME,
        DB_PORT
    );

    if ($mysqli->connect_errno) {
        throw new RuntimeException(
            'Database connection failed: ' . $mysqli->connect_error
        );
    }

    // Character set and collation (SRS §10.1). set_charset() takes a
    // character set; the collation utf8mb4_unicode_ci required by the LIKE
    // search of FR-2.5 is the collation of every table in the schema.
    if (!$mysqli->set_charset(DB_CHARSET)) {
        throw new RuntimeException('Could not set database charset to ' . DB_CHARSET);
    }

    // R-SQL-2: set the session SQL mode explicitly, naming
    // STRICT_TRANS_TABLES so a relaxed server default cannot be inherited.
    if (!$mysqli->query("SET SESSION sql_mode = '" . DB_SESSION_SQL_MODE . "'")) {
        $error = $mysqli->error;
        $mysqli->close();
        throw new RuntimeException('Could not set session sql_mode: ' . $error);
    }

    // R-SQL-3: verify the effective mode and reject the connection if
    // STRICT_TRANS_TABLES is absent. This is the effective control.
    $modeResult = $mysqli->query('SELECT @@SESSION.sql_mode AS mode');
    if (!$modeResult) {
        $error = $mysqli->error;
        $mysqli->close();
        throw new RuntimeException('Could not read session sql_mode: ' . $error);
    }

    $row = $modeResult->fetch_assoc();
    $modeResult->free();
    $effectiveMode = isset($row['mode']) ? $row['mode'] : '';

    if (stripos($effectiveMode, 'STRICT_TRANS_TABLES') === false) {
        // Log the failure before rejecting the connection.
        error_log(
            '[GreenCorner] REJECTED database connection: session sql_mode is "'
            . $effectiveMode . '", which does not include STRICT_TRANS_TABLES.'
        );
        $mysqli->close();
        throw new RuntimeException(
            'Database connection rejected: session sql_mode lacks STRICT_TRANS_TABLES.'
        );
    }

    $GLOBALS['gc_db'] = $mysqli;

    return $GLOBALS['gc_db'];
}

/**
 * Prepare a statement with bound parameters.
 *
 * All database access goes through here, which guarantees prepared statements
 * and therefore prevents SQL injection (NFR-S-03, SEC-1).
 *
 * @param string $sql    SQL with '?' placeholders.
 * @param string $types  Type string, e.g. 's', 'i', 'd', 'ds'.
 * @param array  $params Values to bind, in placeholder order.
 * @return mysqli_stmt
 */
function db_prepare(string $sql, string $types = '', array $params = [])
{
    $stmt = db()->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException('Could not prepare statement: ' . db()->error);
    }

    if ($params !== []) {
        // mysqli_stmt::bind_param() takes its bound values BY REFERENCE, so
        // each value is first copied into a variable and the variable's
        // address is passed. Passing the array elements directly would raise
        // a warning under PHP 8, and that warning would be emitted as page
        // output — sending the headers early and corrupting the response
        // status code.
        $values = array_values($params);
        $references = array();

        foreach ($values as $index => $value) {
            $references[$index] = &$values[$index];
        }

        // The type string must have exactly one character per bound value.
        // A mismatch raises an ArgumentCountException from bind_param(), and
        // the message it produces ("the number of elements in the type
        // definition string must match the number of bind variables") does
        // not say which statement was at fault. The check below turns that
        // into a message that names the problem.
        if (strlen($types) !== count($values)) {
            $stmt->close();
            throw new RuntimeException(
                'Prepared statement type mismatch: ' . count($values)
                . ' values were bound but the type string "' . $types
                . '" describes ' . strlen($types) . '.'
            );
        }

        $bindArgs = array_merge(array($types), $references);
        call_user_func_array(array($stmt, 'bind_param'), $bindArgs);
    }

    return $stmt;
}

/**
 * Run a query that returns rows and return them as an array of associative
 * arrays.
 *
 * @param string $sql    SQL with '?' placeholders.
 * @param string $types  Type string.
 * @param array  $params Values to bind.
 * @return array<int, array<string, mixed>>
 */
function db_select(string $sql, string $types = '', array $params = []): array
{
    $stmt = db_prepare($sql, $types, $params);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Query failed: ' . $error);
    }

    $result = $stmt->get_result();
    $rows = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();

    return $rows;
}

/**
 * Run a query that returns a single row, or null when there is no match.
 *
 * @param string $sql    SQL with '?' placeholders.
 * @param string $types  Type string.
 * @param array  $params Values to bind.
 * @return array<string, mixed>|null
 */
function db_select_one(string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_select($sql, $types, $params);

    return $rows[0] ?? null;
}

/**
 * Run a write statement (INSERT, UPDATE, DELETE).
 *
 * Following R-SQL-8, the warning count is captured immediately after the
 * statement and before any other query, because SHOW COUNT(*) WARNINGS is
 * diagnostic and does not clear the warning list.
 *
 * Following NFR-M-05 / ER-11, the result of the write is checked and the
 * caller receives the number of affected rows so that a skipped write can
 * never be mistaken for a successful one.
 *
 * @param string $sql    SQL with '?' placeholders.
 * @param string $types  Type string.
 * @param array  $params Values to bind.
 * @return int Number of affected rows.
 * @throws RuntimeException if the statement fails or raises warnings.
 */
function db_write(string $sql, string $types = '', array $params = []): int
{
    $stmt = db_prepare($sql, $types, $params);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $errno = $stmt->errno;
        $stmt->close();
        throw new RuntimeException('Write failed (' . $errno . '): ' . $error, $errno);
    }

    $affectedRows = $stmt->affected_rows;

    // R-SQL-8: capture the warning count immediately, before any other query.
    $warningCount = 0;
    $warningResult = db()->query('SHOW COUNT(*) WARNINGS');
    if ($warningResult) {
        $warningRow = $warningResult->fetch_row();
        $warningCount = isset($warningRow[0]) ? (int) $warningRow[0] : 0;
        $warningResult->free();
    }

    // Inspect the warnings if the count is non-zero (R-SQL-8, ER-13).
    if ($warningCount > 0) {
        $messages = [];
        $detail = db()->query('SHOW WARNINGS');
        if ($detail) {
            while ($warning = $detail->fetch_row()) {
                $messages[] = $warning[0] . ': ' . $warning[1] . ' (code ' . $warning[2] . ')';
            }
            $detail->free();
        }
        $stmt->close();
        throw new RuntimeException(
            'Write raised ' . $warningCount . ' warning(s): ' . implode('; ', $messages)
        );
    }

    $stmt->close();

    return $affectedRows;
}

/**
 * Run a write statement and return the id of the inserted row.
 *
 * @param string $sql    SQL with '?' placeholders.
 * @param string $types  Type string.
 * @param array  $params Values to bind.
 * @return int Insert id.
 */
function db_insert_id(string $sql, string $types = '', array $params = []): int
{
    // The insert id must be read while the statement is still open —
    // closing the statement resets mysqli::$insert_id.
    $stmt = db_prepare($sql, $types, $params);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $errno = $stmt->errno;
        $stmt->close();
        throw new RuntimeException('Insert failed (' . $errno . '): ' . $error, $errno);
    }

    // R-SQL-8: capture the warning count immediately, before any other query.
    $warningCount = 0;
    $warningResult = db()->query('SHOW COUNT(*) WARNINGS');
    if ($warningResult) {
        $warningRow = $warningResult->fetch_row();
        $warningCount = isset($warningRow[0]) ? (int) $warningRow[0] : 0;
        $warningResult->free();
    }

    if ($warningCount > 0) {
        $messages = [];
        $detail = db()->query('SHOW WARNINGS');
        if ($detail) {
            while ($warning = $detail->fetch_row()) {
                $messages[] = $warning[0] . ': ' . $warning[1] . ' (code ' . $warning[2] . ')';
            }
            $detail->free();
        }
        $stmt->close();
        throw new RuntimeException(
            'Insert raised ' . $warningCount . ' warning(s): ' . implode('; ', $messages)
        );
    }

    // mysqli_stmt::get_insert_id() is the reliable accessor for the id of
    // the inserted row. mysqli::$insert_id is not populated reliably here.
    $insertId = (int) $stmt->insert_id;
    $stmt->close();

    return $insertId;
}

/**
 * Begin a transaction.
 */
function db_begin_transaction(): void
{
    db()->begin_transaction();
}

/**
 * Commit the current transaction.
 */
function db_commit(): void
{
    db()->commit();
}

/**
 * Roll back the current transaction.
 */
function db_rollback(): void
{
    db()->rollback();
}

/**
 * Translate a MySQLi error code into a short, machine-readable code.
 *
 * Used by the JSON endpoints so that clients receive a consistent error code
 * alongside the human-readable message (SRS §9.4, ER-22).
 *
 * @param int $errno MySQLi error number.
 * @return string
 */
function db_error_code(int $errno): string
{
    switch ($errno) {
        case 1062:
            return 'duplicate_entry';
        case 1451:
            return 'row_is_referenced';
        case 1452:
            return 'no_referenced_row';
        case 1265:
            return 'invalid_enum_value';
        case 3819:
            return 'check_constraint_violated';
        case 1364:
        case 1366:
            return 'invalid_value';
        default:
            return 'database_error';
    }
}
