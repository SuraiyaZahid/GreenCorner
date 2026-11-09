# GreenCorner — Implementation Decision Log

Decisions made during implementation, recorded as they are approved.

The three approved documents (`GreenCorner_PRD.md`, `GreenCorner_SRS.md`,
`GreenCorner_Requirement_Traceability_Matrix.md`) are the source of truth for
*what* the product does. They are not modified during implementation. This
log records *how* the approved requirements are realised in code, and which
choices were explicitly approved by the project owner.

---

## D-001 — Database credentials live in an untracked local override

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Applies to:** FR-6.12, SRS §10.1, SEC-1, SEC-6

### Context

`config/config.php` is the single source of truth for environment settings
(SRS §10.1, FR-6.12) and is tracked in version control. In Phase 1 it held
the development database credentials directly, which would put a real
password into Git history if the same file were used for production.

### Decision

Configuration is split into four files:

| File | Tracked? | Purpose |
|---|---|---|
| `config/config.php` | Yes | Non-secret defaults. Carries **no** database password at all. |
| `config/config.example.php` | Yes | Template for a new installation. Placeholder values only. |
| `config/config.local.php` | **No** (`.gitignore`) | The real credentials for this machine or deployment. |
| `config/database.php` | Yes | Loads the configuration safely and fails clearly when a required credential is missing. |

`config/bootstrap.php` is the single entry point for loading configuration:
it defines the `GREENCORNER_APP` guard and then loads everything through
`config/database.php`.

### Rules

1. No real database credential is ever committed to Git.
2. `config/config.local.php` is listed in `.gitignore`.
3. `config/config.example.php` contains placeholders only — no working
   credential for any environment.
4. `config/database.php` is solely responsible for loading configuration and
   for raising a clear error when a required credential is absent.
5. **The tracked `config/config.php` carries no database password.** There is
   no password default to fall back on.
6. **A missing `config/config.local.php` is a fatal error**, as is any empty
   required credential. The application stops with one clear, actionable
   message naming the missing key and the file to create — never a silent
   fallback to an empty or guessed value.
7. `config/` is outside the web root and is additionally denied by
   `config/.htaccess`, and every config file refuses to run when reached
   directly, so `config.local.php` cannot be fetched over HTTP even if the
   document root is misconfigured.
8. Development and production credentials are separate: each installation
   supplies its own `config/config.local.php`.

### Consequences

- A fresh clone has no `config/config.local.php` and therefore fails fast with
  a clear message until one is created from the example file. This is
  intentional: a missing credential must never fall back to a guess, and a
  deployment that forgets the file cannot silently start with the
  development password.
- The application still reads every setting through the same constants, so no
  consumer of the configuration changes.

---

## D-002 — Application settings are read through constants, not arrays

**Status:** Implemented (no owner decision required)
**Applies to:** FR-6.12, SRS §10.1

`config/database.php` defines the constants (`DB_HOST`, `DB_USER`, …) that
the rest of the application already uses. This keeps every consumer
unchanged and keeps the loader in exactly one place.

---

## D-003 — A failed login re-renders the login form with an inline error

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Applies to:** FR-1.5, ER-2, SEC-12, SEC-13

### Decision

A failed login **re-renders the login form** with the error shown inline,
instead of redirecting to the login page with a flash message.

- The message is the same generic text for an unknown email address and for
  an incorrect password, so account existence is never disclosed (SEC-12).
- The **email address the visitor typed is preserved** in the field, so they
  do not have to type it again.
- The **password field is always cleared** and never re-populated.

### Consequences

- A failed login is not a redirect: the response is the login page with the
  error rendered inline.
- The login form view must render an optional inline error and an optional
  preserved email.

---

## D-004 — A CSRF failure re-renders the submitted form with HTTP 422

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Applies to:** FR-7.7, NFR-S-04

### Decision

A rejected CSRF token on a **form post** re-renders the submitted form with
**HTTP 422** and a clear error message. It does **not** redirect, because a
redirect would replace the required 422 response with a 302.

- Safe inputs are preserved where appropriate (for example the email address
  on the login form).
- **Passwords and other sensitive values are never preserved.** The password
  field is always cleared.
- A JSON endpoint still receives the JSON 422 body with the machine-readable
  `invalid_csrf_token` code (SRS §9.4).

### Consequences

- The check stays in one place, but the rendering of the rejected form is per
  form, because each form has its own view and its own safe inputs.
- The re-render happens before any state change, so a rejected request
  writes nothing.

### Implementation

- `helpers/csrf.php` — `csrf_require(bool $isJsonRequest, string $form)`
  accepts the name of the submitted form. On rejection it sets HTTP 422 and
  calls `csrf_render_rejected_form()`, which re-renders the matching view
  with `$_SESSION['csrf_error']` set.
- `controllers/AuthController.php` — `register()`, `login()`, `logout()` and
  `changePassword()` each pass their form name to `csrf_require()`.
- `views/auth/login.php`, `views/auth/register.php`,
  `views/auth/change_password.php` — each renders the `csrf_error` alert.
- Preserved inputs: name and email on the registration form, email on the
  login form. **Nothing** is preserved on the change-password form, because
  all three of its fields are passwords.

---

