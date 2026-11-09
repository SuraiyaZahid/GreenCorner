# GreenCorner

An online shop for plants, seeds, pots, tools and fertilisers. Shoppers browse
the catalogue, and store admins manage the catalogue themselves through a
simple admin panel.

GreenCorner is a standalone three-tier web application: a browser front end, a
back-end application tier, and a relational database organised around a
`roles` table that drives authorisation.

**Stack:** plain PHP (MVC), MySQLi, HTML, CSS and vanilla JavaScript. No
framework, no build step, no package manager.

---

## Requirements

| Component | Version |
|---|---|
| PHP | 8.0 or newer, with the `mysqli`, `gd` and `mbstring` extensions |
| MySQL | 5.7+ or MariaDB 10.3+ |
| Web server | Apache with `mod_rewrite`, or the PHP built-in server |

`strict` SQL mode with `STRICT_TRANS_TABLES` is required; the application
verifies this on every connection and refuses to start without it.

The `mbstring` extension is required: field lengths are measured in
characters with `mb_strlen()`, not in bytes. The application checks for
`mysqli`, `gd` and `mbstring` at start-up and stops with a message naming
the missing extension, rather than failing later inside a controller.

---

## Installation

1. **Create the database and the schema.**

   ```bash
   mysql -u root -p < database/schema.sql
   ```

   This creates the tables and seeds the two roles (`User` and `Admin`).

2. **Create the local configuration file.**

   ```bash
   cp config/config.example.php config/config.local.php
   ```

   Then edit `config/config.local.php` and enter your database credentials.
   This file is **not** tracked in version control, so no password ever
   reaches Git. The tracked `config/config.php` carries no database password
   at all: a missing credential is a fatal error with a clear message, never
   a silent fallback (decision D-001).

3. **Run the migrations** (only needed for a database created before
   Phase 2).

   ```bash
   php database/migrate.php
   ```

4. **Run the sample-data seed** (development only).

   ```bash
   php database/seed.php
   ```

   The seed refuses to run when `APP_ENV` is `production`.

5. **Serve the application.**

   ```bash
   php -S 127.0.0.1:8000 -t public public/router.php
   ```

   The application is then at <http://127.0.0.1:8000>.

   For Apache, point the document root at `public/`. The `.htaccess` file in
   that directory rewrites every request to `public/index.php`, and `config/`
   sits outside the web root and is additionally denied by its own
   `.htaccess`.

6. **Configure PHP to keep errors out of the response body.**

   ```
   display_errors = Off
   log_errors = On
   ```

   This is required, not merely recommended (decision D-011). A PHP warning
   printed into the response sends the headers early, and the application can
   then no longer set its own status code — which is how an oversized upload
   would be answered with `200` instead of `422`. The details still reach the
   error log.

---

## Development credentials

Created by `database/seed.php` (FR-6.12, AC-40).

| Role | Email | Password |
|---|---|---|
| Admin | `admin@greencorner.test` | `Admin123!` |
| User | `user@greencorner.test` | `User123!` |

> **Change the default Admin password before any real use.** These accounts
> exist for local development and demonstration only.

---

## Project layout

```
config/          Configuration loader, tracked defaults and local override
controllers/     One controller per area: Auth, Catalog, Admin, Upload, Error,
                 Cart, Checkout, Order, Profile, AdminOrder, AdminUser,
                 FaultInjection (Phase 6 test support)
models/          Data access: Product, ProductAdmin, Category, User, UserAdmin,
                 Cart, Order, OrderStatus, OrderFulfilment, Payment, Checkout
helpers/         Database, authorisation, CSRF, sessions, images, functions,
                 logging, checkout snapshot, security headers
views/           Templates, grouped by area
database/        Schema, migrations, the sample-data seed, and the backup and
                 restore tools
public/          The web root: front controller, assets
storage/uploads/ Product images and their generated thumbnails
storage/logs/    Operational logs: the cancellation log and the Admin action
                 audit trail (JSON Lines; outside the web root)
storage/backups/ The encrypted backup archives (outside the web root)
tests/           The test suites and the HTTP test client
docs/            The performance report and the session housekeeping proposal
.github/         The Continuous Integration workflow
Dockerfile       The php:8.3-apache image
docker-compose.yml  The application and the MySQL service
```

---

## Tests

The suites drive the application over HTTP, so the server must be running
first.

