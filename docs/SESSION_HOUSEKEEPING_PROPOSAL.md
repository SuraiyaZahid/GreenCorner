# GreenCorner — Session housekeeping proposal

**Status: ACCEPTED on 2026-11-02. The decision is "no change" — the existing
handler-based policy is the final policy. No purge script was written, no
scheduled job was created, and no row was deleted, updated, or otherwise
modified.**

The measurement below is the record behind that decision. The decision itself
is recorded in **`config/DECISIONS.md` as D-021**, which was PROPOSAL and is
now ACCEPTED. Option A (the one-off purge script) and Option B (the scheduled
cleanup job) are both **declined**; Option B is recorded as a deliberate
deferral rather than an oversight.

Phase 6 was instructed to inspect the session table and document a safe
cleanup policy. It was explicitly forbidden from deleting records, from
running cleanup against the current database, from scheduling an automatic
cleanup job, and from modifying existing sessions. This document is the
result of that inspection. No row was deleted, updated, or otherwise
modified.

---

## 1. What was inspected

The inspection was read-only. It read the `sessions` table and the
`information_schema` for its size, and it made no write of any kind.

The inspection can be repeated at any time with:

```
php tests/phase6_session_assessment.php
```

## 2. What the table holds

Measured on 2026-11-02 at 17:06, against the development database:

| Measure | Value |
|---|---|
| Total rows | 20233 |
| On disk | 4144 KB (≈4.0 MB) |
| Oldest row created | 2026-11-02 11:12 |
| Newest row created | 2026-11-02 17:06 |
| Anonymous (no signed-in user) | 16525 |
| Belonging to a signed-in user | 3708 |
| Orphaned (the user no longer exists) | 0 |
| Past the 8-hour idle timeout | 0 |
| Past the 7-day absolute timeout | 0 |
| Rows the handler's `gc()` would collect right now | 0 |

The row count grew from 7174 to 20233 during Phase 6, entirely because the
load test issued thousands of one-shot HTTP requests. The **table size on
disk did not change** — it is still 4144 KB — because the payload is tiny and
the growth is in the row count, not in the data. This is worth noting: the
table's footprint is dominated by the index pages, and those are pre-allocated,
so a table can hold tens of thousands of these rows in the same 4 MB.

Index coverage: `PRIMARY (session_id)`, `idx_sessions_user_id (user_id)`,
`idx_sessions_last_activity (last_activity)`.

Payload sizes: minimum 0 bytes, maximum 403 bytes, average 138 bytes.

## 3. What the application already does

The housekeeping is **already implemented and already correct**. There is no
missing feature here; what exists is a policy decision about whether to add
an *additional* mechanism.

The session handler (`helpers/session_handler.php`) does three things:

1. **Expires a session when it is read.** `read()` destroys the row when it
   has been idle longer than `SESSION_IDLE_TIMEOUT` (8 hours) or older than
   `SESSION_ABSOLUTE_TIMEOUT` (7 days). This is the primary mechanism: a
   session that is never used again is destroyed the moment it is next
   presented.

2. **Refreshes the activity timestamp.** `write()` updates `last_activity`
   on every request, so the idle timeout always counts from the last real
   use of the session. This was confirmed empirically during this
   assessment: a live session issued three requests over four seconds and
   its row showed `last_activity - created_at = 4`, exactly the elapsed
   time. The timestamp is genuinely refreshed, not frozen.

3. **Garbage-collects.** `gc()` deletes rows whose `last_activity` is
   older than the idle timeout. It is driven by PHP's own session garbage
   collector (`gc_probability=1`, `gc_divisor=100`), so it runs
   opportunistically during a request — roughly once per hundred requests.

### Why the table still holds 20233 rows

The rows are **not** abandoned sessions awaiting collection. All of them
are within the 8-hour idle window, and the great majority of them were never
revisited after they were created. They are the residue of the Phase 1–6 test
suites and of the load test, which issue thousands of one-shot HTTP requests;
each request that does not send a session cookie creates a session row that is
never used again.

A live visitor who browses the shop and leaves does exactly the same thing.
This is the normal, expected shape of a session table on a busy site: it
holds the recent past, not the remote past.

## 4. The problem this actually poses

Three points, in order of importance.

**The table is not growing without bound.** The `gc()` mechanism deletes
anything past the idle timeout, so the table is self-limiting at roughly
"8 hours of traffic". At the observed rate this is a few megabytes. That
is not a problem. The measurement confirms it directly: the table grew from
7174 to 20233 rows during Phase 6 and its size on disk did not change at
all.