## D-005 — Login rate limiting is database-backed

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Implemented:** 2026-11-02 (Phase 2)
**Applies to:** NFR-S-06, SEC-13

### Decision

The final implementation stores failed-login attempts in the **database**, not
in JSON files under `storage/login_attempts/`.

- Phase 2 adds the required table and its migration to
  `database/schema.sql`.
- The JSON-file implementation (`models/LoginAttempt.php`) is removed once the
  database-backed version is in place.
- The **policy is unchanged**: 5 failed attempts from one source address, then
  a 15-minute lockout (`LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_SECONDS` in
  `config/config.php`). The SRS does not specify otherwise.

### Consequences

- The counters combine correctly across more than one application server,
  and concurrent writes no longer depend on a file lock.
- Phase 1 continues to use the JSON-file store, because the table does not
  exist yet. The model interface is unchanged, so the swap in Phase 2 is
  confined to `models/LoginAttempt.php`.

### Implementation

- `database/schema.sql` — the `login_attempts` table: `id`, `ip_hash`
  (CHAR(64)), `email_hash` (CHAR(64), nullable) and `created_at`
  (INT UNSIGNED, a Unix timestamp). The index
  `idx_login_attempts_ip_created` supports the windowed count.
- `database/migrations/002_login_attempts.sql` — the migration for an
  existing database. It is idempotent (`CREATE TABLE IF NOT EXISTS`), and
  `database/migrate.php` applies it and records it in `schema_migrations`.
- `models/LoginAttempt.php` — rewritten to use prepared statements against
  the table. The public interface is unchanged, so `controllers/AuthController.php`
  needed no structural change (it now passes the attempted email address as
  well, so the account can also be traced).
- **One row per failed attempt**, rather than a single counter. This makes
  the 15-minute window slide correctly: an attempt that falls out of the
  window stops counting on its own, without a scheduled cleanup job.
- **The source address and the email address are stored only as SHA-256
  hashes.** The raw values are personal data (SEC-7) and never reach the
  database. The address comes from `REMOTE_ADDR` alone — proxy headers are
  ignored, because they are supplied by the client and would let an attacker
  choose their own identity for the rate limiter.
- The JSON-file store is removed: `storage/login_attempts/` is deleted and
  its `.gitignore` rule is dropped.

---

## D-006 — A forced password change blocks account pages, not the storefront

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Applies to:** FR-1.9, FR-6.11, BR-14

### Decision

An account carrying the `must_change_password` flag may still browse the
**public storefront** — the landing page and the product catalogue. It is
blocked from **protected account pages and other authenticated actions**
until the password is changed.

- **The restriction applies to Admins as well as Users.** An Admin whose
  password must change cannot reach the Admin Dashboard.
- The forced change-password page itself is always reachable, so the account
  is never stranded.
- The flag genuinely blocks navigation: it is not merely hidden in the
  navigation.

### Consequences

- `enforce_password_change()` must distinguish public storefront routes from
  protected ones, rather than blocking every route except the change-password
  page.
- The "blocked navigation" test must assert that a *protected* page is
  refused while the storefront is still reachable.

### Implementation

- `helpers/authorization.php` — `enforce_password_change()` now consults
  `is_public_storefront_route()`, which matches the requested path against
  `PASSWORD_CHANGE_PUBLIC_ROUTES` (`/`, `/products`, `/product`, `/category`,
  `/catalogue`). A route such as `/product` also covers `/product/12`.
  Everything else still redirects to the change-password page with HTTP 403.
- `helpers/functions.php` — `current_request_path()` is the single place that
  works out the requested path. `public/index.php` uses it for routing and
  `helpers/authorization.php` uses it for the access decision, so routing and
  authorisation can never disagree about what was requested.

---

## D-007 — Phase 2 includes an idempotent sample-data seed

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Implemented:** 2026-11-02 (Phase 2)
**Applies to:** FR-2.1, FR-2.2, FR-3.1

### Decision

Phase 2 includes a seed script that populates the catalogue with:

- **5 categories:** Plants, Seeds, Pots, Tools, Fertilisers.
- **25–30 sample products.**
- **Valid local product images.**
- **No duplicate records when the script is run again** — the seed is
  idempotent, so it may be run repeatedly with the same result.

### Consequences

- The seed script is separate from `database/schema.sql`, which builds the
  structure and seeds the roles.
- The seed is a development and demonstration aid. It must not run in
  production (SRS §4.4), which is the same rule that already applies to the
  role seed.

### Implementation

- `database/seed.php` — creates the 5 categories and 28 products (8 Plants,
  5 Seeds, 6 Pots, 4 Tools, 5 Fertilisers).
- **Categories are matched by name and products by name within their
  category.** A product that already exists is updated in place, so a
  repeated run leaves exactly one row per product and never leaves orphaned
  image files behind. A product whose image files are missing on disk gets
  fresh images, so the seed always leaves the catalogue usable.
- **The images are real files.** Each product gets a generated 1200 × 1200
  JPEG original and the usual 400 × 400 thumbnail, written to
  `storage/uploads/`. The thumbnail is produced by the same
  `write_thumbnail_image()` helper the Admin upload path uses, so a seeded
  product and an uploaded one behave identically.
- **The product names deliberately overlap in words** — "Large Terracotta Pot"
  and "Small Plastic Pot" both contain "pot" — so the FR-2.6 multi-word
  search behaviour can be exercised against real data.