```bash
php -S 127.0.0.1:8000 -t public public/router.php &   # in one terminal

php tests/run.php          # Phase 1: authentication, sessions, RBAC
php tests/phase2_tests.php # Phase 2: catalogue, images, rate limiting, seed
php tests/phase3_tests.php # Phase 3: admin panel
php tests/phase4_tests.php # Phase 4: cart, checkout, payment, orders
php tests/phase5_tests.php # Phase 5: profile, admin orders, admin users
php tests/security_checks.php # Security verification across all phases
```

Each suite takes an optional base URL, defaulting to
`http://127.0.0.1:8000`.

The security script is a permanent part of the project: it re-checks the
authentication, authorisation, CSRF, injection, secret-handling and
infrastructure rules on every run, and it is the place where the
Admin-only resources are verified to be refused to a User and to a
visitor.

### Phase 6 suites

Phase 6 adds the HTTP suite and the static tooling checks. The HTTP suite
needs the server running; the static ones do not.

```bash
php tests/phase6_tests.php        # Phase 6: 500/403 pages, CSP, headers

php tests/phase6_docker_checks.php    # the Docker configuration
php tests/phase6_ci_checks.php        # the CI workflow
php tests/phase6_backup_checks.php    # the backup tool
php tests/phase6_restore_fences.php   # the restore safety fences
php tests/phase6_session_checks.php   # the session housekeeping assessment
php tests/phase6_load_checks.php      # the load-measurement tooling
```

Two scripts are run by hand rather than as part of a suite:

```bash
# Is a container runtime available on this machine?
powershell -NoProfile -File tests/phase6_docker_runtime_probe.ps1

# Inspect the sessions table. This writes nothing.
php tests/phase6_session_assessment.php
```

---

## Shopping

Phase 4 adds the customer shopping flow: the cart (P-08), the payment and
checkout page (P-09), the order confirmation page (P-10) and the customer's
order list and order detail pages (P-11, P-12).

The card processor is **simulated in the application**: there is no gateway
and no external service. The card number is validated (13–19 digits, Luhn,
MM/YY or MM/YYYY expiry in the future, three-digit CVV) and then discarded —
it is never stored in the database, never written to a log and never sent
anywhere. Only `4242 4242 4242 4242` succeeds; every other well-formed card
number is declined. Cash on Delivery is offered alongside it.

The order number has the form `GC-YYYYMMDD-NNNN` and comes from a locked
daily sequence counter, so two simultaneous checkouts can never receive the
same number (decision D-012).

---

## Profile and admin management

Phase 5 adds three groups of pages.

**The customer profile (P-13).** A User can see their own name, email
address and registration date, change their name, and change their
password. The password change requires the current password, and changing
it signs the account out on every other device. The email address, the role
and the account status are **not** editable here, and no control for them
is offered (FR-5.5, RBAC-9). The profile is a User-only resource: an Admin
is refused with HTTP 403. A rejected submission is answered with HTTP 422
and the message is shown next to the field it belongs to.

**Admin order management (P-17, P-18).** The Admin Orders List shows every
order with its number, customer, date, fulfilment status, total and
payment status; the payment status is a column of its own, so a Cash on
Delivery payment that is still pending is never confused with a card
payment that has succeeded. The Admin Order Detail page shows the items,
quantities, unit prices, line totals, total, customer, delivery address
snapshot, fulfilment status and payment status, and lets the Admin change
the fulfilment status and confirm that the cash for a Cash on Delivery
order has been collected.

The fulfilment statuses are `placed`, `processing`, `shipped`, `delivered`
and `cancelled`. They move forward one step at a time, may be corrected
backwards between the non-terminal statuses, and `cancelled` is terminal —
in particular `delivered -> cancelled` is refused, because the customer
has already received the order. The status control offers **only** the
permitted next statuses, so a refused transition is never available in the
first place (decision D-013).

Cancelling an order restores the stock of its items, but only where the
product still exists and has not been removed. Any case that cannot be
restored automatically is written to `storage/logs/order_cancellation.log`
for manual handling; the entry records the order, the product and the
reason, and holds no customer personal data, no card data and no password
material.

**Admin user management (P-19, P-20).** The Admin User List shows every
account with its name, email address and registration date. The Admin User
Detail page lets the Admin set a **temporary password** on an account: the
account is then required to change it the next time it signs in, before it
can reach any other protected page, and its other sessions are signed out
at the same time (decision D-015). An Admin can never view, read or
retrieve an existing password, and the Admin interface offers no way to
set a role. Each reset is recorded in `storage/logs/admin_actions.log` with
the timestamp, the Admin, the target account and the outcome — never with
the password itself.

Both operational logs are JSON Lines (one JSON object per line), written
under an append lock and rotated at about 1 MB. They live outside the web
root, so they can never be fetched over HTTP (decision D-014).

