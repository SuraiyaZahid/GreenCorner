<?php
/**
 * GreenCorner — Phase 6: session housekeeping assessment
 *
 * A READ-ONLY inspection of the sessions table. It reports the shape of
 * the data so that a cleanup policy can be written on evidence.
 *
 * This script NEVER deletes, updates or modifies a session record. It
 * only reads.
 *
 * Usage:
 *   php tests/phase6_session_assessment.php
 */

require_once __DIR__ . '/../config/bootstrap.php';

require_once __DIR__ . '/../helpers/session_handler.php';

echo "=== The session table ===\n\n";

$row = db_select_one(
    'SELECT COUNT(*) AS `total` FROM `sessions`'
);

$total = (int) ($row['total'] ?? 0);

echo 'Total session rows: ' . $total . "\n\n";

if ($total === 0) {
    echo "The table is empty. There is nothing to clean up.\n";
    exit(0);
}

// ---- The age distribution -----------------------------------------------
echo "--- Age distribution against the two timeouts ---\n\n";

$idle = SESSION_IDLE_TIMEOUT;
$absolute = SESSION_ABSOLUTE_TIMEOUT;

$now = time();

$bands = db_select(
    "SELECT
        SUM(CASE WHEN `last_activity` < (UNIX_TIMESTAMP() - " . (int) $absolute . ") THEN 1 ELSE 0 END) AS `past_absolute`,
        SUM(CASE WHEN `last_activity` >= (UNIX_TIMESTAMP() - " . (int) $absolute . ")
                  AND `last_activity` < (UNIX_TIMESTAMP() - " . (int) $idle . ") THEN 1 ELSE 0 END) AS `idle_only`,
        SUM(CASE WHEN `last_activity` >= (UNIX_TIMESTAMP() - " . (int) $idle . ") THEN 1 ELSE 0 END) AS `active`
     FROM `sessions`"
);

$band = $bands[0] ?? array();

$pastAbsolute = (int) ($band['past_absolute'] ?? 0);
$idleOnly = (int) ($band['idle_only'] ?? 0);
$active = (int) ($band['active'] ?? 0);

printf("  Past the absolute timeout (%d days): %d\n", (int) ($absolute / 86400), $pastAbsolute);
printf("  Idle but inside the absolute window: %d\n", $idleOnly);
printf("  Active (within the idle timeout): %d\n", $active);

$live = $idleOnly + $active;

printf("\n  Rows that a live user could still own: %d (%.1f%%)\n", $live, $total > 0 ? ($live / $total) * 100 : 0);
printf("  Rows that are already dead: %d (%.1f%%)\n", $pastAbsolute, $total > 0 ? ($pastAbsolute / $total) * 100 : 0);

// ---- The growth over time -----------------------------------------------
echo "\n--- When the rows were created ---\n\n";

$created = db_select(
    'SELECT FROM_UNIXTIME(MIN(`created_at`)) AS `oldest`, FROM_UNIXTIME(MAX(`created_at`)) AS `newest`
       FROM `sessions`'
);

$c = $created[0] ?? array();

echo '  Oldest row: ' . ($c['oldest'] ?? 'n/a') . "\n";
echo '  Newest row: ' . ($c['newest'] ?? 'n/a') . "\n";

$span = db_select(
    'SELECT DATE(FROM_UNIXTIME(`created_at`)) AS `day`, COUNT(*) AS `count`
       FROM `sessions`
      GROUP BY `day`
      ORDER BY `day` DESC
      LIMIT 14'
);

echo "\n  Rows per day (the last 14 days that have rows):\n";

foreach ($span as $day) {
    printf("    %s  %6d\n", (string) $day['day'], (int) $day['count']);
}

// ---- The rows that belong to nobody --------------------------------------
echo "\n--- The ownership of the rows ---\n\n";

$ownership = db_select(
    'SELECT
        SUM(CASE WHEN `user_id` IS NULL THEN 1 ELSE 0 END) AS `anonymous`,
        SUM(CASE WHEN `user_id` IS NOT NULL THEN 1 ELSE 0 END) AS `owned`
     FROM `sessions`'
);

$o = $ownership[0] ?? array();

$anonymous = (int) ($o['anonymous'] ?? 0);
$owned = (int) ($o['owned'] ?? 0);

printf("  Anonymous (no signed-in user): %d\n", $anonymous);
printf("  Belonging to a signed-in user: %d\n", $owned);

// A session whose user has been deleted is orphaned. The schema has a
// foreign key with ON DELETE CASCADE, so this should be zero.
$orphans = db_select_one(
    'SELECT COUNT(*) AS `c` FROM `sessions` s
       LEFT JOIN `users` u ON u.`id` = s.`user_id`
      WHERE s.`user_id` IS NOT NULL AND u.`id` IS NULL'
);

printf("  Orphaned (the user no longer exists): %d\n", (int) ($orphans['c'] ?? 0));

// ---- The size of the table -----------------------------------------------
echo "\n--- The size of the table ---\n\n";

$size = db_select_one(
    "SELECT
        (SELECT COUNT(*) FROM `sessions`) AS `rows`,
        (SELECT ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions') AS `kb`"
);

printf("  Rows: %d\n", (int) ($size['rows'] ?? 0));
printf("  On disk: %s KB\n", (string) ($size['kb'] ?? '0'));

// ---- What the handler already does --------------------------------------
echo "\n--- What the application already does ---\n\n";

echo '  Session idle timeout:     ' . $idle . ' seconds (' . (int) ($idle / 3600) . " hours)\n";
echo '  Session absolute timeout: ' . $absolute . ' seconds (' . (int) ($absolute / 86400) . " days)\n";
echo "  The handler expires a session when it is read: yes\n";
echo "  A scheduled cleanup job: none (by design, see the report)\n";
echo '  The PHP session garbage collector: gc_probability=' . (int) ini_get('session.gc_probability')
    . ', gc_divisor=' . (int) ini_get('session.gc_divisor') . "\n";
echo "  The handler's own gc() cutoff: the idle timeout, " . $idle . " seconds\n";

// The handler's gc() is the mechanism that would collect the dead rows.
// It is driven by the PHP session garbage collector, which calls it
// occasionally during a request. This is reported, not triggered.
$collectable = db_select_one(
    'SELECT COUNT(*) AS `c` FROM `sessions` WHERE `last_activity` < UNIX_TIMESTAMP() - ' . (int) $idle
);

printf(
    "  Rows the handler's gc() would collect right now: %d\n",
    (int) ($collectable['c'] ?? 0)
);

echo "\n=== No record was changed by this inspection. ===\n";