- The script refuses to run when `APP_ENV` is `production`.
- `tests/verify_seed.php` — checks that every seeded product has both image
  files on disk and that no two products share a name.

---

## D-008 — The Admin product list paginates at 20 products per page

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Implemented:** 2026-11-02 (Phase 3)
**Applies to:** FR-6.1, P-15

### Decision

The Admin product list shows **20 products per page**, and the list includes
**both active and soft-deleted products**. A soft-deleted product is clearly
marked with a "Deleted" badge, and an Admin can navigate between the pages.

This is deliberately separate from the storefront's 12-products-per-page
requirement (FR-2.10), which is a different page with a different audience.

### Consequences

- The storefront pagination is unchanged. A visitor still sees 12 products
  per page.
- The Admin list is ordered by `created_at DESC, id DESC`, so the most
  recently added products appear first.
- An out-of-range page number falls back to the last page; a page number
  that is not a number falls back to the first page.
- Soft-deleted products are included in the Admin list because an Admin has
  to be able to see, and restore, a product that the storefront hides.

---

## D-009 — The upload probe route is removed once the real Admin upload endpoint exists

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Implemented:** 2026-11-02 (Phase 3)
**Applies to:** FR-6.2, FR-6.4, FR-2.12

### Decision

The development-only `/__upload_probe` route is **deleted**, together with
its controller. The route existed only to exercise the image pipeline before
the Admin product form existed.

The probe is removed only **after** the real Admin product-upload endpoint
has been implemented and tested with genuine multipart uploads, so no
upload capability is lost with it.

### Consequences

- `controllers/UploadProbeController.php` is deleted and the route is removed
  from `public/index.php`. No test route remains reachable.
- The four Phase 2 upload tests that used the probe were migrated to the
  real Admin endpoint and now live in the Phase 3 suite, where they exercise
  the same code path a real Admin uses.
- A Phase 3 test asserts that the probe route no longer exists.

---

## D-010 — A product's only deletion mechanism is `is_deleted`

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Implemented:** 2026-11-02 (Phase 3)
**Applies to:** FR-2.8, FR-6.5, FR-6.6

### Decision

The `products` table has **no `is_active` column**, and none is added. The
approved deletion mechanism for a product is `is_deleted` alone.

`is_active` does exist on the `users` table, where it backs FR-7.3 and
FR-6.11 (deactivating an account). It is not a product status feature and is
not extended to products.

### Consequences

- The Admin product list shows both active and soft-deleted products, and
  distinguishes them with the `is_deleted` flag (see D-008).
- Restoring a product sets `is_deleted` back to 0.
- A Phase 3 test asserts that the `products` table has no `is_active`
  column and does have `is_deleted`, so an unapproved column cannot be
  introduced silently later.

---

## D-011 — PHP errors are never printed into the response body

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Implemented:** 2026-11-02 (Phase 3)
**Applies to:** NFR-S-03, SEC-2, FR-6.2

### Decision

`display_errors` is **Off** and `log_errors` is **On**, in the development
`php.ini` and in any production configuration. The error details still reach
the error log, so nothing is lost while developing.

### Consequences

- A PHP warning can no longer send the response headers early. This matters
  beyond tidiness: when PHP's `post_max_size` limit is exceeded it emits a
  warning **before** the application script runs, and a warning printed into
  the response body would send the headers early and lock the status code at
  200. The application could then no longer refuse the request with its own
  status.