**The current data is test residue, not production data.** All 20233 rows
were created within a six-hour window by the test suites. A production
deployment would start from an empty table. The number is not a forecast of
production behaviour; it is a measurement of this development database.

**The `sessions` table is excluded from backups** (`BACKUP_EXCLUDED_TABLES`),
so it never appears in a backup or a restore. Nothing in the backup,
restore, or CI story depends on the contents of this table.

## 5. The proposed policy

**Recommended: no change to the running system.**

The existing mechanism already satisfies every requirement the project
carries:

- FR-1.10 requires an 8-hour idle timeout and a 7-day absolute maximum.
  Both are enforced by the handler, on read, for every session.
- No requirement anywhere asks for a scheduled cleanup job.
- Adding one would introduce a background process that writes to the
  database on a timer, which is a new failure mode and a new operational
  surface, in exchange for reclaiming a few megabytes of a table that is
  already bounded.

The following is offered **only** if the reviewer wants the space back
regardless of cost.

### Option A — a one-off administrative purge (recommended if action is wanted)

A single script, run by hand, that deletes rows which are already dead by
the application's own definition. It would be:

```
DELETE FROM `sessions`
 WHERE `last_activity` < UNIX_TIMESTAMP() - 28800
```

with the cutoff bound to `SESSION_IDLE_TIMEOUT` rather than written as a
literal, and the row count reported before and after.

Properties:

- **It deletes nothing that a user could still be using.** The cutoff is
  the same 8 hours the handler uses, and a session past that point is
  already refused by `read()`.
- **It uses `idx_sessions_last_activity`**, so the DELETE is an index scan
  and not a full table lock.
- **It is idempotent and safe to run repeatedly.** Running it now would
  delete **0 rows**, because no row is past the cutoff. That is the honest
  answer for the current data: there is nothing to purge today.
- **It is not scheduled.** No cron, no timer, no background process.

The script would refuse to run without an explicit `--confirm` argument,
would print the row count it is about to delete and require a second
confirmation, and would refuse to run at all against a database whose name
is not the production one.

### Option B — a scheduled cleanup job (not recommended)

A cron entry running the purge from Option A every hour.

Rejected for now, for these reasons:

- It adds a moving part that runs when nobody is watching. The existing
  `gc()` already collects the same rows opportunistically.
- A scheduled write against the database is a new thing that can fail at
  3 a.m. and be noticed at 9 a.m.
- It needs its own monitoring, its own credentials handling, and its own
  entry in the deployment documentation.

If it is wanted later, the right form is not a cron entry but an
`event scheduler` in MySQL or a scheduled container in Docker Compose —
both of which the project already has a place for.

### Option C — tighten the idle timeout (not recommended)

Lowering `SESSION_IDLE_TIMEOUT` would collect the rows sooner, but it
changes a documented user-facing behaviour (FR-1.10, 8 hours of inactivity
before a session ends) and would log out real users who leave a tab open.
That is a product decision, not a housekeeping one, and it is out of scope
for Phase 6.

## 6. What is deliberately NOT proposed

- **No deletion of the current 20233 rows.** They are not dead by the
  application's own definition, so deleting them would mean deleting
  sessions that a user could still hold.
- **No modification of `created_at` or `last_activity`.** The timestamps
  are correct.
- **No change to the session handler's expiry logic.** It is correct, it
  is tested, and it is the mechanism the requirements ask for.
- **No change to the backup exclusion list.** The `sessions` table stays
  out of backups.

## 7. The approval requested, and what was decided

Three separate things were put to the owner on 2026-11-02. The answers:

1. **Confirm that the existing handler-based housekeeping is the intended
   final policy.** → **YES.** Accepted. The item closes with no code change,
   and this document is the record.
2. **If Option A is wanted**, approve the one-off purge script. → **NO.** The
   purge script is declined and will not be written. It would delete 0 rows
   today and would reclaim no measurable space.
3. **Confirm that Option B (scheduled job) is not wanted now.** → **NO.** It
   is recorded as a **deliberate deferral**, not an oversight, so that the
   absence of a scheduled job is a decision rather than a gap.

The decision is recorded in **`config/DECISIONS.md` as D-021**, which now
carries the status **ACCEPTED** with the date of acceptance. D-021 also
asserts what this decision guarantees: no purge script exists, no scheduler
entry is configured, and no session row has been or will be deleted by any
housekeeping mechanism.