> **Account deactivation is deliberately not implemented.** FR-6.11 is a
> P1 requirement and is deferred to a later release. Nothing in this phase
> lets an Admin deactivate or reactivate an account.

---

## Deployment (Docker)

Phase 6 adds a Docker environment for the application and the database.

```bash
cp .env.example .env      # put the database password in .env
docker compose up --build -d
```

The web image is **`php:8.3-apache`**, running mod_php, because the
application's `.htaccess` files carry `php_flag` directives that only Apache
with mod_php honours. The database service is **`mysql:8.0.40`**, pinned
exactly — the schema uses a `CHECK` constraint that MySQL versions before
8.0.16 ignore.

The document root is `public/`, so `config/`, `storage/`, `controllers/`,
`models/`, `views/`, `helpers/` and `database/` are never reachable over
HTTP. The database service is **not** published to the host: the application
reaches it over the compose network.

The database password is supplied through an environment variable and is
never written into the image or into a tracked file. The image is checked to
carry no `config/config.local.php`. The **entrypoint writes that file at
start-up**, from the environment, with mode 640 — so the password is never
committed, never baked into an image layer, and never appears on a command
line, while the application still gets the configuration it requires
(decision D-001).

The application is then at <http://127.0.0.1:8080>.

> **The environment was not built or started.** The machine used for the
> Phase 6 work has no container runtime — Docker, Podman, nerdctl and WSL
> are all absent. The configuration is defined, reviewed and statically
> validated by `tests/phase6_docker_checks.php` (38 checks), but the image
> itself has not been built here. The finding is recorded by
> `tests/phase6_docker_runtime_probe.ps1` and reported in the Phase 6
> completion report rather than glossed over.

---

## Launch verification

A launch-verification pass was authorised after Phase 6. **It could not
complete the Docker-dependent steps, because this host cannot run a Linux
container.** That is recorded as a blocker, not as a pass.

### What was investigated

The question was not merely "is Docker installed?" but "can it be, here?" —
so the answer is specific rather than general:

| Question | Finding |
|---|---|
| Is a runtime installed? | No — `docker`, `podman`, `nerdctl`, `containerd`, `lima`, `limactl` and `colima` are all absent, with no install directory and no container service. |
| Can one be installed without elevation? | No — the session is **not** elevated. The Podman Windows installer manifest was retrieved and inspected: `Scope: machine`, `InstallerType: burn`. Every Windows container-runtime installer is machine-scoped. |
| Is WSL available? | No — `wsl --status` reports *"The Windows Subsystem for Linux is not installed."* `wsl --install` needs elevation and a reboot. |
| Is the CPU capable? | **Yes.** The Windows hypervisor is already running (`HypervisorPresent = True`, VBS/HVCI active), so the processor is **not** the blocker. |
| Could a userspace-only runtime work? | No — the shell here is MSYS2/Windows-native and cannot execute Linux ELF binaries; a rootless Podman machine still needs WSL or Hyper-V. |
| Could a remote builder be used? | Not attempted — it would send the project source to an external service that has not been authorised. |

The full, reproducible record is produced by
**`tests/phase7_docker_runtime_blocker.ps1`**, and the decision is recorded as
**D-022**. Nothing was installed: the downloaded installer was inspected and
deleted, and no container service or program directory was left behind.

### What this blocks

- **The Docker image has never been built, and the container has never been
  started.** The container-specific behaviour — the document-root move, the
  entrypoint's generation of `config/config.local.php`, the volume
  permissions and the `www-data` ownership — has **never been executed**.
  Static checks cannot reach it.
- **NFR-P-02 is still not marked as passed.** It remains measured against
  PHP's single-threaded development server, which is a floor and not a
  forecast.
- **The CI workflow has still never executed.** It remains prepared and
  pending its first run, because the project has no Git repository and
  initialising one has not been authorised.

### What would clear it

Any one of these is sufficient:

1. An elevated session on this host, then `wsl --install` and a reboot,
   then the Docker Desktop or Podman install.
2. A Linux host (or a Linux VM) with Docker, to which the project could be
   copied and the approved configuration run.
3. A remote Docker host with a secured TCP endpoint, reachable from here,
   with `DOCKER_HOST` set.

Until one of these is provided, **the project is not declared
production-ready.**

---

## Backups and restore

A backup captures **both** the database and the uploaded product images, and
writes them as a **single encrypted archive**. The two halves of the shop's
state have to travel together: a database-only backup would restore a shop
with 28 products and no photographs.

