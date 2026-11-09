# GreenCorner — Phase 6: performance measurement

**NFR-P-02 (200 concurrent users) was MEASURED at 200 concurrent users.
The measurement is recorded below. What it does and does not establish is
stated in section 6.**

---

## 1. What was measured

A closed-loop load test against the application's product catalogue
(`/products`), which is the heaviest read path: it queries the database,
joins the categories, reads the images and renders the whole listing.

**The model is closed loop.** Each of N worker processes issues one
request, waits for the response to arrive, and only then issues the next
request. This is how a user behaves — nobody clicks again before the page
has loaded. The consequence is that when the server slows down, the
request rate falls with it, which is the correct behaviour for a
measurement and the wrong behaviour for an attack.

The alternative, an open loop with a fixed request rate, would measure the
generator rather than the server: when the server fell behind, the
requests would simply queue up inside the client, and the reported
latency would be the queueing delay rather than the service time.

Each worker keeps its own cookie jar, so the workers behave like
different browsers. The first request of each worker is a warm-up and is
not measured: it pays for the connection setup and for the cold opcode
cache, and counting it would report the cost of starting rather than the
cost of serving.

## 2. The environment the measurement was made in

This matters more than any number in the table, so it comes first.

| | |
|---|---|
| Server | **PHP's built-in development server** (`php -S`), single-threaded |
| PHP | 8.3.36 (ZTS build, **no OPcache**) |
| Database | MySQL 8.4.9, local, `localhost` |
| Machine | 12 logical processors, ~16 GB RAM |
| Port | 8002 (the dedicated Phase 6 server) |

**The built-in development server is single-threaded: it serves one
request at a time.** It is a development tool. The production environment
for this project is Apache with mod_php (`php:8.3-apache`), which spawns
several worker processes and serves requests in parallel.

This means the throughput figures below are a **floor**, not a forecast
of production behaviour. A single-threaded server cannot use more than
one core, so the 12 logical processors on this machine went largely
unused. The concurrency figures are therefore a test of the
*application's* ability to hold 200 sessions open and answer correctly
under pressure — not of the server's ability to serve 200 users in
parallel.

## 3. The measurements

Taken on 2026-11-02 against the catalogue listing. The full record is in
`storage/logs/phase6-load-matrix.json`.

| Concurrent users | Requests | Throughput (rps) | p50 | p95 | p99 | Max | Error rate | Codes |
|---:|---:|---:|---:|---:|---:|---:|---:|---|
| 10 | 300 | 41.63 | 239 ms | 295 ms | 316 ms | 323 ms | **0.00%** | 200 × 300 |
| 25 | 500 | 38.76 | 620 ms | 698 ms | 716 ms | 722 ms | **0.00%** | 200 × 500 |
| 50 | 750 | 33.00 | 1355 ms | 1806 ms | 1896 ms | 1910 ms | **0.00%** | 200 × 750 |
| 100 | 1000 | 29.46 | 3250 ms | 4209 ms | 4363 ms | 4399 ms | **0.00%** | 200 × 1000 |
| **200** | **1000** | **32.15** | **5302 ms** | **6097 ms** | **6163 ms** | **6198 ms** | **0.00%** | **200 × 1000** |
| 300 | 900 | 38.95 | 5188 ms | 8451 ms | 8911 ms | 9151 ms | 5.56% | 200 × 850, 599 × 50 |

### Reading the table

**Throughput is flat at roughly 30–42 requests/second across the whole
range.** This is the signature of a saturated server: the request rate
does not rise with concurrency because the server cannot serve any
faster. The extra users simply wait longer.

**Latency scales almost exactly linearly with concurrency** — 239 ms at
10 users, 620 ms at 25, 1355 ms at 50, 3250 ms at 100, 5302 ms at 200.
That is Little's Law: with a fixed service rate, latency is concurrency
divided by throughput. The application is queuing requests correctly and
never dropping them.

**The error rate is 0.00% up to and including 200 users.** Every one of
the 1000 requests at 200 users returned HTTP 200.

**At 300 users, 50 of the 900 requests failed with status 599** — the
client's own timeout, not an application error. The application returned
HTTP 200 for all 850 requests it actually served (850/850 = 100%). No
request produced a 4xx or a 5xx, and **the application log recorded no
errors of any kind during the entire matrix.**

## 4. Resource usage

Sampled with `Get-CimInstance Win32_Processor` during the runs:

| Run | CPU load | Free memory |
|---|---|---|
| 100 users, catalogue | 27–58% | 3.1–3.9 GB |
| 200 users, catalogue | peak **96%** | stable |

The CPU peaks at 96% during the 200-user run and the memory stays flat.
There is no runaway growth and no leak: the free memory actually rose
slightly across the samples as the run finished and the workers exited.

The application's own log (`storage/logs/`) recorded **zero** errors
during the whole matrix. The server's error log recorded zero PHP
warnings, notices or fatals during the load runs. (It did record 26
warnings earlier, from the Phase 6 functional suite; those were a
separate pre-existing defect, which was found and fixed — see section 7.)

## 5. The NFR-P-02 target