- An upload whose body exceeds `post_max_size` is therefore refused with
  **HTTP 422** and a clear message ("The upload is too large. Please choose a
  smaller image.") rather than being reported to the Admin as a form with
  missing fields.
- The size check runs **before** the CSRF check, because a body that PHP
  discarded also took the CSRF token with it. Checking the token first would
  report a session problem instead of the real cause.

---

## D-012 — The order number comes from a locked daily sequence counter

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Implemented:** 2026-11-02 (Phase 4)
**Applies to:** FR-4.14, FR-4.15, FR-4.11, BR-6

### Context

Decision C fixes the order number format as `GC-YYYYMMDD-NNNN`, for example
`GC-20261102-0007`. The `NNNN` component is a counter that restarts at
`0001` on each calendar day, so it must be shared between every checkout
that happens on the same day.

Decision D requires the number to be unique under concurrent checkouts, and
permits a sequence table and a migration **if necessary**, as a limited
technical addition documented here.

### Decision

The order number is generated from a dedicated `order_sequence` table
holding one row per calendar day: `sequence_date` (DATE, primary key) and
`last_value` (INT UNSIGNED).

Generation runs **inside the checkout transaction** that also writes the
order row, and the counter row is locked with `SELECT ... FOR UPDATE`
before it is read and incremented. The write is
`UPDATE ... SET last_value = last_value + 1`, executed while the lock is
held.

The `UNIQUE` constraint on `orders.order_number` is **retained** as the
final backstop, and a duplicate-key error is caught, the transaction rolled
back, and the customer returned to the cart with a clear message and a
retry path — no partial order, no orphaned payment row, no stock decrement
left behind.

### Why a counter table is necessary

A `COUNT(*)` over the `orders` table cannot produce a safe sequence. Two
simultaneous checkouts would both read the same count, and both would then
try to insert the same order number; one of them would fail on the unique
constraint rather than receiving a different number. The counter row, with
the row lock held for the rest of the transaction, serialises the two
checkouts so each receives its own number.

### Consequences

- The `order_sequence` table is a **technical support table**, not a
  business entity. It holds no product, customer or order data, and it
  introduces no business behaviour beyond "the order number is unique and
  human-readable". It is not part of the SRS §10.3 business schema; it is
  recorded here as the limited technical addition Decision D permits.
- The counter is per calendar day, so the numbers are ordered within a day
  and the date is visible in the number itself.
- The lock is held for the whole checkout transaction. This is the same
  lock that FR-4.11 needs for the stock re-validation, so the two
  requirements share one critical section rather than competing for two.

### Implementation

- `database/migrations/003_order_sequence.sql` — the migration for an
  existing database. It is idempotent (`CREATE TABLE IF NOT EXISTS`), and
  `database/migrate.php` applies it and records it in `schema_migrations`.
- `database/schema.sql` — the same table, so a fresh installation has it
  without running the migration.
- `models/Order.php` — `Order::nextNumber()` performs the locked
  increment. The `INSERT ... ON DUPLICATE KEY UPDATE` that creates the
  day's row is deliberately a no-op when the row already exists, so the
  first checkout of the day does not race with a second one.
- `models/Checkout.php` — calls `Order::nextNumber()` inside the checkout
  transaction, before the order row is written.


---

## D-013 — The fulfilment-status transitions are enforced in the application layer

### Context

`orders.status` is an `ENUM('placed', 'processing', 'shipped', 'delivered',
'cancelled')` with **no `CHECK` constraint** and **no trigger**. The
database therefore accepts any status value in that set from any status
value. The transition rules of FR-6.8 and BR-7 — forward progression,
backward correction between non-terminal statuses, `cancelled` as a terminal
state, and the refusal of `delivered -> cancelled` — exist nowhere but in
the code.

The database constraint `chk_payment_order_binding` shows the same pattern
for payments: it permits the shape `card/failed -> card/success`, so the
application layer is the only thing standing between those two states
(BR-9).

### Decision

The transition rules live in **one place**, `models/OrderStatus.php`, as
pure functions over the status vocabulary. The service layer
(`models/OrderFulfilment.php`) calls them inside the order-locking
transaction; the controller never re-implements them, and the view never
offers a status that the vocabulary does not permit.

The permitted transitions are:

| From | To |
|------|-----|
| `placed` | `processing`, `cancelled` |
| `processing` | `placed` (correction), `shipped`, `cancelled` |
| `shipped` | `processing` (correction), `delivered`, `cancelled` |
| `delivered` | `shipped` (correction) |
| `cancelled` | — (terminal) |

`delivered -> cancelled` is **refused**, because the customer has already
received the order (FR-6.8 acceptance criterion 3).

### Consequences

- The rules are testable in isolation, without a database or a request.
- The Admin detail page offers **only** the permitted targets, so a
  refused transition is never available in the first place. The 409
  response is the backstop, not the primary control.
- The `ENUM` remains the type constraint it was always meant to be; the
  *business* rules are the application's, because only the application
  knows what "forward" means.

### Implementation

- `models/OrderStatus.php` — the vocabulary and the transition rules.
- `models/OrderFulfilment.php` — the transactional service that applies
  them, restores stock and settles payments.
- `models/Payment.php` — `Payment::updateStatus()` is the only place a
  payment status is changed; it enforces BR-9 and FR-6.9.

---

## D-014 — Operational logs are JSON Lines in `storage/logs/`

### Context

FR-6.8 requires a cancellation whose stock cannot be restored to be "logged
for manual handling" (BR-13), and the Phase 5 authorisation requires an
audit record for Admin-initiated password resets. Both records must be
machine-readable, must never contain secret material, and must never be
reachable over HTTP.

### Decision

A small shared logging facility writes **JSON Lines**: one valid JSON
object per line, so a file can be read and parsed entry by entry without a
full-file parse.

- The log directory is `storage/logs/`, which is outside the web root
  (the document root is `public/`). An `.htaccess` deny rule is written
  into the directory as well, so a misconfigured document root still
  cannot serve the files (SEC-6).
- Entries are written with an **append lock** (`flock`), so two
  concurrent requests cannot interleave halves of a line.
- The file is **rotated at approximately 1 MB**; the previous file is kept
  with a `.1` suffix, so the most recent history is always available and
  the directory cannot grow without bound.
- The directory and the files are created with restrictive permissions
  (0750 and 0640), so the log is readable only by the account the web
  server runs as.
- **Secret-looking keys are dropped, not masked.** A context key whose
  name contains `password`, `passwd`, `pwd`, `secret`, `token`, `csrf`,
  `card_number`, `cardnumber`, `cvv`, `pin`, `authorization` or `cookie`
  is removed from the entry entirely, so a caller cannot accidentally
  write password material into a log file.

### Consequences

- The cancellation log is `storage/logs/order_cancellation.log` and the
  Admin action audit trail is `storage/logs/admin_actions.log`. Both hold
  one JSON object per line.
- The entries carry the order id, the order number, the product id, the
  product name, the quantity and the reason — and no customer personal
  data beyond the order reference, no card data and no password material.
- The audit entry for a password reset carries the timestamp, the Admin's
  user id, the target user's id, the action and the outcome. It never
  carries the temporary password, in any form.

### Implementation

- `helpers/logging.php` — `gc_log()`, `gc_log_read()`, `gc_log_clear()`,
  the secret-key scrubbing and the rotation.
- `models/OrderFulfilment.php` — writes the unrestorable-cancellation
  entry.
- `controllers/AdminUserController.php` — writes the password-reset audit
  entry.

---

## D-015 — An Admin password reset invalidates the target's other sessions

### Context

FR-6.10 lets the Admin set a temporary password on an account. The
password change is the point of the reset: a session established with the
old password must not survive it, or the reset would not actually recover
the account.

### Decision

A successful Admin password reset **deletes every session of the target
account except the one making the request**. The Admin's own session is
unaffected, because the target is a different account.

The forced-change flag (`must_change_password = 1`) is set at the same
time, so the account's next login is routed to the change-password page
before it can reach any other protected page (FR-1.9).

### Consequences

- An old session cannot keep using the account after its password has
  been reset, which is what makes the reset a recovery mechanism rather
  than a formality.
- The reset is a single, atomic operation: the new hash, the forced-change
  flag and the session deletion all happen together.
- The Admin's own session is never invalidated by somebody else's reset,
  so an Admin cannot lock themselves out through this route.

### Implementation

- `helpers/session_handler.php` — `invalidate_other_sessions()`.
- `models/UserAdmin.php` — `setTemporaryPassword()` writes the hash and
  the forced-change flag.
- `controllers/AdminUserController.php` — calls both, and writes the
  audit entry.

---

## D-016 — The Admin order list and user list paginate at 20 per page

### Context

The Admin product list already paginates at 20 per page (D-008). The
approved Phase 5 scope sets the same size for the Admin order list and
the Admin user list, and asks for **no filters** on either list, because
the approved requirements ask for none.

### Decision

Both lists paginate at 20 records per page and carry no filters. The
pagination partial takes the base path from the calling view, so one
partial serves the product, order and user lists.

### Consequences

- The Admin panel is consistent: every Admin list behaves the same way.
- A list with no filters cannot leak a query parameter that reaches the
  database unchecked, and the page count always matches the number of
  records shown.

---

## D-017 — A rejected Profile submission re-renders the page with HTTP 422

### Context

FR-5.5 acceptance criterion 3 requires that an empty or over-length name is
"rejected with an inline message", and FR-5.6 acceptance criterion 1
requires that a wrong current password is "rejected with a message".

Two patterns already existed in the code base:

- The Auth controllers (D-003) store the field errors in the session and
  redirect, so the message is shown on the page the visitor lands on.
- The Admin controllers re-render the form in place with HTTP 422, so the
  message appears in the response to the failing request.

The Profile page could have followed either. The session-and-redirect
approach was chosen first, but it has two drawbacks: the error is not
strictly *inline* in the response to the rejected request, and the error
has to travel through the session, so a message could in principle be shown
against an unrelated later request.

### Decision

A rejected Profile submission re-renders the Profile page **in place** with
HTTP 422, and the field error is shown next to the field it belongs to. No
error is stored in the session. This is the same pattern the Admin
controllers use, and it is the reading of "an inline message" that is
closest to the wording of FR-5.5.

A **successful** Profile submission still redirects, because there is nothing
to show inline and the Post/Redirect/Get pattern avoids the resubmission
problem on a browser refresh.

### Consequences

- The status code of a rejected Profile submission is 422, the same code the
  CSRF handler and the Admin controllers use for a rejected submission.
- The CSRF handler still preserves the name the visitor typed when it
  re-renders the Profile page after a rejected token, because a name is not
  sensitive. Passwords are never preserved (D-004, SEC-13).
- `ProfileController` now renders the view itself, so the controller holds a
  private `renderProfile()` helper. The view's own defaults are unchanged.

---

## D-018 — The error handling is layered, and the Content Security Policy is nonce-based

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Implemented:** 2026-11-02 (Phase 6)
**Applies to:** §15.3, NFR-R-05, AC-44, NFR-S-03, SEC-1, SEC-2, SEC-11

### Context

Two separate problems were found in Phase 6.

**The 500 page could be truncated.** The branded 500 page (P-22) includes the
shared layout, and the shared layout reads the current account from the
database. When the original failure *was* a database failure, rendering the
error page could throw a second exception. The catch block that renders the
500 page sat outside the `try` block that produced it, so the second
exception escaped, the response was cut off part-way through the header, and
the client received a truncated document with a broken status.

**The application had no Content Security Policy.** A strict policy could not
simply be switched on, because a strict policy forbids inline scripts and the
application had one inline script.

### Decision — the error handling is layered

The error handling is now **three layers deep**, and each layer is
independently sufficient:

| Layer | What it is | When it runs |
|---|---|---|
| A | The branded 500 page with the shared layout | The ordinary unexpected error |
| B | A static, self-contained HTML document emitted by `public/index.php` | The branded page cannot be rendered at all |
| C | The server's own error handling | Nothing above could produce a response |

**Layer A.** The shared layout is now defensive about the database:
`safe_current_user()` never throws — it logs the failure server-side and
treats the account as unknown — and the cart badge is wrapped in its own
`try`/`catch`, because a badge is cosmetic and must never blank a page. The
session handler's `read()`, `write()`, `destroy()` and `gc()` are guarded the
same way, so a database outage during session handling cannot turn into an
uncaught fatal error.

**Layer B.** `public/index.php` wraps the rendering of the 500 page in its own
`try`/`catch`. If that rendering fails, a static HTML document is emitted
inline: no database, no session, no `include`, and therefore nothing that can
fail in turn. Any output the branded page had already produced is discarded
first, so the client receives one whole document rather than a fragment
followed by the fallback.

**Layer C.** The server's own handling, which needs nothing from the
application.

The exception detail is **logged, never rendered**, at every layer
(NFR-R-05, D-011). The client receives only the generic message.

### Decision — the Content Security Policy uses a nonce

The policy contains **neither `'unsafe-inline'` nor `'unsafe-eval'`**. This is
possible because the application has exactly one inline script: the small
snippet in `views/layouts/header.php` that publishes the base URL to the front
end.

That script is authorised by a **per-request nonce**: a random value
generated fresh for every response, put in the header, and copied onto the
script tag. A nonce is only valid for the response that carries it, so an
injected script — which cannot know the nonce — is refused by the browser.

The nonce is generated **before any output is produced**. If it were
generated after the first byte had been written, the header could no longer
be sent and the policy would be meaningless.

The policy is:

```
default-src 'self'; script-src 'self' 'nonce-…'; style-src 'self';
img-src 'self'; connect-src 'self'; font-src 'self'; object-src 'none';
base-uri 'self'; form-action 'self'; frame-ancestors 'none'
```

The other response headers — `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Referrer-Policy`, `X-Permitted-Cross-Domain-Policies`
and `Permissions-Policy` — are sent from the **same place**, so that every
response carries them: the normal pages, the 404, the 403, the 500, the JSON
endpoints and the uploaded images alike. A header added to a single view would
leave every other response unprotected.

### Consequences

- The fault-injection routes (`/_fault/…`) exist so that these paths can be
  tested over HTTP rather than asserted about. There are three:
  `/_fault/error` (an ordinary unexpected error), `/_fault/database-outage`
  (the database becomes unreachable mid-request) and
  `/_fault/render-failure` (the shared layout itself fails, so even the
  branded 500 page cannot be rendered). They refuse to act in production and
  for any account that is not an Admin, so they behave as a 404 everywhere
  else.
- The database-outage test **authenticates first**. A logged-out request never
  reaches the database in `current_user()`, so a logged-out outage test would
  pass vacuously and prove nothing.
- The Layer B fallback is reachable only by making the shared layout itself
  fail, which is what the `/_fault/render-failure` route does. Without it,
  Layer B would be untested code.
- The security headers are sent before the session starts and before any
  controller runs, so a failure inside a controller cannot produce a response
  without them.
- The session handler reports a **guarded** write failure as handled rather
  than as a failure. Returning `false` would make PHP emit "session_write_close():
  Failed to write session data" — a warning that added noise to the log
  during exactly the database outage the hardening exists for, without saying
  anything the handler's own log entry had not already said.

---

## D-019 — Backups cover the database and the uploaded files, and exclude the session tables

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Implemented:** 2026-11-02 (Phase 6)
**Applies to:** FR-6.12, SRS §10.1, NFR-R-09

### Context

The catalogue's images live on the filesystem, not in the database. A backup
that captures only the database would restore a shop with 28 products and no
photographs. The two halves of the shop's state therefore have to travel
together, and they have to be restorable **together**.

### Decision

A backup captures **both** the database and `storage/uploads/`, and it is
written as a **single encrypted archive**.

- The database is dumped with `mysqldump --single-transaction --quick
  --routines --triggers --events --hex-blob --set-gtid-purged=OFF`, so the
  dump is consistent without locking the tables.
- The dump **excludes** the `sessions` and `login_attempts` tables. A session
  is a short-lived artefact of one browser, not shop data, and restoring one
  would resurrect live sessions and a stale rate-limit counter (D-005).
- The uploaded files are added to the same archive.
- The archive is encrypted with **GPG symmetric AES256**. The passphrase is
  supplied through the environment, never on the command line.
- The manifest records the row count of every table, the number of files, and
  the tool versions, so a restore can be **verified** rather than assumed.

### Retention

**7 daily, 4 weekly, 12 monthly.** The daily set is the recovery point; the
weekly and monthly sets are the longer tail. Every backup is kept, so the
retention is a superset of what any single tier would hold.

### Where the backups live

- **Never** in the public directory, and never inside the container image.
- The backup directory sits outside the web root, with restrictive
  permissions on both the directory and the files.
- An **offsite copy** is part of the policy: the operator copies each
  archive off the machine. The tooling does not push to a remote host itself,
  because a backup tool that can reach the internet is a backup tool that can
  leak one.

### Restore safety

A restore is **only ever performed against an isolated scratch database and an
isolated storage directory**. The current project database is never
overwritten, and the restore tooling refuses to run against it at all:

- The target database name must end in `_scratch` and must not be the
  project's own database.
- The schema file's `USE` statement is skipped, and the connection is fenced
  with `SELECT DATABASE()` after each step, so the restore can never be
  redirected into the live database by a statement inside a dump.
- A dump that carries a `USE` statement is refused outright.

### Consequences

- The backup and the restore are **verified against each other**: a full
  cycle was executed end to end and the restored database was compared with
  the manifest, table by table and file by file.
- The restore tooling needs an account with `CREATE DATABASE` for the scratch
  schema. The application's own account does not have it, and is not given it.

---

## D-020 — The Docker environment is `php:8.3-apache` with a MySQL 8.0 service

**Status:** Approved and implemented
**Approved:** 2026-11-02
**Implemented:** 2026-11-02 (Phase 6)
**Applies to:** SRS §4.1, §4.4, SC-2, SC-16

### Context

The application needs a web server that honours the `.htaccess` rewrite
rules, the `php_flag` directives in `public/.htaccess`, and the `.htaccess`
deny rules in `config/` and `storage/`. It also needs a database at MySQL
8.0.16 or newer, because the schema uses a `CHECK` constraint that earlier
versions ignore.

### Decision

- The web image is **`php:8.3-apache`**, running mod_php. The alternative,
  `php:8.3-fpm` behind a separate reverse proxy, cannot serve the `.htaccess`
  files as written and would mean rewriting the deployment rules.
- The database service is **`mysql:8.0.40`**, pinned exactly, which satisfies
  the 8.0.16 minimum.
- The document root is `public/`. `config/`, `storage/`, `controllers/`,
  `models/`, `views/`, `helpers/` and `database/` are therefore never
  reachable over HTTP, whether or not the extra `.htaccess` denies are
  honoured.
- The database service is **not** published to the host. The application
  reaches it over the compose network, so a database port on the host is not
  an attack surface.
- The database password is supplied through an environment variable and is
  **never** written into the image or into a tracked file. The image is
  checked to carry no `config/config.local.php`.
- **The entrypoint writes `config/config.local.php` at start-up**, from the
  environment, with mode 640. The application *requires* that file (D-001),
  and the image deliberately does not carry one — so without this step the
  container would refuse to start. Writing it at start-up keeps the password
  out of every image layer and out of version control, while still giving
  the application the configuration it needs. The generated file carries the
  same access guard as the tracked one, so it refuses to run when it is
  reached directly over HTTP.
- The application refuses to run the sample-data seed in production
  (`APP_ENV=production`), and the fault-injection routes refuse to act in
  production (D-018).

### Consequences

- The environment is defined, reviewed and statically validated, but **it was
  not built or started**: the machine used for the work has no container
  runtime. The finding is recorded in `tests/phase6_docker_runtime_probe.ps1`,
  and the Docker configuration is validated by
  `tests/phase6_docker_checks.php` instead. This is stated plainly in the
  Phase 6 report rather than glossed over.
- The image is `mod_php`, so the production server serves requests in
  parallel across several worker processes. The performance measurement in
  `docs/PERFORMANCE_REPORT.md` was taken against PHP's **single-threaded**
  built-in development server, so its figures are a floor, not a forecast.

---

## D-021 — The session housekeeping policy is the handler-based policy, and it is accepted

**Status:** ACCEPTED — 2026-11-02
**Proposed:** 2026-11-02
**Accepted:** 2026-11-02
**Applies to:** FR-1.10, FR-7.6

### Context

The `sessions` table holds one row per browser session. Phase 6 was asked to
assess the session housekeeping and to **report a safe cleanup policy**. It
was explicitly forbidden from deleting existing session records, from running
any cleanup against the current database, from scheduling an automatic
cleanup job, and from modifying existing sessions.

### What was done

The table was **inspected, not changed**. `tests/phase6_session_assessment.php`
reports the row count, the age distribution against the idle and absolute
timeouts, the rows per day, the anonymous/owned/orphaned split, the table
size, and the number of rows the application's own garbage collector would
collect. It writes nothing.

The finding is that the application is already **self-limiting**: the handler
expires a session on read once it is idle for longer than 8 hours or older
than 7 days, and the garbage collector removes the expired rows. The rows that
remain are the residue of the Phase 1–6 test suites, not production data.

### The decision

The full proposal, with the measurement tables and the three options, is in
**`docs/SESSION_HOUSEKEEPING_PROPOSAL.md`**. It was presented for approval on
2026-11-02 and has been **accepted**.

**The decision is Option "no change": the existing handler-based housekeeping
is the intended final policy.**

- **The handler-based policy is accepted as final.** The session handler
  expires a session on read once it has been idle for longer than 8 hours or
  older than 7 days, and PHP's garbage collector removes the expired rows
  opportunistically during a request. This satisfies FR-1.10 in full.
- **Option A (the one-off purge script) is declined.** No purge script is
  written. There is nothing to reclaim: the rows currently present are not
  dead by the application's own definition, so a purge would delete sessions
  that a user could still hold, and it would reclaim no measurable space —
  the table's on-disk size is unchanged by the row count.
- **Option B (the scheduled cleanup job) is declined**, and is recorded here
  as a **deliberate deferral rather than an oversight**. No cron entry, no
  MySQL event scheduler entry, and no scheduled container is configured.
- **Option C (tightening the timeouts) is declined.** It would change a
  documented user-facing behaviour (FR-1.10).

### Consequences

- **No cleanup code has been written.** No purge script exists in
  `database/`, no scheduler directory exists, and no scheduled job is
  configured in `docker-compose.yml`. `tests/phase6_session_checks.php`
  asserts all of this, so a cleanup job cannot be added silently later.
- **No session record has been deleted or modified**, at any point, before
  or after this decision.
- The decision is now **closed** for v1.0. The housekeeper needs no further
  work: the mechanism the requirements ask for is the mechanism that ships.
- `docs/SESSION_HOUSEKEEPING_PROPOSAL.md` is retained as the measurement
  record behind this decision. Its status line has been updated to reflect
  the acceptance.

---

## D-022 — The Docker image is statically verified but has never been built or run

**Status:** VERIFICATION BLOCKED — 2026-11-02
**Raised:** 2026-11-02
**Applies to:** SRS §18 SC-2, NFR-P-02, NFR-PT-01, NFR-PT-02, decision D-020

### Context

D-020 records the Docker environment as `php:8.3-apache` plus a MySQL 8.0
service, and states plainly that the image had not been built. The Phase 7
launch-verification track was authorised to build the image, run the
container, verify its startup behaviour, run the full regression suite against
it, and measure NFR-P-02 against the Apache/mod_php container.

**None of that can be executed on this host.** The blocker was investigated
in full rather than asserted, and the investigation is reproducible.

### What was checked

| Question | Finding |
|---|---|
| Is a container runtime installed? | No. `docker`, `podman`, `nerdctl`, `containerd`, `lima`, `limactl` and `colima` are all absent from `PATH`; no install directory and no container service exists. |
| Can one be installed without elevation? | No. The session is **not** elevated (`IsAdministrator = False`). The Podman Windows installer manifest was retrieved and inspected: `Scope: machine`, `InstallerType: burn`. Every Windows container-runtime installer is machine-scoped. A download-only step succeeded without elevation, but the install itself does not. |
| Is WSL available? | No. `wsl --status` reports *"The Windows Subsystem for Linux is not installed."* `wsl --install` requires elevation and a reboot. Docker Desktop and `podman machine` both need it for a Linux container. |
| Is the CPU capable? | **Yes.** `HypervisorPresent = True`, `VirtualizationBasedSecurityStatus = 2`, and `SecurityServicesRunning = 2` (HVCI) show the Windows hypervisor is already running. `Win32_Processor` reporting `VirtualizationFirmwareEnabled = False` is the *normal* result in that state — the firmware flags are hidden from the guest because the hypervisor owns the hardware. **The processor is not the blocker.** |
| Could a userspace-only runtime work? | No. A Linux container needs a Linux kernel. The shell here is MSYS2/Windows-native (`MINGW64_NT-10.0-26200`), which cannot execute Linux ELF binaries. A rootless Podman machine still needs WSL or Hyper-V, both of which need elevation. |
| Could the build be delegated to a remote builder? | Not authorised, and not attempted. The Docker Hub registry is reachable, but a registry only *serves* images — building and running still need a local runtime. Delegating to a third-party hosted builder would send the project source to an external service, which is outside the approved scope. |

### The blocker, stated precisely

**The Docker build cannot be executed on this host because no Linux container
runtime can be installed without administrator elevation, and the only Linux
hosts available here (WSL and Hyper-V) both require elevation.**

Any one of the following would clear it:

1. An elevated session on this host, followed by `wsl --install` and a reboot,
   then the Docker Desktop or Podman install.
2. A Linux host (or a Linux VM) with Docker, to which the project could be
   copied and the approved Docker configuration run.
3. A remote Docker host with a secured TCP endpoint, reachable from here,
   with `DOCKER_HOST` set.

### Consequences

- **The Docker verification is NOT complete.** The image has never been
  built, the container has never been started, and the container-specific
  behaviour has never been executed.
- **NFR-P-02 remains unmeasured against the production deployment.** The
  existing measurement was taken against PHP's single-threaded built-in
  development server with no OPcache. It remains a **floor**, not a pass, and
  the traceability matrix continues to record it as such.
- **The CI workflow has still never executed.** It remains prepared and
  pending its first run, because the project has no Git repository and
  initialising one has not been authorised.
- **The static verification stands.** `tests/phase6_docker_checks.php` (38
  checks) continues to validate the Dockerfiles structurally, and
  `tests/phase6_ci_checks.php` (25 checks) continues to validate the workflow.
  What static checks cannot reach is the runtime behaviour: the document-root
  move, the entrypoint's generation of `config/config.local.php`, the volume
  permissions, and the `www-data` ownership.
- **The honesty rule of D-020 is extended to the launch-verification
  report.** A missing container runtime is reported as a blocker. It is never
  reported as a pass, and the project is not declared production-ready while
  this item is open.
- The investigation is reproducible with
  **`tests/phase7_docker_runtime_blocker.ps1`**, which produces the full
  record quoted above and changes nothing.

### What was NOT done

- No container runtime was installed, and no configuration was changed to
  attempt one.
- The downloaded Podman installer was inspected and deleted; it was never
  installed.
- No build was attempted against the local development database, and the
  local database was not touched.
- No session record was deleted or modified.
- No scheduled cleanup job was created.