```bash
# Create a backup. The passphrase comes from BACKUP_ENCRYPTION_KEY.
BACKUP_ENCRYPTION_KEY='...' php database/backup.php

# Check what would happen, without writing anything.
BACKUP_ENCRYPTION_KEY='...' php database/backup.php --dry-run

# Restore into an isolated scratch database and storage directory.
php database/restore.php --file=<archive> --scratch-db=<name>_scratch \
    --scratch-uploads=<path> --key='...'
```

- The database is dumped with `mysqldump --single-transaction`, so the dump
  is consistent without locking the tables.
- The dump **excludes** the `sessions` and `login_attempts` tables: a session
  is a short-lived artefact of one browser, not shop data.
- The archive is encrypted with **GPG symmetric AES256**. The passphrase is
  supplied through the environment, never on the command line.
- A **manifest** records the row count of every table, the number of files
  and the tool versions, so a restore can be verified rather than assumed.
- Backups are **never** written to the public directory and never inside the
  container image. The backup directory sits outside the web root with
  restrictive permissions on both the directory and the files.
- Retention is **7 daily, 4 weekly, 12 monthly**. An offsite copy of each
  archive is part of the policy; the tooling does not push to a remote host
  itself.

**A restore only ever targets an isolated scratch database and an isolated
storage directory.** The current project database is never overwritten, and
the restore tooling refuses to run against it: the target name must end in
`_scratch`, the schema file's `USE` statement is skipped, the connection is
fenced with `SELECT DATABASE()` after every step, and a dump carrying a
`USE` statement is refused outright (decision D-019).

The whole cycle can be exercised in one command, which creates a real
encrypted backup, restores it into a scratch schema, verifies it against the
manifest, and then removes every scratch artefact:

```bash
SCRATCH_ADMIN_USER=root bash tests/phase6_backup_cycle.sh
```

---

## Performance

**NFR-P-02 (200 concurrent users) was measured, not assumed.**

The measurement is a closed-loop load test against the catalogue listing,
the heaviest read path. At 200 concurrent users: **1000 of 1000 requests
returned HTTP 200, with a 0.00% error rate**, at 32.15 requests/second,
p50 5302 ms, p95 6097 ms. The application log recorded no errors during the
matrix, and the memory use was flat.

The environment matters more than the numbers: the measurement was taken
against **PHP's built-in development server, which is single-threaded and has
no OPcache**. It therefore measures the *application's* ability to hold 200
sessions open and answer correctly, and the throughput figures are a **floor**
— not a forecast of production behaviour. Apache with mod_php, which is what
the Docker image runs, serves requests in parallel across several workers.

**NFR-P-02 is not marked as passed in the traceability matrix**, because the
measurement was not made against the production deployment. The full record,
including what the measurement does *not* establish, is in
**`docs/PERFORMANCE_REPORT.md`**.

**A launch-verification attempt to re-measure this against the running
Apache/mod_php container was blocked**: no container runtime can be installed
on this host without administrator elevation, and WSL — which Docker Desktop
and Podman both need for a Linux container — is not installed. The blocker is
investigated and reproducible (see *Launch verification* above, and
`tests/phase7_docker_runtime_blocker.ps1`).

**The status is therefore unchanged: measured against the development
server, and not marked as passed.** It is not a pass, and it is not a
failure either — it is a floor, with the production measurement still
outstanding. **No figure in this section has been re-stated, re-estimated or
projected; these are the measurements that were actually taken.**

```bash
bash tests/serve.sh 8002          # the dedicated Phase 6 server
bash tests/phase6_load_matrix.sh  # the full matrix
```

---

## Security hardening

Phase 6 added two things to the security posture.

**A Content Security Policy with a nonce.** The policy contains neither
`'unsafe-inline'` nor `'unsafe-eval'`. This is possible because the
application has exactly one inline script — the snippet in the shared
header that publishes the base URL — and it is authorised by a per-request
nonce that an injected script cannot know. The nonce is generated before any
output is produced, so the header can always be sent.

**Layered error handling.** The error handling is three layers deep, and each
layer is independently sufficient: the branded 500 page; a static,
self-contained HTML document emitted by the front controller when the branded
page cannot be rendered at all; and the server's own handling. The exception
detail is **logged, never rendered** — no stack trace, no SQL fragment, no
file path, no credentials. The shared layout is defensive about the
database, so a normal page and a 403 both still render during an outage
(decision D-018).

---

## Continuous integration

`.github/workflows/ci.yml` is prepared and ready. It runs as four jobs that
must all pass — a job that cannot run is a failure, not a skip:

1. **Static checks** — the PHP syntax of every file, the forbidden SQL
   forms, the error-output setting, the absence of a tracked credential, and
   the Phase 6 configuration checks.
2. **Docker build** — the image is built, started, and checked to carry no
   local configuration file.
3. **Test suites** — the application is driven over HTTP against the
   container, exactly as `tests/serve.sh` does locally.
4. **Security checks** — a separate job, so a green test run can never
   quietly hide a red security run.

> **CI is prepared but pending its first run.** The project does not
> currently have an initialised Git repository, so the workflow file has
> never been executed. The workflow is committed to the tree as a file and is
> validated by `tests/phase6_ci_checks.php`, but a workflow can only run once
> the project is a Git repository on GitHub.

---

## Session housekeeping

The `sessions` table was **inspected, not changed**. The application is
already self-limiting: the handler expires a session on read once it is idle
for longer than 8 hours or older than 7 days, and the garbage collector
removes the expired rows.

A cleanup policy was **proposed during Phase 6 and accepted on 2026-11-02**
(decision D-021). **The decision is "no change": the existing handler-based
housekeeping is the final policy.** No purge script exists, no scheduled job
is configured, and no session record has been deleted — before or after the
decision. The one-off purge script and the scheduled cleanup job were both
**declined**, and the scheduled job is recorded as a **deliberate deferral**
rather than an oversight, so that its absence is a decision and not a gap.

The measurements behind the decision are in
**`docs/SESSION_HOUSEKEEPING_PROPOSAL.md`**.

```bash
php tests/phase6_session_assessment.php   # read-only inspection
```

---

## Project status

| Phase | Scope | Status |
|---|---|---|
| Phase 1 | Foundation: registration, login, sessions, roles, centralised RBAC | Complete |
| Phase 2 | Catalogue: categories, products, listing, detail, search, pagination, image upload | Complete |
| Phase 3 | Admin panel: dashboard, product CRUD, category management, image pipeline | Complete |
| Phase 4 | Shopping: cart, payment page, checkout, orders, user dashboard, order confirmation | Complete |
| Phase 5 | Order fulfilment: fulfilment status management, COD confirmation, user list, password reset | **Accepted** — verified 410/410 tests passing, including 32/32 security checks |
| Phase 6 | Quality and launch: security hardening, CSP, Docker, backup/restore, load measurement, CI | **Complete** — verified 619 checks passing, 0 failing. NFR-P-02 measured at 200 users with a 0.00% error rate but **not marked as passed**, because the measurement was made against the development server. The Docker image was **not** built — no container runtime is available on this machine. |

### Launch verification

| Item | Status |
|---|---|
| Session housekeeping policy | **Accepted** (D-021) — the existing handler-based policy is final. No purge script, no scheduled job, no session record deleted or modified. |
| Docker image build and runtime verification | **Blocked** (D-022) — no Linux container runtime can be installed on this host without administrator elevation, and WSL is not installed. The image has never been built; the container has never been started. |
| Container startup, document root, generated configuration, permissions, upload protection, database persistence | **Not verified** — they depend on the build above. |
| Container regression suite (619 checks against the container) | **Not run** — it depends on the build above. |
| Production-container load matrix | **Not run** — it depends on the build above. |
| NFR-P-02 | **Measured, not passed** — the measurement was taken against the single-threaded development server and remains a floor. The traceability matrix is unchanged. |
| CI first execution | **Not run** — the project has no Git repository, and initialising one has not been authorised. The workflow remains prepared and pending its first run. |

**The project is not declared production-ready.** The v1.0 functional scope
and the P0 acceptance criteria are complete, but the launch-verification
steps above remain open.

---

## Implementation decisions

`config/DECISIONS.md` records the decisions that shape the implementation —
configuration handling, login failure behaviour, CSRF handling, rate
limiting, the forced-password-change rule, the sample-data seed, the Admin
list pagination, the removal of the upload probe route, the product deletion
mechanism, the PHP error-output setting, the order-number sequence
counter, the fulfilment-status transition rules, the operational log format,
the session invalidation that follows an Admin password reset, the way
a rejected Profile submission is answered, the layered error handling and the
nonce-based Content Security Policy, the backup and restore policy, the
Docker architecture, the accepted session housekeeping policy, and the
container-verification blocker.

The approved requirements (`GreenCorner_PRD.md`, `GreenCorner_SRS.md` and
`GreenCorner_Requirement_Traceability_Matrix.md`) are the source of truth for
what the product does, and are not modified during implementation.
