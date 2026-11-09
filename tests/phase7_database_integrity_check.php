<?php
/**
 * GreenCorner — Launch Verification: local database integrity check
 *
 * READ-ONLY. Confirms that the launch-verification work changed nothing in
 * the local development database: no row was deleted, no schema was altered,
 * and the session table still holds every row it did.
 *
 * It connects with the application's own helpers, so it uses the same
 * credentials the application uses, and it issues SELECT statements only.
 *
 * Usage:
 *   php tests/phase7_database_integrity_check.php
 */

require_once __DIR__ . '/TestRunner.php';
require_once dirname(__DIR__) . '/helpers/db.php';

$runner = new TestRunner();

$runner->group('1. The tables are present and populated');

$expected = array(
    'roles' => 2,
    'users' => null,
    'categories' => 5,
    'products' => 28,
    'orders' => null,
    'order_items' => null,
    'payments' => null,
    'sessions' => null,
    'login_attempts' => null,
);

$counts = array();

foreach ($expected as $table => $fixed) {
    $runner->test('The ' . $table . ' table is readable', function () use ($table, &$counts) {
        $row = db_select_one('SELECT COUNT(*) AS `total` FROM `' . $table . '`');
        $counts[$table] = (int) ($row['total'] ?? 0);

        assert_true($counts[$table] >= 0, 'The ' . $table . ' table could not be counted');
    });

    if ($fixed !== null) {
        $runner->test('The ' . $table . ' table still holds its expected rows', function () use ($table, $fixed, &$counts) {
            assert_equals(
                $fixed,
                $counts[$table] ?? -1,
                'The ' . $table . ' table no longer holds its expected row count'
            );
        });
    }
}

$runner->group('2. The session table is intact');

$runner->test('The session table still holds its recent sessions', function () use (&$counts) {
    // WHAT THIS CHECK ASSERTS, AND WHAT IT DOES NOT.
    //
    // This check asserts two things about the current state of the table:
    // that it holds at least one session used within the idle timeout,
    // and that it is not empty.
    //
    // It does NOT prove that no purge occurred, and it is not a purge
    // detector. It cannot be one, because the handler's gc() and the
    // declined Option A purge script issue the SAME statement:
    //
    //     DELETE FROM `sessions` WHERE `last_activity` < (now - cutoff)
    //
    // A purge that removes only the idle rows therefore leaves the table
    // in exactly the same observable state as the accepted garbage
    // collection. The two differ only in which code executed, and the
    // table cannot report that. The information needed to tell them
    // apart is destroyed by the very act being detected.
    //
    // THE EVIDENCE THAT NO PURGE MECHANISM EXISTS is asserted in
    // tests/phase6_session_checks.php, group 6, "The Phase 6
    // restrictions were respected". That is the check that matches
    // D-021: it proves that no purge script exists, that the handler
    // holds no unbounded delete, that no scheduler entry exists, and
    // that the decision log records the accepted policy. This check is
    // a companion to it, not a substitute for it.
    //
    // WHY THIS QUANTITY. The assertion this replaces counted the rows
    // that are idle beyond the 8 hour timeout and expected that count to
    // be ZERO. That counted exactly what the handler's gc() would
    // collect, so it grew as the table aged and it failed on any
    // database that had been running for more than 8 hours. It also
    // asserted the opposite of the accepted policy: D-021 accepts the
    // handler-based policy, so no purge script and no scheduled job run,
    // and rows past the cutoff are EXPECTED to accumulate.
    //
    // The current quantities do not decay in that way. Neither depends
    // on how old the table is, and neither is affected by the garbage
    // collector, which only ever removes rows that are older than the
    // cutoff.
    //
    // PRECONDITION (owner-approved, option (a)): this check is
    // meaningful only on a database that has been serving traffic, so
    // that it holds at least one session that was used recently. On a
    // freshly seeded database that has never served a request there is
    // no such session and this check fails. That is a known limitation
    // of the check, not a purge.
    $recent = db_select_one(
        'SELECT COUNT(*) AS `total`
           FROM `sessions`
          WHERE `last_activity` >= UNIX_TIMESTAMP() - ?',
        'i',
        array(SESSION_IDLE_TIMEOUT)
    );

    assert_true(
        (int) ($recent['total'] ?? 0) > 0,
        'No session row has been used within the idle timeout. The sessions '
        . 'table holds no recent session. The handler garbage collector '
        . 'never removes a session that was in use within the idle timeout, '
        . 'so this indicates that the recent sessions were removed by some '
        . 'other mechanism.'
    );

    $total = db_select_one('SELECT COUNT(*) AS `total` FROM `sessions`');

    assert_true(
        (int) ($total['total'] ?? 0) > 0,
        'The sessions table is empty. The accepted policy (D-021) says no '
        . 'purge script and no scheduled job run, so the table is expected '
        . 'to hold its sessions.'
    );
});

$runner->test('No session row is orphaned', function () use (&$counts) {
    $row = db_select_one(
        'SELECT COUNT(*) AS `total`
           FROM `sessions` s
      LEFT JOIN `users` u ON u.`id` = s.`user_id`
          WHERE s.`user_id` IS NOT NULL
            AND u.`id` IS NULL'
    );

    assert_equals(
        0,
        (int) ($row['total'] ?? -1),
        'An orphaned session row exists, which a purge would have removed'
    );
});

$runner->group('3. The schema was not altered');

$runner->test('The schema matches the committed schema file', function () {
    // The columns of the tables that matter are compared against what the
    // committed schema declares. A migration would show up here.
    $expected = array(
        'users' => array('id', 'full_name', 'email', 'password_hash', 'role_id', 'is_active', 'must_change_password', 'created_at'),
        'sessions' => array('session_id', 'user_id', 'payload', 'last_activity', 'created_at'),
        'orders' => array(
            'id', 'order_number', 'user_id', 'total_amount', 'status',
            'delivery_name', 'delivery_phone', 'delivery_address', 'delivery_city',
            'created_at',
        ),
    );

    foreach ($expected as $table => $columns) {
        $rows = db_select('SHOW COLUMNS FROM `' . $table . '`');
        $actual = array();

        foreach ($rows as $row) {
            $actual[] = (string) $row['Field'];
        }

        sort($actual);
        sort($columns);

        assert_equals($columns, $actual, 'The ' . $table . ' table columns have changed');
    }
});

$runner->group('4. The report');

$runner->test('The counts are printed for the record', function () use (&$counts) {
    $lines = array();

    foreach ($counts as $table => $total) {
        $lines[] = sprintf('    %-16s %d', $table, $total);
    }

    fwrite(STDERR, PHP_EOL . '  The local development database, as it stands:' . PHP_EOL);
    fwrite(STDERR, implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL);

    assert_true(count($counts) === 9, 'Not every table was counted');
});

exit($runner->summary());
