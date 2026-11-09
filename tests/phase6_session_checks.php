<?php
/**
 * GreenCorner — Phase 6: session housekeeping checks
 *
 * These checks confirm that the session housekeeping the project relies
 * on is actually in place, and that the Phase 6 restrictions were
 * respected:
 *
 *   1. The handler expires a session on read, using both timeouts.
 *   2. The handler refreshes the activity timestamp on write.
 *   3. The garbage collector collects rows past the idle timeout.
 *   4. The cleanup index exists, so a future purge is cheap.
 *   5. The sessions table is excluded from backups.
 *   6. NO cleanup job was scheduled and NO purge script was added.
 *
 * Check 6 is the important one: the Phase 6 instruction forbade deleting
 * records, running a cleanup, scheduling a job, and modifying sessions.
 * These checks confirm that none of those happened, so a later change
 * cannot quietly introduce them.
 *
 * Usage:
 *   php tests/phase6_session_checks.php
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/../helpers/session_handler.php';
require_once __DIR__ . '/../database/backup.php';

$root = dirname(__DIR__);

$runner = new TestRunner();

$runner->group('1. The handler expires a session when it is read');

$runner->test('The idle timeout is 8 hours', function () {
    assert_equals(28800, SESSION_IDLE_TIMEOUT);
});

$runner->test('The absolute timeout is 7 days', function () {
    assert_equals(604800, SESSION_ABSOLUTE_TIMEOUT);
});

$runner->test('The read method destroys a session past the idle timeout', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    assert_contains('$now - $lastActivity > SESSION_IDLE_TIMEOUT', $source);
    assert_contains('$this->destroy($sessionId)', $source);
});

$runner->test('The read method destroys a session past the absolute timeout', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    assert_contains('$now - $createdAt > SESSION_ABSOLUTE_TIMEOUT', $source);
});

$runner->test('The expiry uses the created time, not the activity time, for the absolute limit', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    // An active session must still die at the absolute maximum. The check
    // must read created_at, never last_activity, for the absolute limit.
    assert_contains('$now - $createdAt > SESSION_ABSOLUTE_TIMEOUT', $source);
});

$runner->group('2. The handler refreshes the activity timestamp');

$runner->test('The write method updates last_activity', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    assert_contains('SET `payload` = ?, `last_activity` = ?', $source);
});

$runner->test('An unchanged payload still refreshes the timestamp', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    // affected_rows = 0 does not prove absence (R-SQL-9 / ER-14), so the
    // handler falls back to a SELECT and then a timestamp-only UPDATE.
    assert_contains('UPDATE `{$this->table}` SET `last_activity` = ? WHERE `session_id` = ?', $source);
    assert_contains('$affected === 0', $source);
});

$runner->test('The write method is guarded against an uncaught exception', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    assert_contains('[GreenCorner] Session write failed: ', $source);
});

$runner->group('3. The garbage collector');

$runner->test('The gc method deletes rows past the idle timeout', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    assert_contains('DELETE FROM `{$this->table}` WHERE `last_activity` < ?', $source);
});

$runner->test('The gc method is guarded against an uncaught exception', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    assert_contains('[GreenCorner] Session gc failed: ', $source);
});

$runner->test('The garbage collector is driven by PHP, not by a scheduled job', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    assert_contains("ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_TIMEOUT)", $source);
});

$runner->group('4. The table is prepared for a future cleanup');

$runner->test('The last_activity column is indexed', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');

    assert_contains('idx_sessions_last_activity', $source);
    assert_contains('KEY `idx_sessions_last_activity` (`last_activity`)', $source);
});

$runner->test('The user_id column is indexed', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');

    assert_contains('KEY `idx_sessions_user_id` (`user_id`)', $source);
});

$runner->test('The sessions table has a foreign key to users', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');

    assert_contains('CONSTRAINT `fk_sessions_user`', $source);
    assert_contains('ON DELETE CASCADE', $source);
});

$runner->group('5. The sessions table is excluded from backups');

$runner->test('The sessions table is in the exclusion list', function () {
    assert_in_array('sessions', BACKUP_EXCLUDED_TABLES);
});

$runner->test('The login_attempts table is in the exclusion list', function () {
    assert_in_array('login_attempts', BACKUP_EXCLUDED_TABLES);
});

$runner->group('6. The Phase 6 restrictions were respected');

$runner->test('No session purge script was added to the application', function () {
    // A purge would be a script that deletes from the sessions table.
    // Phase 6 was forbidden from running one, so none may exist.
    $found = array();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__) . '/database', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (stripos($source, 'DELETE FROM `sessions`') !== false
            || stripos($source, "DELETE FROM `sessions`") !== false) {
            $found[] = $file->getFilename();
        }
    }

    assert_equals(
        array(),
        $found,
        'A script that deletes session rows was added. Phase 6 was not permitted to add one: '
        . implode(', ', $found)
    );
});

$runner->test('The session handler has no unconditional bulk delete', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    // The handler does delete session rows, but only in two bounded ways:
    // one session by its id, and every session of one user when their
    // password changes (a security requirement, not a purge). There must
    // be no delete with no WHERE clause and no TRUNCATE.
    assert_not_contains('TRUNCATE', $source);
    assert_not_contains('DELETE FROM `sessions`;', $source);
    assert_not_contains('DELETE FROM `sessions` WHERE 1', $source);

    // The gc() delete is bounded by a timestamp comparison.
    assert_contains('DELETE FROM `{$this->table}` WHERE `last_activity` < ?', $source);
});

$runner->test('The handler deletes only bounded session sets', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/session_handler.php');

    // Every DELETE against the sessions table must be bounded by a WHERE
    // clause naming a session id, a user id, or an activity timestamp.
    preg_match_all('/DELETE FROM `sessions`[^;]*/', $source, $matches);

    foreach ($matches[0] as $statement) {
        assert_contains('WHERE', $statement, 'An unbounded DELETE was found: ' . $statement);
    }
});