NFR-P-02 requires the application to support **200 concurrent users**.

**At 200 concurrent users the application served 1000 of 1000 requests
with a 0.00% error rate, every response HTTP 200, and no errors in the
log.** The target was reached and sustained for the duration of the run.

The measured p50 response time at that level was 5.3 seconds. That is
slow for a web page, and it is the direct consequence of the
single-threaded development server: with 200 requests queued behind one
worker, the last one waits roughly 200 × 26 ms ≈ 5.2 s. The *application*
is not slow; the *server* is serialising.

## 6. What this establishes, and what it does not

### What it establishes

- The application **holds 200 concurrent sessions open** and answers every
  one of them correctly.
- **Zero errors** at 200 concurrent users, over 1000 requests.
- **No resource leak**: memory is flat and the logs are clean across the
  matrix.
- The **ceiling of this configuration** is between 200 and 300 users, and
  the failure mode at 300 is a client timeout rather than an application
  error or a crash.
- The measurement **reproduces**: the matrix is a script
  (`tests/phase6_load_matrix.sh`) and the results are a file, not a
  claim in a document.

### What it does NOT establish

- **It does not measure production performance.** The server is PHP's
  built-in development server, which serves one request at a time and
  has no OPcache. Apache with mod_php — the project's actual production
  configuration — serves requests in parallel across several worker
  processes and has an opcode cache. Production throughput will be
  **higher** than these figures, not lower.
- **It does not establish a latency target.** NFR-P-02 states a
  concurrency, not a response time. The 5.3-second p50 at 200 users is
  reported honestly as a property of the development server, and it would
  be misleading to present it as the application's expected latency.
- **It does not cover the write paths.** The catalogue is the heaviest
  *read* path. Checkout, payment and admin operations were not load
  tested, because load-generating writes against the development
  database would create thousands of test orders and permanently consume
  the daily order-number sequence.
- **The measurements are from a single machine on a development
  database**, with the sample data (28 products, 5 categories) that the
  seed provides. A production catalogue is larger.

### The verdict

**NFR-P-02 was measured, not assumed.** The application met the
200-concurrent-user target in this environment with no errors. Because
the environment is a single-threaded development server, this result
demonstrates that the *application* is sound at that concurrency and
gives a conservative floor for the production configuration — but it is
not a substitute for a load test against the production container, which
should be run once the Docker environment is deployed.

**NFR-P-02 is not marked as passed in the traceability matrix**, because
the measurement was made against the development server rather than the
production deployment. The measurement and its limitation are both
recorded here so that the decision can be made on evidence.

## 7. A defect the load test surfaced

The server error log held 26 PHP warnings, all from the Phase 6
functional suite rather than from the load runs:

```
PHP Warning: Undefined variable $adminOrderTargets in views/admin/orders/show.php on line 164
PHP Warning: Undefined variable $adminOrderCanConfirmCod in views/admin/orders/show.php on line 196
```

The cause was a real defect in the CSRF re-render path.
`helpers/csrf.php` includes the admin order view from two places when a
CSRF token is rejected, and neither of those two paths set
`$adminOrderTargets` or `$adminOrderCanConfirmCod`. The controller sets
them in all three of its paths, so the defect only appeared on a CSRF
rejection — which is exactly the path a security check would exercise.

It was fixed in three places:

1. `helpers/csrf.php` — both CSRF re-render paths now compute the
   permitted statuses and the cash-confirmation flag exactly as the
   controller does.
2. `views/admin/orders/show.php` — the three values are given safe
   defaults, so a future path that includes the view renders an empty
   form instead of raising a warning.
3. A regression check was added to the Phase 6 test suites.

The fix was verified by driving both CSRF-rejection paths directly: both
now return HTTP 422 with a complete 6,601-byte response body, and the
server log records **no** warnings.

This is worth noting on its own terms: the defect was in the *security*
rejection path, and it was found by reading the log rather than by a test
assertion. The security checks passed while the warning was being
written.

## 8. Reproducing the measurement

```
# The dedicated Phase 6 server must be running on port 8002.
bash tests/serve.sh 8002

# The full matrix.
bash tests/phase6_load_matrix.sh

# A single level.
php tests/phase6_load_test.php --url=http://127.0.0.1:8002 --users=100 --requests=10
```

On Windows, the path is passed through the `GC_LOAD_PATH` environment
variable rather than on the command line, because the MSYS shell rewrites
a command-line argument that looks like a path. The wrapper script
handles this.

The results are written to `storage/logs/phase6-load-matrix.json`.

## 9. The files

| File | Purpose |
|---|---|
| `tests/phase6_load_test.php` | The coordinator: spawns the workers, aggregates, reports |
| `tests/phase6_load_worker.php` | One worker: the closed loop |
| `tests/phase6_load_matrix.php` | The measurement matrix across the concurrency levels |
| `tests/phase6_load_matrix.sh` | The Windows-safe wrapper for the matrix |
| `tests/phase6_load_checks.php` | The checks that the tooling is sound |
| `storage/logs/phase6-load-matrix.json` | The recorded results |