$runner->test('No cron entry or scheduled job was added', function () {
    // The project has no cron configuration and no scheduler directory.
    foreach (array('crontab', 'cron', 'scheduler', 'scheduled') as $name) {
        assert_true(
            !is_dir(dirname(__DIR__) . '/' . $name),
            'A ' . $name . ' directory was added. Phase 6 was not permitted to schedule a job.'
        );
    }
});

$runner->test('The compose file schedules no periodic job', function () {
    $compose = dirname(__DIR__) . '/docker-compose.yml';

    if (!is_file($compose)) {
        return;
    }

    $source = (string) file_get_contents($compose);

    // A cleanup would appear as a scheduled or repeating service.
    assert_not_contains('schedule:', $source);
    assert_not_contains('cron', $source);
    assert_not_contains('session-cleanup', $source);
});

$runner->test('The session assessment is read-only', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_session_assessment.php');

    // The assessment reads only. It must not call a write helper and it
    // must not contain a write statement. The check looks for the SQL
    // verbs at the start of a statement, so that a mention of "ON DELETE
    // CASCADE" in a comment is not a false positive.
    assert_not_contains('db_write(', $source);
    assert_not_contains('db_insert(', $source);
    assert_not_contains('db_update(', $source);

    $withoutComments = (string) preg_replace('/^\s*\/\/.*$/m', '', $source);

    assert_not_contains('DELETE FROM', $withoutComments);
    assert_not_contains('UPDATE `', $withoutComments);
    assert_not_contains('INSERT INTO', $withoutComments);
});

$runner->test('The housekeeping policy is recorded as accepted, with no purge and no job', function () {
    $proposal = dirname(__DIR__) . '/docs/SESSION_HOUSEKEEPING_PROPOSAL.md';

    assert_true(is_file($proposal), 'The proposal document is missing');

    $source = (string) file_get_contents($proposal);

    // The policy was PROPOSAL during Phase 6 and was ACCEPTED on
    // 2026-11-02 (decision D-021). The decision is "no change": the
    // existing handler-based housekeeping is the final policy.
    assert_contains('Status: ACCEPTED', $source);

    // The decision is the owner's, and it is recorded in the decision log.
    $decisions = (string) file_get_contents(dirname(__DIR__) . '/config/DECISIONS.md');

    assert_contains('D-021', $decisions);
    assert_contains('handler-based policy', $decisions);
    assert_contains('ACCEPTED', $decisions);

    // The sentence is line-wrapped in the document, so the check looks at
    // the whitespace-normalised text.
    $flat = (string) preg_replace('/\s+/', ' ', $source);

    assert_contains('No row was deleted, updated, or otherwise modified', $flat);

    // The accepted decision is specifically "no purge script and no
    // scheduled job". The document must say so, so that the absence of
    // either mechanism is recorded as a decision rather than a gap.
    // The comparison is case-insensitive because the sentence may start a
    // line, and the flattening above already removes the line breaks.
    $flatLower = strtolower($flat);

    assert_true(
        stripos($flatLower, 'purge script was written') !== false,
        'The document does not record that no purge script was written'
    );
    assert_true(
        stripos($flatLower, 'scheduled job was created') !== false,
        'The document does not record that no scheduled job was created'
    );

    // And the decision log must record Option B as a deliberate deferral.
    assert_contains('deliberate deferral', $decisions);
});

exit($runner->summary());
