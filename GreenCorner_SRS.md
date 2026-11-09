# GreenCorner – Software Requirements Specification (SRS)

**Version:** 2.0  |  **Status:** Approved (Grilling Session Complete)  |  **Date:** November 1, 2026
**Supersedes:** GreenCorner SRS v1.0 (October 31, 2026)
**Companion documents:** `GreenCorner_PRD.md` (v2.0), `GreenCorner_Requirement_Traceability_Matrix.md`

> **How to read this document.** Every requirement below was resolved through a structured requirements-grilling session. Statements are labelled:
> - **[Confirmed]** — explicitly decided and approved during the session.
> - **[Assumption]** — a reasonable default adopted to close a gap; flagged for review.
> - **[Suggestion]** — an optional improvement, explicitly *not* in v1.0 scope.
>
> **Notation.** `FR-<area>.<n>` = functional requirement. `NFR-<area>.<n>` = non-functional requirement. `BR-<n>` = business rule. `UC-<n>` = use case. `ER-<n>` = entity relationship. `SEC-<n>` = security rule. `P-nn` = page ID (see §4.3). Every FR carries a unique ID, a responsible role, preconditions where applicable, expected system behaviour, and acceptance criteria.

---

## 1. Introduction

### 1.1 Purpose
This document specifies the functional and non-functional requirements of **GreenCorner**, a web-based online shopping application for plants and plant supplies. It is the single technical reference describing what the system must do, how it must behave, and how each requirement will be verified. It is intended for developers, QA engineers, the store owner (product owner), and reviewers.

### 1.2 Scope
GreenCorner allows visitors to register and log in, browse a product catalogue organised by category, view product details with image-led presentation, add items to a persistent cart, select a simulated card payment or Cash on Delivery, complete checkout, and manage purchases from a personal dashboard. The system is built around **role-based access control (RBAC)** with two roles, **User** and **Admin**, where **Admin is strictly a management role and cannot shop**.

### 1.3 Document Conventions
| Convention | Meaning |
|---|---|
| **shall / must** | Mandatory requirement |
| **should** | Recommended but not mandatory |
| **may** | Optional |
| **[Confirmed] / [Assumption] / [Suggestion]** | Provenance of the statement (see header) |
| `P-nn` | Page identifier; full inventory in §4.3 |
| `FR-a.b` | Functional requirement; unique and stable once published |
| `NFR-x-n` | Non-functional requirement |
| `BR-n` | Business rule; enumerated in §12 |
| `UC-n` | Use case; enumerated in §13 |
| `ER-n` | Entity relationship; enumerated in §11 |
| `SEC-n` | Security rule; enumerated in §15 |
| `R-SQL-n` | Database configuration requirement; enumerated in §6.4 |

### 1.4 Intended Audience
Developers, QA engineers, the store owner (product owner), and an external assessor or reviewer.

---

## 2. System Purpose and Scope

### 2.1 System Purpose
GreenCorner provides a structured, image-led catalogue and an end-to-end purchase workflow for a small plant store, together with the management tooling an owner needs to run it without developer assistance.

### 2.2 In Scope for v1.0
- Account registration, login, logout, and server-side session management.
- Centralised role-based authorisation with exactly two roles (`User`, `Admin`).
- Product catalogue: categories, listing, category filter, name search, pagination, newest-first ordering, product detail, soft delete.
- Product image pipeline: mandatory image on create, original plus generated thumbnail, format and size validation.
- Database-backed persistent cart with quantity management, removal, price-change and stock notifications.
- Checkout with a delivery-address snapshot, simulated card payment (success **and** decline) and Cash on Delivery.
- Order fulfilment status management by Admin, including COD cash confirmation.
- User dashboard: recent orders, full order detail, profile name and password editing.
- Admin panel: dashboard counters, product CRUD, category CRUD with manual reassignment, order list and detail, user list, admin-initiated password reset.
- Seed/bootstrap script with sample catalogue data.

### 2.3 Out of Scope for v1.0
- Real payment gateway integration (a simulator stands in; the interface is designed so a gateway can replace it later).
- Tax calculation, tax display, and formal tax invoice generation.
- Discount codes, coupons, and promotions.
- Product reviews and ratings; wishlists.
- Shipping-rate calculation; courier or tracking API integration.
- Multi-vendor marketplace; multi-currency.
- Native mobile applications; multi-language support.
- **Email self-service password reset** (retired from v1.0; no SMTP dependency).
- **Customer-initiated order cancellation** (Admin-only in v1.0).
- **Guest checkout** (registration and login are required before shopping).
- **Fuzzy / typo-tolerant search.**
- **A dynamic permissions-management system** (role capabilities are fixed in the application layer).
- **Account deactivation / reactivation** — behaviour fully specified (BR-14, FR-6.11) but implementation deferred to **P1**.

### 2.4 Retired and Superseded v1.0 Requirements
| v1.0 FR | v1.0 text (abridged) | Disposition |
|---|---|---|
| **FR-1.8** | Password reset by email (optional in v1.0) | **Retired — Out of Scope.** No email service dependency. Re-scoped as Admin-initiated reset under **FR-6.10**. |
| **FR-4.8** | "Real card details shall never be stored by the system." | **Superseded by FR-4.9**, which states the prohibition precisely and adds the retention rule for the simulated processor. |
| **FR-3.1** (v1.0 form) | "Clicking Add to Cart shall add the product … and **navigate to the Cart page**." | **Superseded by FR-3.1** (listing → background update) and **FR-3.12** (detail page → navigate to Cart). |
| **Gaps in the catalogue and cart groups** | Soft-delete behaviour, image pipeline, search semantics, pagination, ordering | **Added** as FR-2.8, FR-2.10, FR-2.11, FR-2.12, FR-3.7, FR-3.8. |
| **FR-6.7** (v1.0 form) | "view the user list and, *optionally*, deactivate an account" | **Split** into FR-6.10 (P0) and FR-6.11 (P1, deferred). |

---

## 3. Definitions and Terminology

| Term | Definition |
|---|---|
| **Visitor** | An unauthenticated person browsing the site. |
| **User** | A registered account holding the `User` role. The only role permitted to shop. |
| **Admin** | A registered account holding the `Admin` role. Strictly a management role; **cannot shop**. |
| **RBAC** | Role-Based Access Control — permissions derived from the authenticated account's role. |
| **Cart** | The database-persisted list of products a User intends to buy, keyed by `(user_id, product_id)`. |
| **Cart item** | One row of the cart: a product plus a quantity. |
| **Order** | A confirmed purchase, created at checkout, immutable in its pricing and name snapshot. |
| **Order item** | One line of an order: the product, the quantity, the unit price at purchase, and the product name at purchase. |
| **Fulfilment status** | The lifecycle state of an order: `placed`, `processing`, `shipped`, `delivered`, `cancelled`. |
| **Payment status** | The settlement state of a payment: `pending`, `success`, `failed`. Owned by `payments.status` exclusively. |
| **Payment** | A record in `payments` describing one payment attempt against an order — or a declined attempt with no order. |
| **Soft delete** | Marking a product as deleted via `products.is_deleted = 1`. The row is retained; it is excluded from listings and search and cannot be purchased. |
| **Pending Add-to-Cart** | A product add requested by a Visitor, held in the server session until authentication completes. |
| **Delivery address snapshot** | The address copied verbatim onto an order at checkout, so later address changes never alter order history. |
| **Price at add** | The unit price captured when an item entered the cart. Retained **for change detection only** — the cart always displays and charges the current price. |
| **Thumbnail** | A reduced-size derivative image generated at upload time; used on listing cards. |
| **BDT / ৳** | Bangladeshi Taka. The single store currency for v1.0. |
| **Strict SQL mode** | A MySQL server/connection mode in which invalid or out-of-range data raises an error instead of being coerced, truncated or silently adjusted. |
| **Affected rows** | The number of rows an `INSERT`/`UPDATE`/`DELETE` actually changed. Under default client behaviour an `UPDATE` reports *changed* rows, not *matched* rows. |
| **Warning count** | The number of warnings raised by the most recent statement. |
| **P0 / P1 / P2** | Priority: must-have for launch / should-have, deferred / nice to have. |
| **[Confirmed] / [Assumption] / [Suggestion]** | Provenance labels defined in the document header. |

---

## 4. Overall System Description

### 4.1 Product Perspective
GreenCorner is a **standalone three-tier web application**:

```
┌──────────────────────────────────────────────────────────────┐
│  Presentation tier                                           │
│  Server-rendered HTML/CSS · targeted JavaScript              │
│  AJAX/JSON endpoints for background cart operations          │
└───────────────────────┬──────────────────────────────────────┘
                        │ HTTPS
┌───────────────────────▼──────────────────────────────────────┐
│  Application tier — plain PHP MVC                            │
│  Router → Controllers → Services → Data access (MySQLi)      │
│  Central authorisation helper · Custom DB session handler    │
└───────────────────────┬──────────────────────────────────────┘
                        │ MySQLi over TCP/TLS
┌───────────────────────▼──────────────────────────────────────┐
│  Data tier — MySQL 8.0.16+                                   │
│  roles · users · categories · products · cart_items ·        │
│  orders · order_items · payments                             │
└──────────────────────────────────────────────────────────────┘
```

**[Confirmed]** The stack is **plain PHP MVC** with **MySQLi** and **MySQL 8.0.16+**. The front end is **server-rendered HTML/CSS** with **targeted JavaScript** and a small set of **AJAX/JSON endpoints** for background cart operations. There is **no Laravel, no Blade, no React, no Vue, no Inertia.js and no SPA**. The application is **not** an API-first or headless system.

> **[Confirmed]** The project directory contained no pre-existing PHP implementation when this specification was written. The architecture above is the **planned implementation target**, not a description of existing code.

### 4.2 Application Modules
| Module | Responsibility | FRs |
|---|---|---|
| Authentication | Registration, login, logout, forced password change, admin-initiated reset | FR-1.1 … FR-1.10 |
| Authorisation | Central role→action mapping, session validation, account-status checks | FR-7.1 … FR-7.7 |
| Catalogue | Listing, filtering, search, pagination, sorting, detail, soft delete | FR-2.1 … FR-2.13 |
| Image pipeline | Upload validation, original + thumbnail generation, atomic persistence | FR-2.12, FR-6.4, SEC-3 … SEC-6 |
| Cart | Add, quantity, remove, persistence, price/stock reconciliation | FR-3.1 … FR-3.12 |
| Checkout & Payment | Address capture, card simulation, COD, order creation, transactional stock | FR-4.1 … FR-4.16 |
| Orders & Fulfilment | Order read models, status transitions, COD confirmation, cancellation | FR-5.1 … FR-5.7, FR-6.7 … FR-6.9 |
| Admin Panel | Dashboards, product/category/order/user management | FR-6.1 … FR-6.12 |
| Profile | Self-service name and password change | FR-5.5, FR-5.6 |
| Bootstrap | Idempotent, development-only seed script | FR-6.12 |

### 4.3 Page Inventory — 22 Pages

Every page maps to at least one requirement. 403 and 404 behaviour is fully accounted for.

**Public**
| # | Page | Purpose | Requirement IDs |
|---|---|---|---|
| P-01 | Landing | Store name, short introduction, category strip, Browse Products CTA | FR-2.1, FR-2.4 |
| P-02 | Register | Create an account | FR-1.1, FR-1.2, FR-1.3, FR-1.4 |
| P-03 | Login | Sign in; resumes a pending Add-to-Cart action | FR-1.5, FR-1.7, FR-3.4 |
| P-04 | Forced Change Password | Shown when a temporary password must be changed; blocks normal navigation | FR-1.9 |
| P-05 | Product Listing | Catalogue with search, category filter, pagination, Add to Cart | FR-2.1 … FR-2.11 |
| P-06 | Product Detail | Original image, description, price, stock, category, Add to Cart | FR-2.7, FR-2.12, FR-3.12 |
| P-07 | 404 Not Found | Unknown URL; also the "Product no longer available" response | FR-2.8, FR-2.13 |

**Shopping**
| # | Page | Purpose | Requirement IDs |
|---|---|---|---|
| P-08 | Cart | Line items, totals, quantity changes, removal, price/stock notifications | FR-3.2, FR-3.5 … FR-3.11 |
| P-09 | Payment / Checkout | Order summary, delivery address, Card/COD selection, Checkout | FR-4.1 … FR-4.8 |
| P-10 | Order Confirmation | Order number, itemised summary, total, method, payment status, dashboard link | FR-4.13, FR-4.14 |

**User**
| # | Page | Purpose | Requirement IDs |
|---|---|---|---|
| P-11 | User Dashboard | Greeting, recent orders with status, quick links | FR-5.1, FR-5.3, FR-5.4, FR-5.7 |
| P-12 | Customer Order Detail | Items, totals, date, status — including cancelled orders | FR-5.2, FR-5.7 |
| P-13 | Profile / Account Settings | Edit name; change password with current password | FR-5.5, FR-5.6 |

**Admin**
| # | Page | Purpose | Requirement IDs |
|---|---|---|---|
| P-14 | Admin Dashboard | Counts of products, orders, users | FR-6.1 |
| P-15 | Admin Product Management | Add, update, remove products; image upload | FR-6.2 … FR-6.5 |
| P-16 | Admin Category Management | Add, rename, remove categories; manual reassignment | FR-6.6 |
| P-17 | Admin Orders List | All orders, fulfilment status, payment status | FR-6.7 |
| P-18 | Admin Order Detail | Items, totals, delivery address, status transitions, COD confirmation | FR-6.8, FR-6.9 |
| P-19 | Admin User List | Name, email, registration date | FR-6.10 |
| P-20 | Admin User Detail | Password-reset initiation; deactivation/reactivation controls | FR-6.10, FR-6.11 |

**System**
| # | Page | Purpose | Requirement IDs |
|---|---|---|---|
| P-21 | 403 Access Denied | Role-based denial, in either direction | FR-7.4, FR-7.5, §15.2 |
| P-22 | Error (500) | Unexpected server error with a safe generic message | §15.3, NFR-R-05 |

### 4.4 Operating Modes and Environments
| Mode | Configuration |
|---|---|
| **Development** | HTTP permitted; session cookie `Secure` flag **disabled**; seeded sample catalogue; placeholder plant images; verbose error output permitted. |
| **Production** | HTTPS required; session cookie `Secure` flag **enabled**; seed script **refuses to run**; generic error pages only. |

**[Confirmed]** The `Secure` cookie flag is bound to the environment: enabled whenever the site is served over HTTPS, and disabled only for local HTTP development.

---

## 5. User Classes

| Class | Description | Effective privileges |
|---|---|---|
| **Visitor** | Unauthenticated | Landing page, product listing, product detail, category filter, search, pagination, registration, login. |
| **User** | Registered, `role = User` | Everything a Visitor can, **plus**: add/remove/change cart items, proceed to payment, checkout, order confirmation, user dashboard, order history, customer order detail, profile editing, change own password. |
| **Admin** | Registered, `role = Admin` | Everything a Visitor can **for viewing only**, **plus**: admin dashboard, product create/update/soft-delete, category create/rename/delete, order list and detail, fulfilment status transitions, COD cash confirmation, user list, admin-initiated password reset, and — at **P1** — account deactivation/reactivation. **Admin cannot shop**: no cart, no payment, no checkout, no user dashboard, no customer order history. |

**[Confirmed]** Each account holds exactly one role. Role capabilities are defined in **one** place in the application layer (PRD §5.4) and are enforced server-side on every protected request.

### 5.1 Capability Matrix (normative)
| Capability | Visitor | User | Admin |
|---|---|---|---|
| Browse products and categories | Yes | Yes | Yes |
| View product detail | Yes | Yes | Yes |
| Register / Log in | Yes | n/a | n/a |
| Add to cart / remove from cart | No | **Yes** | **No** |
| Proceed to payment / checkout | No | **Yes** | **No** |
| User Dashboard and order history | No | **Yes** | **No** |
| Add / update / soft-delete products | No | No | Yes |
| Manage categories | No | No | Yes |
| View all orders and users | No | No | Yes |
| Update order fulfilment status | No | No | Yes |
| Confirm COD cash collection | No | No | Yes |
| Reset a user's password | No | No | Yes |
| Deactivate / reactivate an account | No | No | **P1 — deferred** |

---

## 6. Operating Environment

### 6.1 Hardware
Standard client devices (desktop, tablet, smartphone) and a standard server host. No special hardware is required.

### 6.2 Software

| Component | Requirement |
|---|---|
| **PHP** | A modern supported PHP release with the **MySQLi** extension, plus the extensions required for image handling (`GD` or `Imagick`) and for password hashing (`password_hash`). |
| **Database** | **MySQL 8.0.16 or later.** The 8.0.16 floor is mandatory because `CHECK` constraints are only *enforced* from 8.0.16 — before that they are parsed but ignored. |
| **Web server** | Apache or Nginx, routing all requests to the front controller. |
| **TLS** | HTTPS with a valid certificate in production. |
| **Containerisation** | Docker for production packaging and portability. |
| **Not required** | Email/SMTP service, payment gateway account, courier API, Redis, message broker, CDN. |

### 6.3 Supported Clients
The latest two versions of Chrome, Edge, Firefox and Safari, plus mobile browsers on iOS Safari and Android Chrome. The layout is responsive from **360 px** viewport width upward.

### 6.4 Database Configuration Requirements (normative)

**[Confirmed]** The database must operate under **strict SQL mode**. This is a deployment requirement, not a code preference.

| ID | Requirement |
|---|---|
| **R-SQL-1** | `sql_mode` **includes `STRICT_TRANS_TABLES`** at the **server level** (in the MySQL server configuration file), so every connection inherits it. |
| **R-SQL-2** | The application additionally issues `SET SESSION sql_mode = 'STRICT_TRANS_TABLES,…'` on **every** connection, explicitly naming `STRICT_TRANS_TABLES` in the session mode string. |
| **R-SQL-3** | The application **verifies** the effective mode after connecting by reading `@@SESSION.sql_mode` and asserting that `STRICT_TRANS_TABLES` is present. A connection whose session mode lacks it is **rejected**, and the failure is logged. |
| **R-SQL-4** | The application **must not weaken** the session mode after R-SQL-3 has passed. No code path may remove `STRICT_TRANS_TABLES` or otherwise relax the mode. |
| **R-SQL-5** | An automated integration test asserts that a connection reports `STRICT_TRANS_TABLES` in `@@SESSION.sql_mode`, and that an out-of-range or invalid insert raises an error rather than being coerced. |
| **R-SQL-6** | **Rationale.** Session mode is set by the connecting client and is protected by no special privilege — any client able to connect can weaken it. The defence is therefore layered: configure it server-side, assert it per connection, refuse to weaken it in code, and verify it in a test. R-SQL-1 alone is necessary but not sufficient; **R-SQL-3 is the effective control**. |

**R-SQL-7 — `IGNORE` variants are prohibited.** `INSERT IGNORE` and `UPDATE IGNORE` **must not** be used for payment inserts or updates, and are to be avoided generally in application code.

> **Technical rationale (verified against the MySQL 8.0 manual).** `IGNORE` does **not** "bypass constraints". When `IGNORE` is active, MySQL **downgrades** the listed errors — including duplicate-key, foreign-key and `CHECK`-constraint violations — to **warnings**, and **skips the offending row**. `CHECK` constraints are explicitly evaluated for `INSERT IGNORE` and `UPDATE IGNORE`, producing a warning when a constraint evaluates to FALSE, with the offending row skipped. Foreign-key violations are likewise downgraded to warnings with the row skipped, so **error 1452 does not remain an error under `IGNORE`**. The real hazard is therefore not "bad rows get written" but the opposite: **the application may assume the payment row was written when it was not.** Errors that `IGNORE` converts to warnings include `ER_DUP_ENTRY` (1062), `ER_NO_REFERENCED_ROW_2` (1452), `ER_ROW_IS_REFERENCED_2` (1451) and `ER_CHECK_CONSTRAINT_VIOLATED` (3819).
>
> **Mandate.** Use ordinary `INSERT` / `UPDATE`. Check the result of every database operation. Handle errors explicitly. Do not rely on warning counts to detect that a write was skipped — verify the write instead.

**R-SQL-8 — Diagnostics.** The application must capture the statement's **warning count immediately after the affected `INSERT`/`UPDATE`, before executing any other query**, and then inspect the warnings if the count is non-zero.

> **Technical detail.** `SHOW COUNT(*) WARNINGS` and `SHOW WARNINGS` are **diagnostic** statements: they report the warning list and do **not** clear it. `SELECT @@warning_count`, by contrast, is **non-diagnostic** and **does** clear the warning list as a side effect. Because that side effect is order-dependent, the count must be read at the correct point in the sequence.

**R-SQL-9 — Affected-rows semantics.** The application must not treat `affected_rows = 0` from an `UPDATE` as proof that the target row does not exist. Under default client behaviour an `UPDATE` reports the number of rows **actually changed**; a row matched but assigned identical values counts as unchanged. To distinguish "matched" from "changed", the application either enables `CLIENT_FOUND_ROWS` on the connection, or first verifies existence with a separate `SELECT`.

**R-SQL-10 — Referenced-row errors.** The application shall interpret foreign-key errors precisely:

| Error | Constant | Meaning | Raised by |
|---|---|---|---|
| **1452** | `ER_NO_REFERENCED_ROW_2` | A child `INSERT`/`UPDATE` referenced a parent row that does not exist. | Child-side write. |
| **1451** | `ER_ROW_IS_REFERENCED_2` | A parent `DELETE`/`UPDATE` was attempted while a child row still references it. | Parent-side delete/update. |

---

## 7. Functional Requirements

### 7.1 Authentication and Registration — P0

---

**FR-1.1 — Register an account**
- **Role:** Visitor
- **Preconditions:** None (the account must not already exist).
- **Behaviour:** The system shall accept a registration submission containing full name, email address and password, and create a `users` row with `role_id` resolving to the `User` role, `is_active = 1`, a salted password hash in `password_hash`, and `created_at` set to the current timestamp. **Registration shall not automatically authenticate the account**; the Visitor logs in as a separate step. The registration form shall render field-level validation errors inline on failure.
- **Priority:** P0
- **Acceptance criteria:**
  1. A Visitor submitting a valid name, email and password sees a success message and can proceed to the login page.
  2. The created user row has `role_id` = the seeded `User` role id, `is_active = 1`, and a `password_hash` — not the plaintext password.
  3. After registration the Visitor is **not** logged in; navigating to a protected page redirects to login.
  4. A submission with any missing or invalid field re-renders the form with an inline error and creates no user row.

---

**FR-1.2 — Unique email**
- **Role:** Visitor
- **Preconditions:** None.
- **Behaviour:** The system shall enforce uniqueness of `users.email`. A registration attempt using an existing email shall be rejected with a clear message stating that the email is already registered. No user row shall be created. The check shall be enforced by a database `UNIQUE` index as well as by application-level validation.
- **Priority:** P0
- **Acceptance criteria:**
  1. Registering with an already-registered email displays "This email is already registered" and does not create a second row.
  2. The database rejects a direct duplicate insert with error **1062 (ER_DUP_ENTRY)**.
  3. The error message shall not reveal any other account information.

---

**FR-1.3 — Password rules and hashing**
- **Role:** Visitor (at registration); User (when changing own password, FR-5.6)
- **Preconditions:** None.
- **Behaviour:** The system shall require passwords of **at least 8 characters** and shall store **only** a salted hash produced by a salted algorithm (`password_hash` with bcrypt or Argon2id). Plaintext passwords shall never be written to the database, logged, or included in an error message.
- **Priority:** P0
- **Acceptance criteria:**
  1. A 7-character password is rejected with a validation message; an 8-character password is accepted.
  2. The stored value is a salted hash; the plaintext does not appear anywhere in the database.
  3. Two users with the same password have different stored hashes (per-hash salt).

---

**FR-1.4 — Role assignment at registration**
- **Role:** Visitor
- **Preconditions:** None.
- **Behaviour:** Every newly registered account shall be assigned the `User` role. **The first registered User shall never be promoted to Admin.** Admin accounts shall be created only by the bootstrap seed script (FR-6.12) or by an existing Admin. The registration request body shall not be able to specify a role; any role field supplied by the client shall be discarded.
- **Priority:** P0
- **Acceptance criteria:**
  1. A newly registered account has the `User` role.
  2. A registration request that includes `role_id` or `role = Admin` still produces a `User`-role account.
  3. The first account registered on a fresh installation is a `User`, not an Admin.

---

**FR-1.5 — Log in**
- **Role:** Visitor
- **Preconditions:** The account exists and is active.
- **Behaviour:** The system shall authenticate the account using email and password, comparing against the stored hash. On success it shall establish a **server-side, database-backed session** (FR-7.6), regenerate the session identifier, and route the account to the dashboard appropriate to its role (FR-1.7). On failure it shall display a **single generic error message that does not reveal whether the email or the password was incorrect**, and shall not disclose whether the email is registered. Login attempts shall be rate-limited (NFR-S-06).
- **Priority:** P0
- **Acceptance criteria:**
  1. Valid credentials establish a session, and the response sets the session cookie with `HttpOnly` and `SameSite=Lax`.
  2. A wrong password and a non-existent email produce the **same** error message.
  3. The session identifier changes on successful login (the previous identifier is invalidated).
  4. Repeated failed attempts from one source are throttled per NFR-S-06.

---

**FR-1.6 — Log out**
- **Role:** User, Admin
- **Preconditions:** An authenticated session exists.
- **Behaviour:** The system shall destroy the server-side session record, invalidate the session identifier, and clear the session cookie. The User shall be redirected to the landing page. The cart is **not** cleared (FR-3.10).
- **Priority:** P0
- **Acceptance criteria:**
  1. After logout, the previous session identifier no longer authenticates the account.
  2. The browser session cookie is cleared.
  3. The cart contents remain available after the next login.

---

**FR-1.7 — Post-login landing**
- **Role:** User, Admin
- **Preconditions:** Authentication succeeded.
- **Behaviour:** The system shall redirect a `User` to the **User Dashboard** (P-11) and an `Admin` to the **Admin Dashboard** (P-14).
- **Priority:** P0
- **Acceptance criteria:**
  1. Logging in as a `User` lands on P-11.
  2. Logging in as an `Admin` lands on P-14 and never on P-11.

---

**FR-1.8 — ~~Password reset by email~~ (RETIRED — Out of Scope)**
- **Disposition:** Removed from v1.0. No email service is a dependency of the system. The capability is re-scoped as Admin-initiated reset under **FR-6.10**. The requirement set for a future email-based reset is recorded in PRD §16, roadmap item 3.

---

**FR-1.9 — Forced password change**
- **Role:** User (and Admin, if an Admin's own temporary password is set)
- **Preconditions:** The account's `must_change_password` flag is set.
- **Behaviour:** When a user authenticates and the account carries a forced-change flag, the system shall route to the **Forced Change Password** page (P-04) and shall **block navigation to any other protected page** until the password is changed. The new password is subject to the FR-1.3 rules. On successful change the flag is cleared and the user proceeds to the appropriate dashboard.
- **Priority:** P0
- **Acceptance criteria:**
  1. A user whose flag is set lands on P-04 after login.
  2. Attempting to navigate to any other protected page while the flag is set returns the user to P-04.
  3. After a valid change, the flag is cleared and P-04 is not shown on subsequent logins.
  4. A password shorter than 8 characters is rejected.

---

**FR-1.10 — Session lifetime and inactivity handling**
- **Role:** User, Admin
- **Preconditions:** An authenticated session exists.
- **Behaviour:** The session shall expire after **8 hours of inactivity**. An **absolute** maximum lifetime of **7 days** applies regardless of activity. Expiry shall be enforced **server-side**, not merely by the cookie's expiry attribute. When a session expires, the user is redirected to login. The **cart is unaffected**, because it lives in the database (FR-3.10).
- **Priority:** P0
- **Acceptance criteria:**
  1. A session idle for more than 8 hours no longer authenticates, and the user is redirected to login.
  2. A session used continuously still fails after 7 days from creation.
  3. The cart is intact after session expiry and re-login.

---

### 7.2 Product Catalogue — P0

---

**FR-2.1 — Product listing page**
- **Role:** Visitor, User, Admin (viewing)
- **Preconditions:** None.
- **Behaviour:** The system shall render a product listing page (P-05) showing, for each product in the current result page, its **thumbnail image, name, price**, and an Add to Cart button. Soft-deleted products shall be excluded from the listing entirely.
- **Priority:** P0
- **Acceptance criteria:**
  1. Each visible card shows a thumbnail, the product name, and the price with two decimal places.
  2. A product with `is_deleted = 1` appears on no listing page, under any category filter, and for any search term.
  3. The query loads only the current page of results, not the full catalogue.

---

**FR-2.2 — Image-led presentation**
- **Role:** Visitor, User, Admin (viewing)
- **Preconditions:** None.
- **Behaviour:** The system shall present each product card in an image-led theme: the product image leads the card, with the product name displayed beneath it. Listing cards shall use the **generated thumbnail**, never the original.
- **Priority:** P0
- **Acceptance criteria:**
  1. The image is the dominant element of the card and appears above the name.
  2. Listing markup references the thumbnail path, not the original path.

---

**FR-2.3 — Category assignment**
- **Role:** Admin (writes); all roles (read)
- **Preconditions:** The category exists.
- **Behaviour:** Every product shall belong to exactly one category. `products.category_id` shall be `NOT NULL` with a foreign key to `categories.id`. There shall be **no reserved "Uncategorised" category**. A category with products cannot be deleted until every product is reassigned (FR-6.6).
- **Priority:** P0
- **Acceptance criteria:**
  1. `products.category_id` is `NOT NULL` and references `categories.id`.
  2. A product cannot be created or saved without a valid category.
  3. No "Uncategorised" category exists in the seeded data.

---

**FR-2.4 — Category filter**
- **Role:** Visitor, User, Admin (viewing)
- **Preconditions:** None.
- **Behaviour:** The listing shall provide a category filter. Selecting a category shall restrict the result set to products in that category, **combined with (AND) any active search term**. The selected category is preserved in the URL and across pagination. A product may appear under only its own category.
- **Priority:** P0
- **Acceptance criteria:**
  1. Selecting a category shows only that category's products.
  2. With a search term active, the result set is the intersection of the category and the search results.
  3. The category persists in the URL and is preserved when moving to another page.

---

**FR-2.5 — Search by name (substring, case-insensitive)**
- **Role:** Visitor, User, Admin (viewing)
- **Preconditions:** None.
- **Behaviour:** The system shall provide a search field matching **product name only**, using a case-insensitive substring comparison. The implementation shall use a plain SQL `LIKE` comparison against a `utf8mb4` column with a **case-insensitive collation** (`_ci`); **fuzzy, full-text and typo-tolerant matching are out of scope**. Leading and trailing whitespace in the search input shall be trimmed before matching.
- **Priority:** P0
- **Acceptance criteria:**
  1. Searching "fern" matches "Boston Fern" and "Fern Leaf Plant".
  2. Searching "FERN" returns the same results as "fern".
  3. Searching a string with no matches returns the empty-result state (FR-2.9).
  4. Description and category text do not cause a product to match.

---

**FR-2.6 — Multi-word search semantics**
- **Role:** Visitor, User, Admin (viewing)
- **Preconditions:** A search term containing two or more words.
- **Behaviour:** A multi-word query shall match a product only when **every** word appears somewhere in the product name. Word matching is on substrings, so "monst" matches "Monstera Deliciosa".
- **Priority:** P0
- **Acceptance criteria:**
  1. "pot large" matches "Large Terracotta Pot" but not "Small Plastic Pot".
  2. "pot ceramic" matches no product whose name lacks one of the two words.

---

**FR-2.7 — Product detail page**
- **Role:** Visitor, User, Admin (viewing)
- **Preconditions:** The product exists and is not soft-deleted.
- **Behaviour:** The system shall render a product detail page (P-06) showing the **original image**, description, price, stock status, and category. The description shall be output-encoded (NFR-S-03). Out-of-stock products shall be visibly marked and their Add to Cart button disabled.
- **Priority:** P0
- **Acceptance criteria:**
  1. The page shows the original-resolution image, description, price, stock state and category name.
  2. A product with `stock = 0` shows an out-of-stock marker and a disabled Add to Cart button.
  3. HTML in the description is rendered as text, not executed.

---

**FR-2.8 — Soft delete and storefront exclusion**
- **Role:** Admin (action); all roles (effect)
- **Preconditions:** The product exists.
- **Behaviour:** Product removal shall **always** be a soft delete: `products.is_deleted` is set to `1`. A soft-deleted product shall be **excluded from product listings and from search results**, shall **not be purchasable**, and shall **not appear in any category filter result**. Requesting the URL of a soft-deleted product shall render the **"Product no longer available"** page (P-07) with **HTTP status 404** and a link to browse other products. The row and all order history referencing it remain intact.
- **Priority:** P0
- **Acceptance criteria:**
  1. After soft deletion the product is absent from the listing, from every category filter and from every search term.
  2. `GET` on its detail URL returns **HTTP 404** with the "Product no longer available" content.
  3. The product row still exists in the database with `is_deleted = 1`.
  4. Past orders that reference the product still display it.

---

**FR-2.9 — Empty search result**
- **Role:** Visitor, User, Admin (viewing)
- **Preconditions:** A search or filter combination yields no products.
- **Behaviour:** The system shall render a clear **"No products found"** message together with a **Clear Search** action that resets the search term (and, if the user chooses, the category filter) and returns to the unfiltered listing.
- **Priority:** P0
- **Acceptance criteria:**
  1. A non-matching query shows the "No products found" state rather than a blank page.
  2. The Clear Search action removes the search term and lists products again.
  3. The message does not appear when results exist.

---

**FR-2.10 — Pagination**
- **Role:** Visitor, User, Admin (viewing)
- **Preconditions:** None.
- **Behaviour:** The listing shall paginate at **12 products per page on all screen sizes**, using classic Previous / numbered / Next controls. The current page number shall be carried in the URL. The active search term and category shall be preserved across page links. **Changing the search term or the category filter shall reset to page 1.** An invalid page number (non-numeric, zero, negative, or beyond the last page) shall fall back to a valid page. Only the current page of products shall be loaded.
- **Priority:** P0
- **Acceptance criteria:**
  1. A catalogue of 25 products produces three pages of 12, 12 and 1.
  2. The page number appears in the URL and can be bookmarked and shared.
  3. Navigating to page 2 with a category filter active keeps both the filter and the filter's results.
  4. Changing the search term from page 3 returns to page 1.
  5. Requesting `?page=abc`, `?page=0`, `?page=-3` or `?page=9999` renders a valid page rather than an error.
  6. Exactly 12 products are queried for a full page.

---

**FR-2.11 — Ordering**
- **Role:** Visitor, User, Admin (viewing)
- **Preconditions:** None.
- **Behaviour:** Products shall be ordered **newest first** (`created_at` descending). No featured flag and no alternative sort options exist in v1.0. The ordering shall be deterministic: products sharing a `created_at` are ordered by `id` descending.
- **Priority:** P0
- **Acceptance criteria:**
  1. The most recently created product appears first.
  2. No sort control is offered.
  3. Products created within the same second still appear in a stable, defined order.

---

**FR-2.12 — Product images**
- **Role:** Admin (writes); all roles (read)
- **Preconditions:** An image file is supplied — mandatory on create.
- **Behaviour:** Each product shall have an image. The system shall store the **original** file and a **generated thumbnail**, using the thumbnail on listing cards and the original on the detail page. Accepted formats: **JPG, PNG, WebP**. Maximum size: **2 MB**. Both file type and actual image content shall be validated server-side (§15.4). Images shall be served with `loading="lazy"` on listing pages. **[Confirmed]** An image is **mandatory when creating** a product; on **update** the existing image may be retained, and a new upload is not required for every edit. See FR-6.4 for the atomic persistence and cleanup rules.
- **Priority:** P0
- **Acceptance criteria:**
  1. Creating a product without an image is refused with a clear message, and no product row is created.
  2. A `.txt` file renamed to `.jpg` is rejected because the actual content is not a valid image.
  3. A file larger than 2 MB is rejected with a message stating the limit.
  4. After a successful upload, both an original and a thumbnail file exist; the listing uses the thumbnail and the detail page uses the original.
  5. Listing images carry `loading="lazy"`.
  6. Updating a product's description without re-uploading an image succeeds and keeps the existing image.

---

**FR-2.13 — Unknown URL**
- **Role:** All
- **Preconditions:** None.
- **Behaviour:** A request to a URL that does not map to a route shall render the **404 Not Found** page (P-07) with **HTTP status 404** and a link back to the catalogue. The same page and status serve the "Product no longer available" response of FR-2.8, with wording adapted to that case.
- **Priority:** P0
- **Acceptance criteria:**
  1. An unknown URL returns HTTP 404 and renders P-07.
  2. The response body contains a navigation path back to the product listing.
  3. No stack trace or internal path is disclosed.

---

### 7.3 Shopping Cart — P0

---

**FR-3.1 — Add to Cart from the listing (background update)**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role; the product is active, not soft-deleted and in stock.
- **Behaviour:** From the **product listing**, Add to Cart shall add the item through a **background (AJAX/JSON) request** that does **not** reload the page. On success the **cart count in the navigation bar shall update without a page reload**, and a **success toast** shall be displayed containing a visible **"View Cart"** action that navigates to the Cart page (P-08). **[Confirmed] This is the hybrid behaviour:** from the listing the page is **not** navigated to; from the product detail page the user **is** navigated to the Cart (FR-3.12). If the product is already in the cart, its quantity is incremented by one and a second row is **never** created (FR-3.3). If the product is soft-deleted or out of stock, the request shall fail with a clear message and the cart shall be unchanged.
- **Priority:** P0
- **Acceptance criteria:**
  1. Clicking Add to Cart on a listing card updates the header cart count in place; the page is not reloaded and the scroll position is not lost.
  2. A success toast appears containing a "View Cart" action; activating it navigates to P-08.
  3. The cart count is correct after a full page reload, proving the background request persisted.
  4. Adding the same product twice yields one cart row with quantity 2.
  5. Adding an out-of-stock product produces an error message and leaves the cart unchanged.
  6. A JSON request without a valid CSRF token is rejected and the cart is unchanged.

---

**FR-3.2 — Cart page line items**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** The Cart page (P-08) shall list each cart item showing its **image (thumbnail), name, unit price, quantity and line total**, and shall display a **grand total** equal to the sum of the line totals computed from the **current** product prices (FR-3.7). An empty cart shall show a message and a link back to the product listing (FR-3.9).
- **Priority:** P0
- **Acceptance criteria:**
  1. Each row shows thumbnail, name, unit price, quantity and line total.
  2. The grand total equals the sum of the line totals.
  3. The displayed unit price is the product's current price, not the price at add.

---

**FR-3.3 — Duplicate prevention**
- **Role:** User
- **Preconditions:** The product is already in the cart.
- **Behaviour:** Adding a product already present in the cart shall **increment the existing row's quantity by one**. A second row for the same product shall never be created. The `cart_items` table shall carry a `UNIQUE (user_id, product_id)` constraint so this holds even under concurrent requests.
- **Priority:** P0
- **Acceptance criteria:**
  1. Adding the same product three times yields one row with quantity 3.
  2. The database rejects a duplicate `(user_id, product_id)` row with error **1062**.
  3. Two simultaneous add requests do not produce two rows.

---

**FR-3.4 — Visitor add-to-cart and deferred execution**
- **Role:** Visitor
- **Preconditions:** The Visitor clicks Add to Cart while unauthenticated.
- **Behaviour:** The Visitor shall be redirected to the login page (P-03). The requested product identifier shall be stored in the **server session** as a pending action. After successful login the system shall: (a) re-read the product; (b) verify it exists, is not soft-deleted and is in stock; (c) if valid, add it to the cart; (d) **clear the pending action**, so it cannot execute again on a later login; (e) if invalid, show a message explaining that the product is unavailable or out of stock. The pending action shall be **single-shot** and shall not be applied twice.
- **Priority:** P0
- **Acceptance criteria:**
  1. A Visitor clicking Add to Cart is redirected to login with the intent retained.
  2. After a successful login the product is in the cart exactly once.
  3. Logging in a second time does **not** add the product again.
  4. If the product was soft-deleted while the visitor was logging in, a message is shown and nothing is added.
  5. If the product is out of stock, a message is shown and nothing is added.

---

**FR-3.5 — Remove a cart item**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** The User shall be able to remove any item from the cart. The removal shall take effect without a full page reload (AJAX/JSON), the cart count shall update in place, and the totals shall be recalculated. Removing the last item leaves an empty cart (FR-3.9).
- **Priority:** P0
- **Acceptance criteria:**
  1. Removing an item removes exactly that row and updates the count without a page reload.
  2. The grand total recalculates immediately.
  3. Removing the final item shows the empty-cart state.

---

**FR-3.6 — Change a cart quantity**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** The User shall be able to change the quantity of any cart item. The minimum is **1**; the maximum is the product's current available stock. A submitted quantity above available stock shall be clamped down to the available quantity, and the User shall be notified (FR-3.8). A submitted quantity below 1 shall be rejected with a validation message. The update shall take effect without a full page reload, and the totals shall be recalculated.
- **Priority:** P0
- **Acceptance criteria:**
  1. Increasing the quantity updates the line total and grand total without a page reload.
  2. Setting the quantity to 0 or a negative number is rejected with a message; the row is not removed as a side effect.
  3. Setting a quantity above available stock clamps to the available quantity and shows a notification.
  4. Setting the quantity of a zero-stock item is not permitted.

---

**FR-3.7 — Current-price display and price-change notification**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** The cart shall always display the **current** product price, and the grand total shall be computed from current prices. The price captured when the item was added (`price_at_add`) shall be retained **for change detection only**. Where the current price differs from `price_at_add`, the cart shall display a **clear notification showing both the old and the new price**, and the line and grand totals shall reflect the new price. The cart shall **not** silently change a price without notification.
- **Priority:** P0
- **Acceptance criteria:**
  1. After an Admin changes a product's price, the cart shows a notification with the old and the new price.
  2. The line total and grand total use the new price.
  3. `price_at_add` is persisted in `cart_items` but is never used to compute a charge.
  4. No notification appears when the price has not changed.

---

**FR-3.8 — Stock reduction and unavailability in the cart**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** Where the product's current available stock is **less than** the cart quantity, the system shall automatically **reduce** the quantity to the available amount and notify the User. Where stock has reached **zero**, the item shall be marked **unavailable** and **checkout is blocked** until the User removes it — the item is **never removed automatically**. Where the product has been soft-deleted, it shall remain visible in the cart with the message **"No longer available"**, and checkout is blocked until it is manually removed.
- **Priority:** P0
- **Acceptance criteria:**
  1. Stock reduced below the cart quantity results in an automatic reduction and a notification.
  2. A zero-stock item shows as unavailable, and the Proceed to Payment / Checkout action is blocked with an explanatory message.
  3. A soft-deleted item remains visible with "No longer available" and blocks checkout.
  4. Neither an unavailable nor a soft-deleted item is removed from the cart automatically.

---

**FR-3.9 — Empty cart**
- **Role:** User
- **Preconditions:** The cart contains no items.
- **Behaviour:** The Cart page shall show a clear empty-cart message and a link back to the product listing. The Proceed to Payment action shall be unavailable.
- **Priority:** P0
- **Acceptance criteria:**
  1. An empty cart shows the message and the link.
  2. The Proceed to Payment action is not offered.

---

**FR-3.10 — Cart persistence**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** The cart shall be stored in the database and shall persist **between sessions**, surviving logout, login and session expiry. Cart contents are keyed by `user_id`; a cart is never keyed to a session identifier.
- **Priority:** P0
- **Acceptance criteria:**
  1. Cart contents survive a logout and a subsequent login.
  2. Cart contents survive session expiry (8 hours) and re-login.
  3. A different account sees a different (or empty) cart.

---

**FR-3.11 — Proceed to Payment**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role and the cart is not empty.
- **Behaviour:** The Cart page shall provide a **Proceed to Payment** button that navigates to the Payment page (P-09). If any cart item is unavailable or out of stock (FR-3.8), the button shall not proceed and an explanatory message shall be shown.
- **Priority:** P0
- **Acceptance criteria:**
  1. The button is present on the Cart page and navigates to P-09.
  2. The button is absent or disabled on an empty cart.
  3. With an unavailable item, proceeding is blocked and the reason is stated.

---

**FR-3.12 — Add to Cart from the product detail page**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role; the product is in stock and not soft-deleted.
- **Behaviour:** From the **product detail page** (P-06), Add to Cart shall add the item and then **navigate to the Cart page** (P-08). This is the deliberate counterpart to FR-3.1, where the listing background-updates instead of navigating. Duplicate prevention (FR-3.3) applies identically.
- **Priority:** P0
- **Acceptance criteria:**
  1. Clicking Add to Cart on the detail page results in the Cart page being displayed with the item present.
  2. Adding the same product twice increments the quantity rather than creating a second row.
  3. An out-of-stock product's button is disabled, and the action is refused server-side.

---

### 7.4 Payment and Checkout — P0

---

**FR-4.1 — Payment page layout**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role; the cart is not empty and contains no unavailable items.
- **Behaviour:** The system shall render the Payment page (P-09) containing an **order summary** (each cart item with name, unit price, quantity and line total, plus the grand total), a **delivery address form**, and a **payment form**.
- **Priority:** P0
- **Acceptance criteria:**
  1. The page shows the order summary, the address form and the payment form.
  2. The summary totals match the Cart page totals at the time of display.
  3. The page is not reachable with an empty cart.

---

**FR-4.2 — Payment method selection**
- **Role:** User
- **Preconditions:** The Payment page is displayed.
- **Behaviour:** The payment method shall be chosen with **clearly visible radio buttons** offering **Card** and **Cash on Delivery**, which are **mutually exclusive**. **Card shall be selected by default.** The card-detail fields shall be **shown** when Card is selected and **hidden immediately** when COD is selected, without a page reload. **Both methods shall be available for every product** — there is no per-product or per-order restriction in v1.0.
- **Priority:** P0
- **Acceptance criteria:**
  1. Two radio buttons are present; exactly one is selected at any time.
  2. Card is selected on first render.
  3. Selecting COD hides the card fields immediately.
  4. Every product in the catalogue can be purchased with either method.

---

**FR-4.3 — Card field validation**
- **Role:** User
- **Preconditions:** Card is the selected method.
- **Behaviour:** Card fields (cardholder name, card number, expiry, CVV) shall be validated **client-side and server-side**. Client-side validation provides immediate feedback; server-side validation is authoritative and rejects the request regardless of what the client did. A **validation failure shall create no payment record at all** — no order, no stock change, no cart change — and shall re-render the form with inline field errors.
- **Priority:** P0
- **Acceptance criteria:**
  1. An empty or malformed card number is rejected with an inline message.
  2. A request that bypasses client-side validation with invalid data is rejected server-side.
  3. After a validation failure, `SELECT COUNT(*) FROM payments` is unchanged — no payment row was written.
  4. The cart is unchanged after a validation failure.

---

**FR-4.4 — Card payment: successful outcome**
- **Role:** User
- **Preconditions:** Card is selected; validation passed; the simulated processor returns a success outcome.
- **Behaviour:** The system shall, within a single database transaction: create the order and its order items, reduce product stock, clear the cart, and insert a `payments` row with `method = 'card'`, `status = 'success'`, `paid_at` set, and `order_id` referencing the new order. The `success_order_id` generated column of the new row shall equal that `order_id`. **[Confirmed]** A successful card payment **shall have a non-NULL `order_id`** — enforced by the database constraint `chk_payment_order_binding` (§10.4).
- **Priority:** P0
- **Acceptance criteria:**
  1. The order and its items exist with the correct quantities and unit prices.
  2. Stock is reduced by the ordered quantities.
  3. The cart is empty afterwards.
  4. Exactly one `payments` row exists with `method='card'`, `status='success'`, `paid_at` non-NULL and `order_id` equal to the new order id.
  5. `success_order_id` is equal to `order_id` in that row.
  6. The User is taken to the Order Confirmation page (FR-4.13).

---

**FR-4.5 — Card payment: declined outcome**
- **Role:** User
- **Preconditions:** Card is selected; validation passed; the simulated processor returns a decline.
- **Behaviour:** The system shall record a **failed** payment with `method = 'card'`, `status = 'failed'`, `paid_at` NULL, and **`order_id` NULL** — a declined attempt references no order. **No order shall be created, stock shall be unchanged, and the cart shall be unchanged.** The User shall be shown a clear decline message, and the Payment page shall remain available for another attempt. The declined attempt **is persisted**, which is what makes the checkout-error-rate metric measurable (PRD §11).
- **Priority:** P0
- **Acceptance criteria:**
  1. No order row is created for the attempt.
  2. Stock quantities are unchanged.
  3. The cart still contains every item, unchanged.
  4. Exactly one `payments` row exists with `method='card'`, `status='failed'`, `order_id` **NULL**, `paid_at` NULL.
  5. A decline message is displayed and the User can retry.

---

**FR-4.6 — Card payment: both outcomes, no "always succeeds" guarantee**
- **Role:** User
- **Preconditions:** None.
- **Behaviour:** **[Confirmed]** The simulated card processor shall support **both** a success outcome and a decline outcome. The system shall **not** assume or guarantee that a card payment succeeds whenever validation passes. The outcome shall be determined by the simulated processor, and the two outcomes shall produce the different end states specified in FR-4.4 and FR-4.5 respectively.
- **Priority:** P0
- **Acceptance criteria:**
  1. At least two deterministic test card numbers exist — one that succeeds and one that declines.
  2. Submitting the declining card follows the FR-4.5 end state.
  3. No code path treats "validation passed" as "payment succeeded".

---

**FR-4.7 — Cash on Delivery**
- **Role:** User
- **Preconditions:** COD is selected; the delivery address is valid.
- **Behaviour:** The system shall create the order **immediately**, within a single transaction, with fulfilment status `placed` and a `payments` row with `method = 'cod'`, `status = 'pending'`, `paid_at` NULL, and `order_id` referencing the new order. Stock **shall be reduced** at creation and the cart **shall be cleared**. The customer is **not** charged at checkout. The order is then visible to the Admin with payment status `pending` (FR-6.7).
- **Priority:** P0
- **Acceptance criteria:**
  1. The order is created with fulfilment status `placed`.
  2. A `payments` row exists with `method='cod'`, `status='pending'`, `order_id` referencing the order and `paid_at` NULL.
  3. Stock is reduced and the cart is empty afterwards.
  4. No card data was captured or stored.

---

**FR-4.8 — Delivery address collection and snapshot**
- **Role:** User
- **Preconditions:** The Payment page is displayed.
- **Behaviour:** The system shall collect a **delivery address** at checkout and store it on the order as an **immutable snapshot** of the address as supplied at purchase time. The snapshot shall include at least the recipient's name, a phone/contact number, a street address, and a city or area; the field set shall be validated server-side and shall be required. The address is **not** read from a stored user profile in v1.0, because no profile address exists. **No courier API and no automatic delivery tracking** exist in v1.0. The stored address is visible to the Admin on the order detail page (FR-6.8).
- **Priority:** P0
- **Acceptance criteria:**
  1. Checkout cannot complete without a valid address.
  2. The address is stored on the order row and is not shared with any other order.
  3. Later address changes elsewhere do not alter a stored order's address.
  4. The Admin order detail page displays the address.

---

**FR-4.9 — No storage of card data** *(supersedes v1.0 FR-4.8)*
- **Role:** All
- **Preconditions:** None.
- **Behaviour:** The system shall **never store** card numbers, expiry dates, CVVs, or any other raw card instrument data, in any table, log file, session record, or error message. Card data shall be passed to the simulated processor and discarded immediately afterwards. The `payments` table shall hold no column capable of holding a card number.
- **Priority:** P0
- **Acceptance criteria:**
  1. No column in the schema stores a card number, expiry or CVV.
  2. A submitted card number does not appear in any log or error output.
  3. The `payments` row for a successful card payment contains only method, status, timestamps and the order reference.

---

**FR-4.10 — Payment method exclusivity and COD availability**
- **Role:** User
- **Preconditions:** None.
- **Behaviour:** Card and COD shall be mutually exclusive within a single checkout — exactly one payment method applies to an order. COD shall be selectable for **every** product; there is no per-product restriction. The system shall record the method actually chosen on the `payments` row.
- **Priority:** P0
- **Acceptance criteria:**
  1. One order has exactly one payment method recorded.
  2. COD is selectable for every product in the catalogue.
  3. The recorded method matches the radio-button selection.

---

**FR-4.11 — Stock re-validation at checkout**
- **Role:** User
- **Preconditions:** The Checkout action is submitted.
- **Behaviour:** Immediately before creating the order, and **inside the same transaction** as the order creation, the system shall re-read and re-validate every cart item's **current price and current stock**. Where a price has changed, the total shall be recomputed and the User shall be shown the updated total before confirming (FR-4.12). Where an item is out of stock or has been soft-deleted, the order shall **not** be created, the transaction shall be rolled back, and the User shall be informed of the specific item. The stock check shall use row locking (e.g. `SELECT … FOR UPDATE`) to prevent overselling under concurrent checkouts.
- **Priority:** P0
- **Acceptance criteria:**
  1. An item that went out of stock between adding and checkout prevents the order from being created.
  2. The User is told which item is the problem.
  3. No partial order, stock reduction or payment row survives the rollback.
  4. Two simultaneous checkouts for the last unit produce exactly one successful order.

---

**FR-4.12 — Price re-validation and updated total**
- **Role:** User
- **Preconditions:** The Checkout action is submitted and a price has changed.
- **Behaviour:** Where the re-validated current price differs from the price shown to the User, the system shall recompute the order total from current prices and present the **updated total to the User for confirmation before the order is committed**. The order line records the **price at purchase** as an immutable snapshot (FR-4.4, BR-6), so historical totals never change.
- **Priority:** P0
- **Acceptance criteria:**
  1. A price change at checkout produces a confirmation step showing the old and new total.
  2. The committed order's line unit prices equal the re-validated current prices.
  3. Once committed, the order total does not change even if the product price later changes.

---

**FR-4.13 — Order Confirmation page**
- **Role:** User
- **Preconditions:** The order was created successfully.
- **Behaviour:** After a successful checkout the system shall render the **Order Confirmation** page (P-10) showing the **order number**, an **itemised summary** (product name, quantity, unit price, line total), the **total**, the **payment method** and the **payment status**, together with a **link to the User Dashboard**. The page is a distinct destination; the User is **not** redirected directly to the dashboard.
- **Priority:** P0
- **Acceptance criteria:**
  1. The confirmation page displays the order number, the itemised summary, the total, the method and the payment status.
  2. The payment status shown is `success` for a card payment and `pending` for a COD payment.
  3. A link to the User Dashboard is present.
  4. The page is not the User Dashboard itself.

---

**FR-4.14 — Order number and identifier**
- **Role:** User, Admin
- **Preconditions:** An order is created.
- **Behaviour:** Each order shall carry a unique, human-readable **order number** suitable for quoting to the store, in addition to its internal numeric primary key. The order number shall be unique across all orders.
- **Priority:** P0
- **Acceptance criteria:**
  1. Every order has a non-null order number.
  2. Two orders can never share an order number (database uniqueness).
  3. The order number is displayed on the confirmation page and in the Admin order list.

---

**FR-4.15 — Transactional checkout**
- **Role:** User
- **Preconditions:** The Checkout action is submitted and validation passes.
- **Behaviour:** Order creation, order-item creation, stock reduction, payment-record insertion and cart clearing shall be performed within **one database transaction** and shall **succeed together or not at all**. If any step fails, the transaction shall be rolled back and the User shall be shown an error. The cart shall be cleared **only** on successful commit.
- **Priority:** P0
- **Acceptance criteria:**
  1. A forced failure mid-checkout leaves no order, no order items, no payment row and unchanged stock.
  2. The cart still contains its items after a failed checkout.
  3. On success all artefacts exist and the cart is empty.

---

**FR-4.16 — Payment status is owned by `payments`**
- **Role:** User, Admin
- **Preconditions:** None.
- **Behaviour:** The **sole source of truth** for payment status shall be `payments.status`. The `orders` table shall **not** carry a `payment_status` column, and no second copy of the status may exist elsewhere. The status of an order's payment shall be derived by joining to its `payments` row(s). **[Confirmed]** There is no `refunded` status; refunds are out of scope.
- **Priority:** P0
- **Acceptance criteria:**
  1. The `orders` table has no `payment_status` column.
  2. The Admin order list reads the payment status from `payments.status`.
  3. No `refunded` value exists in the schema or the UI.

---

### 7.5 User Dashboard and Profile — P0

---

**FR-5.1 — Dashboard greeting and recent orders**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** The User Dashboard (P-11) shall greet the User **by name** and shall display the User's **recent orders with status** (fulfilment status and payment status). Cancelled orders are included in this list (FR-5.7).
- **Priority:** P0
- **Acceptance criteria:**
  1. The greeting contains the User's full name.
  2. The list shows each order's number, date, total and status.
  3. Orders are ordered most recent first.

---

**FR-5.2 — Customer order detail**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role and the order belongs to that account.
- **Behaviour:** The User shall be able to open a full **order detail** view (P-12) showing the **items (product name, quantity, unit price), line totals, order total, order date, fulfilment status, payment method, payment status and delivery address**. **A User may only view their own orders**; a request for another account's order shall be refused with HTTP 403 (RBAC-7). An order belonging to a different customer shall not leak through a guessed identifier.
- **Priority:** P0
- **Acceptance criteria:**
  1. The detail page shows every listed field.
  2. Requesting another customer's order returns HTTP 403 and reveals no data.
  3. The delivery address shown is the snapshot stored on the order.

---

**FR-5.3 — Dashboard quick links**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** The Dashboard shall provide quick links to **browse products** and to **open the cart**.
- **Priority:** P0
- **Acceptance criteria:**
  1. Both links are present and navigate to the correct pages.
  2. The cart link shows the current cart count.

---

**FR-5.4 — Empty order history**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role and has no orders.
- **Behaviour:** The Dashboard shall show a clear empty-state message (for example, "You have no orders yet") with a link to the product listing.
- **Priority:** P0
- **Acceptance criteria:**
  1. A new account sees the empty-state message rather than a broken table.
  2. A link to the catalogue is provided.

---

**FR-5.5 — Edit own profile name**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** The User shall be able to change **their own** full name from the Profile page (P-13). A User shall **never** be able to change their own **role**, email address, or account status through this interface. Any role or status field supplied by the client shall be discarded.
- **Priority:** P0
- **Acceptance criteria:**
  1. A valid new name is saved and reflected in the dashboard greeting.
  2. A submitted `role_id` or `is_active` value has no effect.
  3. An empty or over-length name is rejected with an inline message.

---

**FR-5.6 — Change own password**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** The User shall be able to change their own password **after providing their current password**. The new password is subject to FR-1.3. On success the **current session identifier shall be rotated** and other sessions for that account, if any, shall be invalidated.
- **Priority:** P0
- **Acceptance criteria:**
  1. A wrong current password is rejected with a message, and the password is unchanged.
  2. A valid change takes effect, and the old password no longer works.
  3. The session identifier changes after the change.

---

**FR-5.7 — Cancelled orders remain visible**
- **Role:** User
- **Preconditions:** An order has been cancelled by an Admin (FR-6.8).
- **Behaviour:** A cancelled order shall **remain visible** in the user's order history and in the recent-orders list on the Dashboard, with a clear **cancelled** status and the explanation **"This order was cancelled by the store. No payment is required."** All order items and the purchase-time product information (name, unit price) shall be **preserved and displayed**. Cancelled orders are **not** removed from the customer's history.
- **Priority:** P0
- **Acceptance criteria:**
  1. A cancelled order appears in the recent-orders list and in the full history.
  2. Its status is shown as cancelled with the explanatory text.
  3. Its items, quantities and unit prices are still displayed.
  4. The order is not deleted from the database.

---

### 7.6 Admin Functions — P0

---

**FR-6.1 — Admin dashboard counters**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role.
- **Behaviour:** The Admin Dashboard (P-14) shall display counts of **products**, **orders** and **users**. The product count shall exclude soft-deleted products. The order count shall reflect all orders, including cancelled ones.
- **Priority:** P0
- **Acceptance criteria:**
  1. All three counts are displayed.
  2. The product count excludes soft-deleted products.
  3. A newly created order changes the order count.

---

**FR-6.2 — Create a product**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role; a valid category exists.
- **Behaviour:** The Admin shall be able to create a product with **name, description, price, stock, category and an image upload**. The **image is mandatory**. The price shall be stored as `DECIMAL(10,2)`; floating-point arithmetic shall not be used for money. The product shall be created with `is_deleted = 0` and shall appear immediately in the storefront. See FR-2.12 for image rules and FR-6.4 for atomic persistence.
- **Priority:** P0
- **Acceptance criteria:**
  1. A product created with all fields, including an image, appears on the storefront listing.
  2. Creation without an image is refused, and no product row is written.
  3. The stored price is an exact `DECIMAL(10,2)` value with no floating-point drift.
  4. The new product is included in its category filter.

---

**FR-6.3 — Update a product**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role; the product exists.
- **Behaviour:** The Admin shall be able to update **any** product field — name, description, price, stock and category — and may replace the image. **A new image upload is not required for every update**: the existing image may be retained. Changes shall appear on the storefront immediately. Previous image files shall be removed **only after** the new files have been stored successfully (FR-6.4).
- **Priority:** P0
- **Acceptance criteria:**
  1. A price change is reflected on the storefront listing and detail page without delay.
  2. A stock change to zero marks the product out of stock and disables Add to Cart.
  3. Updating a description without re-uploading an image succeeds and keeps the existing image.
  4. Replacing an image results in both the original and the thumbnail being replaced, with the old files removed.

---

**FR-6.4 — Image upload validation and atomic persistence**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role; an image file is supplied.
- **Behaviour:** The system shall validate the upload **server-side** on **both** declared file type and actual image content (the file must decode as a valid image), enforce the **JPG / PNG / WebP** allow-list and the **2 MB** limit, and shall generate a **thumbnail** from the original.
- **Filesystem and database atomicity:** a database transaction **cannot roll back filesystem changes**. The required behaviour is therefore **compensating cleanup**: if thumbnail generation or the database write fails, the system shall **roll back the database operation AND explicitly delete any files already written**, so the product is **never left incomplete**. The previous version's image files shall be deleted **only after** the new files are confirmed stored. A failed upload shall leave the product exactly as it was before the attempt.
- **Priority:** P0
- **Acceptance criteria:**
  1. A file whose extension claims an image but whose content is not a decodable image is rejected.
  2. A file above 2 MB is rejected with a message stating the limit.
  3. A failure during thumbnail generation leaves **no** new files on disk and **no** product row, and the product's previous image still works.
  4. A successful replacement removes the superseded files only after the new ones exist.
  5. No partially-written product is visible on the storefront at any point.

---

**FR-6.5 — Remove a product (always soft delete)**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role; the product exists.
- **Behaviour:** Product removal shall **always** be a soft delete (`products.is_deleted = 1`). Products shall **never** be hard-deleted, so that order history and existing cart references remain intact. A soft-deleted product shall be excluded from listings and search (FR-2.8), shall not be purchasable, and if it is already in a customer's cart it shall stay visible with **"No longer available"** with **checkout blocked** until the customer removes it — it is **never removed automatically** (FR-3.8).
- **Priority:** P0
- **Acceptance criteria:**
  1. After removal the row still exists with `is_deleted = 1`.
  2. The product is absent from listings and search results.
  3. Its detail URL returns HTTP 404 with "Product no longer available".
  4. Past orders still display it.
  5. A cart containing it shows "No longer available" and blocks checkout.

---

**FR-6.6 — Manage categories**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role.
- **Behaviour:** The Admin shall be able to **add**, **rename** and **remove** categories. A category that still has products assigned shall **not** be removable; the Admin shall be told which products must be reassigned, and reassignment is **manual** — the system shall **not** silently move products to a fallback category, and there shall be **no reserved "Uncategorised" category**. Category names shall be unique. Deleting a category that has **no** products shall succeed.
- **Priority:** P0
- **Acceptance criteria:**
  1. A new category appears immediately in the storefront filter and the landing category strip.
  2. Renaming a category updates the filter and the product detail page display.
  3. Removing a category with products is refused with an explanatory message naming the blocking products.
  4. After manual reassignment, the category becomes removable.
  5. Removing an empty category succeeds, and it disappears from the filter.
  6. A duplicate category name is rejected.

---

**FR-6.7 — View all orders**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role.
- **Behaviour:** The Admin Orders List (P-17) shall show **all orders** with, for each order: the **order number**, the customer, the date, the **fulfilment status**, the **total** and the **payment status**. The payment status shall be read from `payments.status` (FR-4.16) and shall be shown **distinctly**, so that a COD payment still `pending` is distinguishable from a card payment already `success`. Cancelled orders remain listed.
- **Priority:** P0
- **Acceptance criteria:**
  1. Every order in the system appears in the list.
  2. A COD order with `pending` payment and a card order with `success` payment are visually distinguishable.
  3. Cancelled orders are listed with a cancelled status.
  4. The list shows the customer and the order number.

---

**FR-6.8 — Order detail and fulfilment status transitions**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role; the order exists.
- **Behaviour:** The Admin Order Detail page (P-18) shall show the order's **items, quantities, unit prices, line totals, total, customer, delivery address snapshot, fulfilment status and payment status**, and shall allow the Admin to **update the fulfilment status**. The permitted fulfilment statuses are `placed`, `processing`, `shipped`, `delivered`, `cancelled`; the system sets `placed` automatically at creation. **Only an Admin may change fulfilment status — a customer cannot change it and cannot cancel their own order.** `cancelled` is **terminal**: it cannot be undone. **Backward transitions are permitted** for corrections (for example `shipped → processing`). **All transitions shall be validated** — an unknown or illegal transition is rejected. **Cancelling an order shall restore stock only where the product still exists and is not soft-deleted**; where the product is missing or soft-deleted the case shall be **logged for manual handling**. Cancelling an order shall **not** restore the items to the customer's cart. A cancelled **COD** order's payment moves from `pending` to `failed` (BR-15).
- **Priority:** P0
- **Acceptance criteria:**
  1. The Admin sees every field listed above, including the delivery address.
  2. `placed → processing → shipped → delivered` each succeed and are reflected on the customer's order detail.
  3. `delivered → cancelled` is refused, and no Admin control offers it.
  4. `shipped → processing` is permitted.
  5. Cancelling an order restores stock for still-available products.
  6. Cancelling an order whose product is soft-deleted leaves stock unchanged and writes a log entry.
  7. Cancelling does not add items back to the customer's cart.
  8. A customer has no control to change the status or to cancel.
  9. A cancelled COD order's payment status becomes `failed`, retaining its `order_id`.

---

**FR-6.9 — Confirm COD cash collection**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role; the order has a `cod` payment with `status = 'pending'`.
- **Behaviour:** The Admin shall be able to **confirm cash collection**, which moves the COD payment from `pending` to `success` and sets `paid_at`. The Admin shall **not** be able to convert a **failed card payment into a success** — a failed card payment is **immutable** (BR-9).
- **Priority:** P0
- **Acceptance criteria:**
  1. Confirming a pending COD payment sets `payments.status = 'success'` and `paid_at` to the current timestamp.
  2. The order's payment status then reads `success` on both the Admin list and the customer's order detail.
  3. No Admin control exists to mark a failed card payment as successful.
  4. A direct attempt to set a failed card payment to `success` is rejected by the service layer (the database shape permits it; the application does not).

---

**FR-6.10 — User list and admin-initiated password reset**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role.
- **Behaviour:** The Admin User List (P-19) shall show, for each registered account, its **name, email and registration date**. The Admin User Detail page (P-20) shall allow the Admin to **initiate a password reset**: the Admin sets a **temporary password**, the account's forced-change flag is set (FR-1.9), and the User must change it at next login. **An Admin can never view, read or retrieve an existing password.** The email self-service reset of v1.0 is retired (FR-1.8). The Admin shall not be able to set a role on an account through this interface; roles are managed by the seed script (FR-6.12).
- **Priority:** P0
- **Acceptance criteria:**
  1. The user list shows name, email and registration date for every account.
  2. Setting a temporary password succeeds, and the affected User is forced to change it at next login (FR-1.9).
  3. The Admin interface provides **no** means to view an existing password or hash.
  4. The temporary password is stored only as a hash.

---

**FR-6.11 — Deactivate / reactivate an account (P1 — deferred, behaviour specified now)**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role; the target account holds the `User` role.
- **Behaviour:** **[Confirmed]** The full behaviour of this requirement is specified now, so that it can be implemented in the P1 release without further analysis; the implementation itself is **deferred and is not required for v1.0 launch**.
  - An Admin may **deactivate** a User by setting `users.is_active = 0`, and may **reactivate** it by restoring `is_active = 1`.
  - A deactivated User **cannot log in**.
  - **Existing authenticated sessions become invalid on the next authenticated request** — the account status is checked on every request (FR-7.3), not only at login.
  - A deactivated User **cannot access** the dashboard, order history, cart or checkout.
  - **Existing orders and order history are retained** in the database and are not hidden or deleted.
  - Reactivating the account restores normal access immediately.
- **Priority:** **P1**
- **Acceptance criteria (for the P1 release):**
  1. A deactivated account is refused at login with an appropriate message.
  2. A session established before deactivation fails on its next authenticated request.
  3. The deactivated account's order history is still present in the database.
  4. Reactivation restores login and access.

---

**FR-6.12 — Seed and bootstrap script**
- **Role:** Developer / operator (not an end-user role)
- **Preconditions:** The database exists and is reachable; the environment is a **development** environment.
- **Behaviour:** A seed script shall create, **idempotently**: the two roles (`User`, `Admin`), one **Admin** account, one separate **test User** account (used to exercise the shopping and checkout workflow, per PRD §5.2), **five** sample categories, and approximately **25–30** sample products with **local placeholder plant images**. The script shall be **development-only** and **must refuse to run against a production database**. Development credentials shall be documented in the README, and the README shall state that the default Admin password must be changed before real use.
- **Priority:** P0 (a runnable demo requires it)
- **Acceptance criteria:**
  1. Running the script on an empty database creates the roles, the Admin, the test User, five categories and 25–30 products.
  2. Running it a second time creates **no** duplicates.
  3. The script refuses to run when the target environment is marked production.
  4. Every seeded product has a valid image file present.
  5. The Admin can log in, and the test User can complete the shopping and checkout flow.

---

### 7.7 Role-Based Access Control — P0

---

**FR-7.1 — Centralised authorisation**
- **Role:** All
- **Preconditions:** None.
- **Behaviour:** All protected server-side operations shall be authorised through **one central helper or middleware** that maps the account's role to the permitted action set (the capability matrix, §5.1). The mapping shall be defined in **exactly one place** in the code base. Authorisation shall be enforced **server-side on every protected request**; hiding or disabling interface controls is **never sufficient**. New protected endpoints shall not be added without a corresponding entry in the central mapping.
- **Priority:** P0
- **Acceptance criteria:**
  1. A single, identifiable authorisation module exists and is used by all protected routes.
  2. A User cannot reach any Admin-only action by any route, method or parameter.
  3. No authorisation decision is duplicated in a controller or view.

---

**FR-7.2 — Unauthenticated access**
- **Role:** Visitor
- **Preconditions:** A protected page or endpoint is requested without a valid session.
- **Behaviour:** The system shall redirect the request to the **login page** with **HTTP status 401**. The originally requested destination may be retained so the User is returned to it after login.
- **Priority:** P0
- **Acceptance criteria:**
  1. A protected page requested anonymously returns 401 and the login page.
  2. A protected endpoint requested anonymously returns 401 with a JSON error body, not HTML.
  3. After logging in, the User is returned to the originally requested page.

---

**FR-7.3 — Account status checked per request**
- **Role:** User, Admin
- **Preconditions:** A session exists.
- **Behaviour:** On **every** authenticated request the system shall re-read the account's `is_active` flag and role, and shall invalidate the session if the account has been deactivated or its role has changed. The role used for the authorisation decision shall always be the **current** database value, not a value cached in the session at login.
- **Priority:** P0
- **Acceptance criteria:**
  1. A deactivated account's next request is refused, and the session is invalidated.
  2. A role change in the database takes effect on the next request without re-login.
  3. The decision does not rely on a role value stored at login time.

---

**FR-7.4 — User accessing an Admin resource**
- **Role:** User
- **Preconditions:** The account is authenticated with the `User` role.
- **Behaviour:** A User request to any Admin page or Admin endpoint shall receive **HTTP 403** and render the **403 Access Denied** page (P-21).
- **Priority:** P0
- **Acceptance criteria:**
  1. Direct navigation to an Admin page URL returns 403.
  2. A direct POST to an Admin endpoint returns 403 and performs no state change.
  3. The response renders P-21 with no sensitive detail.

---

**FR-7.5 — Admin accessing a shopping resource**
- **Role:** Admin
- **Preconditions:** The account is authenticated with the `Admin` role.
- **Behaviour:** An Admin request to any shopping feature — **cart, payment, checkout, order confirmation, user dashboard, customer order detail** — shall receive **HTTP 403** and render P-21. **[Confirmed] Admin is strictly a management role.** An Admin may browse the catalogue and the storefront, and may view the Admin order list and order detail.
- **Priority:** P0
- **Acceptance criteria:**
  1. An Admin cannot add to cart and cannot reach P-08, P-09, P-10, P-11 or P-12; each returns 403.
  2. The cart count is not rendered in the navigation for an Admin.
  3. An Admin can still open the product listing and detail pages.

---

**FR-7.6 — Server-side, database-backed sessions**
- **Role:** User, Admin
- **Preconditions:** None.
- **Behaviour:** Sessions shall be **server-side and database-backed**. The browser shall hold **only a session identifier** in an httpOnly cookie. The implementation shall use a **custom session save handler** registered with `session_set_save_handler($handler, true)` (or an equivalent server-side persistence mechanism), so that `session_start()` alone is **not** sufficient for the session to persist across requests. The session cookie shall set `httponly = 1`, `samesite = Lax`, `use_strict_mode = 1`, `sid_length = 32`, and `gc_maxlifetime = 28800` (8 hours of inactivity); `secure = 1` under HTTPS and disabled for local HTTP development. The **absolute 7-day** maximum (FR-1.10) is enforced in the handler's own logic, because it cannot be expressed by a cookie attribute. **No JWT, no browser-storage token, and no "Remember Me"** feature shall exist.
- **Priority:** P0
- **Acceptance criteria:**
  1. Session state survives a PHP process restart, proving the session is stored in the database rather than in process memory.
  2. The cookie is httpOnly and `SameSite=Lax`; the `Secure` attribute is present when served over HTTPS and absent for local HTTP development.
  3. The browser's `document.cookie` does not expose the session identifier.
  4. A session idle beyond 8 hours is rejected; one older than 7 days is rejected even if active.
  5. No JWT or token in `localStorage` or `sessionStorage` exists anywhere in the application.

---

**FR-7.7 — CSRF protection**
- **Role:** User, Admin
- **Preconditions:** None.
- **Behaviour:** Every **state-changing** request (POST, and any other mutating verb) shall carry a **CSRF token** that is validated server-side. The token shall be tied to the session. A missing, malformed or mismatched token shall cause the request to be **rejected with no state change**. This applies to the background cart endpoints of FR-3.1, FR-3.5 and FR-3.6 as well as to full-page form submissions.
- **Priority:** P0
- **Acceptance criteria:**
  1. A state-changing request without a token is rejected, and nothing changes.
  2. A token from another session is rejected.
  3. Valid tokens are accepted.
  4. The background cart endpoints enforce the same rule as the page forms.

---

### 7.8 Search and Pagination — Summary Index

The search and pagination requirements are stated in full under §7.2 and are not duplicated here.

| Topic | Requirements |
|---|---|
| Category filter | FR-2.4 |
| Name search, case-insensitive substring | FR-2.5 |
| Multi-word search (all words) | FR-2.6 |
| Empty result state | FR-2.9 |
| Pagination, 12/page, URL page number | FR-2.10 |
| Ordering, newest first | FR-2.11 |

---

## 8. Non-Functional Requirements

### 8.1 Performance

| ID | Requirement |
|---|---|
| **NFR-P-01** | Pages shall load within **3 seconds** on a typical broadband connection. |
| **NFR-P-02** | The system shall support at least **200 concurrent users** in v1.0. |
| **NFR-P-03** | Product images shall be compressed and **lazy-loaded**; listing pages shall use thumbnails. |
| **NFR-P-04** | Product listing queries shall load **only the current page** of results — the full catalogue shall never be fetched to paginate client-side. |
| **NFR-P-05** | The cart count shall be updated by a **background request** without a page reload, and the Add-to-Cart action shall not require a full page round trip. |

### 8.2 Security

| ID | Requirement |
|---|---|
| **NFR-S-01** | **HTTPS** shall be used for all traffic in production. |
| **NFR-S-02** | Passwords shall be hashed with a **salted** algorithm (`password_hash`, bcrypt or Argon2id). Plaintext passwords shall never be stored, logged or displayed. |
| **NFR-S-03** | All database access shall use **parameterised queries**; all user-supplied output shall be **output-encoded** to prevent XSS. |
| **NFR-S-04** | **CSRF tokens** shall be validated on every state-changing request (FR-7.7). |
| **NFR-S-05** | Image uploads shall be limited to **JPG, PNG, WebP** with a **2 MB** maximum and shall be validated server-side on **both** declared type and actual image content. Uploaded files shall be stored outside the web root or served through a handler that prevents execution. |
| **NFR-S-06** | **Login attempts shall be rate-limited** per source address and, where practical, per account. |
| **NFR-S-07** | The database connection shall operate under **strict SQL mode** per §6.4 R-SQL-1 … R-SQL-6, so invalid data is rejected rather than silently coerced. |
| **NFR-S-08** | Authorisation shall be enforced **server-side** on every protected request through the single central mechanism of FR-7.1. Interface-level hiding shall never be the only control. |
| **NFR-S-09** | **Card data shall never be stored** — in any table, log, session record or error message (FR-4.9). |
| **NFR-S-10** | Sessions shall be **server-side and database-backed**, with httpOnly, SameSite and environment-appropriate Secure cookie attributes (FR-7.6). |

### 8.3 Usability

| ID | Requirement |
|---|---|
| **NFR-U-01** | The layout shall be responsive from **360 px** viewport width upward to desktop. Product grids are single-column on phones and multi-column on larger screens. |
| **NFR-U-02** | The core flow from browsing to checkout shall be achievable in **five steps or fewer**. |
| **NFR-U-03** | Clear error and success messages shall be presented for all state-changing actions. |
| **NFR-U-04** | Basic accessibility shall be provided: **alt text** on all images, keyboard-operable forms, and sufficient colour contrast. |
| **NFR-U-05** | The system shall present explicit, distinct states for: no search results, empty cart, empty order history, out-of-stock product, price-changed item, unavailable (soft-deleted or zero-stock) cart item, declined payment, validation errors, and cancelled order. |

### 8.4 Reliability and Availability

| ID | Requirement |
|---|---|
| **NFR-R-01** | Target uptime shall be **99%** for v1.0. |
| **NFR-R-02** | Checkout shall be **transactional**: order creation, order-item creation, stock reduction, payment insertion and cart clearing succeed together or not at all (FR-4.15). |
| **NFR-R-03** | **Daily database backups** shall be taken. |
| **NFR-R-04** | The product image pipeline shall be **atomic from the user's perspective**: a failed upload shall leave no partial product and no orphaned files (FR-6.4). |
| **NFR-R-05** | An unexpected server error shall produce a **generic** error page (P-22) with no stack trace, SQL fragment, file path or other internal detail in the response. |

### 8.5 Maintainability

| ID | Requirement |
|---|---|
| **NFR-M-01** | The code shall be **modular**, with separate layers for routing/controllers, services and data access. |
| **NFR-M-02** | **API documentation** and **automated tests** shall be provided for critical flows. |
| **NFR-M-03** | The authorisation capability matrix shall be defined in **exactly one place** in the code base. |
| **NFR-M-04** | Database access shall be performed through **MySQLi prepared statements**; the `IGNORE` variants `INSERT IGNORE` and `UPDATE IGNORE` are **prohibited** in application code (R-SQL-7). |
| **NFR-M-05** | Every database operation's **result** shall be checked and the write **verified**, rather than inferred from the absence of an exception. |

### 8.6 Portability

| ID | Requirement |
|---|---|
| **NFR-PT-01** | The system shall run on any major browser and be deployable with **Docker**. |
| **NFR-PT-02** | The application shall require no email, payment-gateway, courier or third-party API service in v1.0. |

---

## 9. External Interface Requirements

### 9.1 User Interface
The complete 22-page inventory is in §4.3. The interface shall use a **green, nature-inspired theme** with generous white space and rounded cards; **large, image-led product presentation** with the image leading each card and the name beneath it; and a consistent **navigation bar** showing the logo, categories, search, a cart icon with item count, and a login/account menu.

### 9.2 Hardware Interfaces
None beyond standard client devices (desktop, tablet, smartphone) and a standard server host.

### 9.3 Software Interfaces

| Interface | Protocol | Purpose | Notes |
|---|---|---|---|
| **Browser ↔ Application** | HTTPS, HTML/CSS, JavaScript | All pages | Server-rendered; progressive enhancement via JavaScript. |
| **Browser ↔ Cart endpoints** | HTTPS, JSON over HTTP | Add to cart, change quantity, remove item, read cart count | Background (AJAX) operations; CSRF-protected; session-authenticated (FR-3.1, FR-3.5, FR-3.6, FR-7.7). |
| **Application ↔ MySQL** | MySQLi, TCP/TLS | All persistence | Prepared statements; strict SQL mode (R-SQL-1 … R-SQL-6). |
| **Application ↔ Filesystem** | Local filesystem | Product images | Originals and generated thumbnails; outside the web root or served through a non-executing handler. |
| **Simulated card processor** | In-process, no network | Card outcome simulation | **[Confirmed]** Not a real gateway; produces success or decline outcomes (FR-4.6). No card data is persisted (FR-4.9). |

**[Confirmed]** There is **no** real payment gateway, **no** email/SMTP service, **no** courier API, **no** Redis or message broker, and **no** third-party API dependency in v1.0.

### 9.4 Communication Formats
JSON for all AJAX endpoints, with consistent error objects. A JSON endpoint failure shall return an appropriate HTTP status — **401** unauthenticated, **403** forbidden, **422** validation failure, **409** conflict, **500** unexpected — and a machine-readable error code alongside a human-readable message.

---

## 10. Database Requirements

**[Confirmed]** The database is **role-centred**: every account references a role, and permissions flow from it.

### 10.1 Engine and Configuration

| Property | Value |
|---|---|
| Engine | **MySQL 8.0.16+** (mandatory floor — `CHECK` constraints are enforced only from 8.0.16) |
| Access | **MySQLi** with prepared statements |
| Character set | `utf8mb4` |
| Collation | `utf8mb4_unicode_ci` (case-insensitive) — required by FR-2.5 |
| SQL mode | `STRICT_TRANS_TABLES` at server level, set and verified per session (§6.4) |
| Engine/InnoDB | Transactional, with row-level locking for the checkout stock check (FR-4.11) |

### 10.2 Entity Relationship Summary

```
roles ──1:N──> users ──1:N──> cart_items ──N:1──> products
                 │                    │
                 │                    └──N:1──> categories
                 │
                 └──1:N──> orders ──1:N──> order_items ──N:1──> products
                                 │
                                 └──1:N──> payments  (order_id NULLABLE)
```

| Relationship | Cardinality | Notes |
|---|---|---|
| role → users | 1:N | `users.role_id` FK, `NOT NULL` |
| user → cart_items | 1:N | `UNIQUE (user_id, product_id)` |
| user → orders | 1:N | |
| category → products | 1:N | `products.category_id` `NOT NULL` |
| product → cart_items | 1:N | |
| order → order_items | 1:N | |
| product → order_items | 1:N | `product_id` `NOT NULL` — the product row is never deleted (FR-6.5) |
| order → payments | 1:N | **`order_id` is NULLABLE** — a declined card attempt has no order |

### 10.3 Core Tables

**roles**
| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `name` | VARCHAR(50) | NOT NULL, UNIQUE |

Seeded with `User` and `Admin`. Additional roles may be inserted later as **data only**, with no schema migration (PRD §5.3). There is **no permissions table** in v1.0.

**users**
| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `full_name` | VARCHAR(120) | NOT NULL |
| `email` | VARCHAR(190) | NOT NULL, UNIQUE |
| `password_hash` | VARCHAR(255) | NOT NULL |
| `role_id` | INT UNSIGNED | NOT NULL, FK → `roles.id` ON DELETE RESTRICT ON UPDATE RESTRICT |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 |
| `must_change_password` | TINYINT(1) | NOT NULL, DEFAULT 0 |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |

**[Confirmed]** `is_active` and `must_change_password` are both required: `is_active` backs FR-7.3 and FR-6.11; `must_change_password` backs FR-1.9 and FR-6.10.

**categories**
| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `name` | VARCHAR(100) | NOT NULL, UNIQUE |
| `description` | VARCHAR(255) | NULL |

**products**
| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `name` | VARCHAR(150) | NOT NULL, indexed for the `LIKE` search of FR-2.5 |
| `description` | TEXT | NULL |
| `price` | **DECIMAL(10,2)** | NOT NULL — floats are prohibited (BR-1) |
| `stock` | INT | NOT NULL, DEFAULT 0 |
| `image_path` | VARCHAR(255) | NOT NULL — original |
| `thumbnail_path` | VARCHAR(255) | NOT NULL — generated thumbnail |
| `category_id` | INT UNSIGNED | NOT NULL, FK → `categories.id` ON DELETE RESTRICT ON UPDATE RESTRICT |
| `is_deleted` | TINYINT(1) | NOT NULL, DEFAULT 0 |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

**[Confirmed]** `products.is_deleted` is required (FR-2.8, FR-6.5). `category_id` is `NOT NULL` (FR-2.3).

**cart_items**
| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `user_id` | INT UNSIGNED | NOT NULL, FK → `users.id` ON DELETE CASCADE ON UPDATE RESTRICT |
| `product_id` | INT UNSIGNED | NOT NULL, FK → `products.id` ON DELETE RESTRICT ON UPDATE RESTRICT |
| `quantity` | INT | NOT NULL, DEFAULT 1, CHECK (`quantity >= 1`) |
| `price_at_add` | DECIMAL(10,2) | NOT NULL — **change detection only** (FR-3.7), never used to charge |
| `added_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |

`UNIQUE (user_id, product_id)` — FR-3.3.

**orders**
| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `order_number` | VARCHAR(32) | NOT NULL, UNIQUE — FR-4.14 |
| `user_id` | INT UNSIGNED | NOT NULL, FK → `users.id` ON DELETE RESTRICT ON UPDATE RESTRICT |
| `total_amount` | DECIMAL(10,2) | NOT NULL |
| `status` | ENUM('placed','processing','shipped','delivered','cancelled') | NOT NULL, DEFAULT 'placed' |
| `delivery_name` | VARCHAR(120) | NOT NULL |
| `delivery_phone` | VARCHAR(40) | NOT NULL |
| `delivery_address` | VARCHAR(255) | NOT NULL |
| `delivery_city` | VARCHAR(100) | NOT NULL |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |

**[Confirmed]** `orders` has **no `payment_status` column** (FR-4.16). The **delivery address snapshot** columns are part of the order row (FR-4.8).

**order_items**
| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `order_id` | INT UNSIGNED | NOT NULL, FK → `orders.id` ON DELETE CASCADE ON UPDATE RESTRICT |
| `product_id` | INT UNSIGNED | NOT NULL, FK → `products.id` ON DELETE RESTRICT ON UPDATE RESTRICT |
| `product_name_at_purchase` | VARCHAR(150) | NOT NULL — immutable snapshot (BR-5) |
| `unit_price_at_purchase` | DECIMAL(10,2) | NOT NULL — immutable snapshot (BR-6) |
| `quantity` | INT | NOT NULL, CHECK (`quantity >= 1`) |

**payments**
| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT |
| `order_id` | INT UNSIGNED | **NULL** — a declined card attempt references no order |
| `method` | ENUM('card','cod') | NOT NULL |
| `status` | ENUM('pending','success','failed') | NOT NULL |
| `paid_at` | DATETIME | NULL |
| `success_order_id` | INT UNSIGNED | **VIRTUAL GENERATED** — `CASE WHEN status = 'success' THEN order_id ELSE NULL END` |
| — | — | `UNIQUE KEY uq_payments_success_order (success_order_id)` |
| — | — | `CONSTRAINT chk_payment_order_binding CHECK (…)` — see §10.4 |
| — | — | `CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT ON UPDATE RESTRICT` |

### 10.4 `payments` — Final DDL and the Permitted Combinations

```sql
CREATE TABLE payments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NULL,          -- NULL for failed card attempts
  method        ENUM('card','cod')          NOT NULL,
  status        ENUM('pending','success','failed') NOT NULL,
  paid_at       DATETIME NULL,
  success_order_id INT UNSIGNED
    GENERATED ALWAYS AS (
      CASE WHEN status = 'success' THEN order_id ELSE NULL END
    ) VIRTUAL,
  UNIQUE KEY uq_payments_success_order (success_order_id),
  CONSTRAINT chk_payment_order_binding
    CHECK (
         (method = 'card'  AND status = 'success'  AND order_id IS NOT NULL)
      OR (method = 'card'  AND status = 'failed'   AND order_id IS NULL)
      OR (method = 'cod'   AND status = 'pending'  AND order_id IS NOT NULL)
      OR (method = 'cod'   AND status = 'success'  AND order_id IS NOT NULL)
      OR (method = 'cod'   AND status = 'failed'   AND order_id IS NOT NULL)
    ),
  CONSTRAINT fk_payments_order
    FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
);
```

**The five permitted payment shapes (BR-8):**

| Method | Status | `order_id` | `success_order_id` | Scenario |
|---|---|---|---|---|
| `card` | `success` | **NOT NULL** (the order) | = `order_id` | Card payment approved (FR-4.4) |
| `card` | `failed` | **NULL** | NULL | Declined card attempt (FR-4.5) |
| `cod` | `pending` | **NOT NULL** | NULL | COD order placed, cash not yet collected (FR-4.7) |
| `cod` | `success` | **NOT NULL** | = `order_id` | Cash collected and confirmed by Admin (FR-6.9) |
| `cod` | `failed` | **NOT NULL** | NULL | COD order cancelled before cash collection (FR-6.8) |

**[Confirmed] The CHECK constraint does not enforce every business rule.** It enforces the five permitted `(method, status, order_id)` shapes at the database level. The following rules are **application-enforced** and must be implemented in the service layer:

| Rule | Enforcement |
|---|---|
| A **failed card payment is immutable** (BR-9) | Application-enforced. The database permits `card/failed → card/success`, so the service layer must reject that transition. |
| A failed **COD** payment arises only from an **order cancellation** | Application-enforced: the `cod/failed` transition is permitted only as part of the cancel workflow (FR-6.8). |
| `paid_at` is set only when `status = 'success'` | Application-enforced; the database does not couple the two. |
| A **successful** payment must reference an order | **Database-enforced** by `chk_payment_order_binding` — `success` with `order_id IS NULL` is rejected. |

**[Confirmed] A `cod` payment with `status = 'failed'` retains its `order_id`.** It is **not** true that every failed payment has a NULL `order_id`; only a failed **card** payment does.

**[Confirmed] At most one successful payment per order.** The `success_order_id` virtual generated column, combined with the `UNIQUE` index on it, permits **multiple** `pending` and `failed` rows for the same order, because MySQL allows multiple `NULL` values in a `UNIQUE` index — but a **second** row with `status = 'success'` for the same `order_id` would produce a duplicate non-NULL `success_order_id` and be rejected with error **1062**.

**[Confirmed] `success_order_id` must match `orders.id` in type and signedness** — both `INT UNSIGNED` — so the generated column and the foreign key are type-compatible.

**[Confirmed] The `valid_payment_states` lookup table was removed.** The single table-level `CHECK` constraint replaces it: one fewer table, one fewer column, one fewer foreign key, and zero seed `INSERT` statements, while being strictly stronger.

### 10.5 Schema Delta versus SRS v1.0

| # | Change | Reason | FR |
|---|---|---|---|
| 1 | `orders.payment_status` **removed** | Three-way duplication of payment status; `payments.status` is the sole source of truth | FR-4.16, BR-3 |
| 2 | `orders.status` changed to an **ENUM** with five values | Undefined status values were possible in v1.0 | FR-6.8, BR-7 |
| 3 | `orders.order_number` **added**, UNIQUE | FR-4.14 |
| 4 | `orders` delivery-address columns **added** | FR-4.8 |
| 5 | `payments.order_id` made **NULLABLE** | A declined card attempt has no order (FR-4.5) |
| 6 | `payments.success_order_id` **added** (VIRTUAL generated) + UNIQUE index | "At most one successful payment per order", MySQL-compatible (FR-4.16) |
| 7 | `payments.chk_payment_order_binding` **CHECK** added | Enforce the five permitted payment shapes |
| 8 | `payments.status` ENUM restricted to `pending\|success\|failed` | No `refunded` in v1.0 (BR-3) |
| 9 | `products.is_deleted` **confirmed present** | Soft delete (FR-2.8, FR-6.5) |
| 10 | `users.is_active` **confirmed present** | FR-7.3, FR-6.11 |
| 11 | `users.must_change_password` **added** | FR-1.9, FR-6.10 |
| 12 | `products.image_path` / `products.thumbnail_path` **added** | Original + thumbnail (FR-2.12) |
| 13 | `cart_items.price_at_add` **added** | Price-change detection (FR-3.7) |
| 14 | `order_items.product_name_at_purchase` **added** | Immutable name snapshot (BR-5) |
| 15 | `cart_items` **UNIQUE (user_id, product_id)** added | Duplicate prevention at the database level (FR-3.3) |
| 16 | `categories.description` retained; `products.description` remains `TEXT` | Aligned with the v1.0 baseline and the order-history requirements | FR-2.7, FR-6.7 |

### 10.6 Data Integrity Rules Enforced by the Database
- `CHECK` constraints (MySQL 8.0.16+) on payment shape, cart and order-item quantities.
- Foreign keys with `RESTRICT` on delete for referenced catalogue and order data, so a referenced row cannot be hard-deleted.
- `UNIQUE` on `users.email`, `roles.name`, `categories.name`, `orders.order_number`, `cart_items (user_id, product_id)`, and `payments.success_order_id`.
- `NOT NULL` on `products.category_id` and `products.price`.
- `DECIMAL(10,2)` on every monetary column; no floating-point columns anywhere.

---

## 11. Entity Relationships — Detailed

| # | Entity A | Relationship | Entity B | Key attributes |
|---|---|---|---|---|
| ER-1 | `roles` | 1:N | `users` | `users.role_id` NOT NULL; exactly one role per user |
| ER-2 | `users` | 1:N | `cart_items` | keyed `user_id`; UNIQUE with `product_id` |
| ER-3 | `users` | 1:N | `orders` | `orders.user_id` NOT NULL |
| ER-4 | `categories` | 1:N | `products` | `products.category_id` NOT NULL; `ON DELETE RESTRICT` |
| ER-5 | `products` | 1:N | `cart_items` | `ON DELETE RESTRICT` — a product row is never deleted |
| ER-6 | `orders` | 1:N | `order_items` | `ON DELETE CASCADE` from the order |
| ER-7 | `products` | 1:N | `order_items` | `product_id` NOT NULL — history survives soft deletion |
| ER-8 | `orders` | 1:N | `payments` | `payments.order_id` **NULLABLE**; `ON DELETE RESTRICT` |
| ER-9 | `payments` | derived | `orders` | via `success_order_id` UNIQUE — at most one success per order |

---

## 12. Business Rules

| ID | Rule | Enforced by |
|---|---|---|
| **BR-1** | A single configurable currency applies to the whole store; initial value **BDT (৳)**. All monetary values are stored as `DECIMAL(10,2)`. **Floating-point types are prohibited.** Displayed amounts always show two decimal places. Multi-currency is out of scope. | Schema + application |
| **BR-2** | The Admin-entered product price **is** the final amount the customer pays. **No tax** is added at checkout, and v1.0 produces **no formal tax invoice**. | Application |
| **BR-3** | Payment status values are exactly `pending`, `success`, `failed`. **There is no `refunded` value** — refunds are out of scope. | Schema ENUM + application |
| **BR-4** | Payment status and fulfilment status are **separate concerns** and are never conflated. | Application |
| **BR-5** | The product **name** is snapshotted on the order line as `product_name_at_purchase`, so history survives any later rename. | Schema + application |
| **BR-6** | The unit price is snapshotted on the order line as `unit_price_at_purchase`; **historical order totals never change**. | Schema + application |
| **BR-7** | Fulfilment status is exactly one of `placed`, `processing`, `shipped`, `delivered`, `cancelled`. The system sets `placed`; **only an Admin** changes it; `cancelled` is **terminal**; backward transitions are permitted; all transitions are validated. | Schema ENUM + application |
| **BR-8** | The five permitted `(method, status, order_id)` payment shapes are those in §10.4 and no others. | Database `CHECK` |
| **BR-9** | A **failed card payment is immutable** — no Admin action may turn it into a success. | Application (the database permits the shape; the service layer rejects the transition) |
| **BR-10** | A **failed payment never reduces stock.** Stock is reduced when an order is successfully created — including COD orders, where payment is still `pending`. | Application, inside the transaction |
| **BR-11** | Stock is re-checked **inside the checkout transaction** with row locking, to prevent overselling. | Application + InnoDB locking |
| **BR-12** | The cart always displays and charges the **current** price. `price_at_add` is retained for change detection only. | Application |
| **BR-13** | Cancelling an order restores stock **only** where the product still exists and is not soft-deleted; otherwise the case is **logged for manual handling**. Cancelling does **not** restore items to the customer's cart. | Application |
| **BR-14** | Account deactivation (P1): a deactivated User **cannot log in**; existing sessions become invalid on the **next** authenticated request; the dashboard and order history are inaccessible; **existing orders and order history are retained**; reactivation restores normal access. | Application (FR-7.3, FR-6.11) |
| **BR-15** | A cancelled order's COD payment moves from `pending` to `failed`, **retaining its `order_id`** (§10.4). | Application |
| **BR-16** | The **configured currency must not be changed once orders exist**, because order totals are stored without a per-row currency code. This is a deliberate, documented limitation. | Documentation / operational |
| **BR-17** | Seed scripts are **development-only** and **must refuse to run against a production database**; they are **idempotent**. | Application |

---

## 13. Use Cases

---

### UC-1 — Register and Log In

| Field | Detail |
|---|---|
| **Primary actor** | Visitor |
| **Goal** | Create an account and reach the User Dashboard |
| **Preconditions** | The email is not already registered; the `User` role exists |
| **Trigger** | The Visitor submits the registration form, then the login form |
| **Steps** | 1. The Visitor opens P-02 and submits name, email and password. 2. The system validates input and creates the account with the `User` role, `is_active = 1`, and a salted hash. 3. The Visitor is prompted to log in. 4. The Visitor submits the login form. 5. The system verifies the credentials, establishes a server-side session, and routes to P-11. |
| **Postconditions** | The account exists and is active; a session exists; the cart for the account is empty |
| **Exceptions** | Duplicate email → inline message, no account created; weak password → inline message; wrong credentials → generic message |
| **Requirements** | FR-1.1, FR-1.2, FR-1.3, FR-1.4, FR-1.5, FR-1.7, FR-7.6 |

---

### UC-2 — Browse, Filter, Search and Paginate

| Field | Detail |
|---|---|
| **Primary actor** | Visitor, User, Admin (viewing) |
| **Goal** | Find a product |
| **Preconditions** | None |
| **Trigger** | The visitor opens P-01 or P-05 |
| **Steps** | 1. The visitor selects a category, or types a search term. 2. The system matches the product **name** case-insensitively; a multi-word term requires **all** words to appear. 3. The results are filtered by category with **AND** logic and sorted newest first. 4. The system renders 12 products per page with Previous / numbered / Next controls and the page number in the URL. 5. The visitor opens a product and lands on P-06. |
| **Postconditions** | No state change |
| **Exceptions** | No matches → "No products found" with a Clear Search action (FR-2.9); invalid page → fallback to a valid page |
| **Requirements** | FR-2.1, FR-2.4, FR-2.5, FR-2.6, FR-2.9, FR-2.10, FR-2.11, FR-2.13 |

---

### UC-3 — Add to Cart (authenticated)

| Field | Detail |
|---|---|
| **Primary actor** | User |
| **Goal** | Add a product to the cart |
| **Preconditions** | Authenticated with the `User` role; the product is active, not soft-deleted and in stock |
| **Trigger** | The User clicks Add to Cart |
| **Steps** | 1. From the **listing**, the client posts to the cart endpoint with a CSRF token; the page is not reloaded. 2. The server increments the existing quantity or inserts a new cart row. 3. The response returns the new count; the navigation count updates in place. 4. A success toast appears with a "View Cart" action. — **Alternative (detail page):** the item is added and the browser navigates to P-08. |
| **Postconditions** | The cart contains the item; the count reflects it |
| **Exceptions** | Out of stock or soft-deleted → error message, cart unchanged; missing or invalid CSRF token → rejected, cart unchanged |
| **Requirements** | FR-3.1, FR-3.3, FR-3.12, FR-7.7 |

---

### UC-4 — Add to Cart as a Visitor (deferred execution)

| Field | Detail |
|---|---|
| **Primary actor** | Visitor → User |
| **Goal** | Complete an add-to-cart action that began before login |
| **Preconditions** | Not authenticated |
| **Trigger** | The Visitor clicks Add to Cart |
| **Steps** | 1. The system stores the product id as a pending action in the **session** and redirects to P-03. 2. The Visitor logs in. 3. The system re-reads the product and checks it is not soft-deleted and is in stock. 4. If valid, the item is added; if not, a message explains why. 5. The pending action is **cleared**, so it cannot execute again. |
| **Postconditions** | The item is in the cart exactly once, or a message explains the refusal; the pending action is cleared |
| **Exceptions** | Product soft-deleted or out of stock during login → message, nothing added |
| **Requirements** | FR-3.4, FR-7.2, FR-3.1 |

---

### UC-5 — Manage the Cart

| Field | Detail |
|---|---|
| **Primary actor** | User |
| **Goal** | Adjust the cart contents and totals |
| **Preconditions** | Authenticated with the `User` role |
| **Trigger** | The User opens P-08 |
| **Steps** | 1. The system loads each item with thumbnail, name, **current** unit price, quantity and line total, plus the grand total. 2. Where the current price differs from `price_at_add`, a notification shows the old and new price. 3. The User changes a quantity or removes an item; the change is applied in the background and the totals recalculate. 4. Where stock is below the cart quantity, the quantity is reduced automatically and the User is notified. |
| **Postconditions** | The cart reflects the requested changes; the totals are correct |
| **Exceptions** | Quantity below 1 → rejected; item unavailable (zero stock or soft-deleted) → marked "No longer available" and checkout blocked; the item is **not** removed automatically |
| **Requirements** | FR-3.2, FR-3.5, FR-3.6, FR-3.7, FR-3.8, FR-3.9, FR-3.10, FR-3.11 |

---

### UC-6 — Checkout with a Simulated Card Payment (success)

| Field | Detail |
|---|---|
| **Primary actor** | User |
| **Goal** | Place an order and pay by card |
| **Preconditions** | Authenticated with the `User` role; the cart is non-empty and contains no unavailable items; card fields are valid |
| **Trigger** | The User clicks Checkout on P-09 |
| **Steps** | 1. The system re-validates every item's price and stock **inside a transaction**, using row locking. 2. If a price changed, the updated total is presented for confirmation. 3. The simulated processor returns a success outcome. 4. The system creates the order and order items, reduces stock, inserts a `payments` row (`card`/`success`/`order_id` set/`paid_at` set), and clears the cart. 5. The transaction commits and P-10 is rendered. |
| **Postconditions** | An order exists with its items; stock is reduced; the cart is empty; a successful payment is recorded |
| **Exceptions** | Validation failure → inline errors, **no payment record**; item out of stock → transaction rolled back, order not created, the item named; price changed → confirmation step before commit |
| **Requirements** | FR-4.1 … FR-4.6, FR-4.9 … FR-4.16 |

---

### UC-7 — Checkout with a Declined Card Payment

| Field | Detail |
|---|---|
| **Primary actor** | User |
| **Goal** | Attempt a card payment that is declined |
| **Preconditions** | As UC-6 |
| **Trigger** | The User clicks Checkout on P-09 using the declining test card |
| **Steps** | 1. Steps 1–2 of UC-6 proceed. 2. The simulated processor returns a decline. 3. The system inserts a `payments` row with `method='card'`, `status='failed'`, `order_id` **NULL**, `paid_at` NULL. 4. **No order is created, stock is unchanged, and the cart is unchanged.** 5. A decline message is shown and the User may retry. |
| **Postconditions** | One failed payment row exists with a NULL `order_id`; no order; the cart is intact |
| **Exceptions** | — |
| **Requirements** | FR-4.5, FR-4.6, FR-4.15 |

---

### UC-8 — Checkout with Cash on Delivery

| Field | Detail |
|---|---|
| **Primary actor** | User |
| **Goal** | Place an order to be paid on delivery |
| **Preconditions** | Authenticated with the `User` role; the cart is non-empty and contains no unavailable items; a valid address is supplied; COD is selected |
| **Trigger** | The User clicks Checkout on P-09 |
| **Steps** | 1. The card fields are hidden because COD is selected. 2. The system validates the address. 3. The system re-validates prices and stock in a transaction. 4. It creates the order with fulfilment status `placed` and the delivery-address snapshot, reduces stock, inserts a `payments` row (`cod`/`pending`/`order_id` set), and clears the cart. 5. P-10 is rendered showing payment status `pending`. |
| **Postconditions** | An order exists with status `placed`; stock is reduced; the cart is empty; a pending COD payment is recorded |
| **Exceptions** | As UC-6 |
| **Requirements** | FR-4.7, FR-4.8, FR-4.10, FR-4.14, FR-4.15 |

---

### UC-9 — Admin Manages Products and Images

| Field | Detail |
|---|---|
| **Primary actor** | Admin |
| **Goal** | Create, update or remove catalogue items |
| **Preconditions** | Authenticated with the `Admin` role; a valid category exists |
| **Trigger** | The Admin opens P-15 |
| **Steps** | 1. **Create:** the Admin submits name, description, price, stock, category and a **mandatory image**. The server validates the type and content and the 2 MB limit, stores the original, generates a thumbnail, and inserts the product — or rolls back and deletes any written files. 2. **Update:** the Admin edits any field and may upload a new image; the existing image may be retained; previous files are removed only after the new files are stored. 3. **Remove:** the Admin soft-deletes the product; it disappears from listings and search, and its URL returns 404. |
| **Postconditions** | The catalogue reflects the change immediately |
| **Exceptions** | Invalid image or oversize file → refused, nothing written, no orphaned files; a failed thumbnail generation → rollback plus explicit file deletion |
| **Requirements** | FR-6.2 … FR-6.5, FR-2.8, FR-2.12 |

---

### UC-10 — Admin Manages Categories

| Field | Detail |
|---|---|
| **Primary actor** | Admin |
| **Goal** | Maintain the category taxonomy |
| **Preconditions** | Authenticated with the `Admin` role |
| **Trigger** | The Admin opens P-16 |
| **Steps** | 1. The Admin adds, renames or removes a category. 2. If the category still has products, the removal is refused and the blocking products are named. 3. The Admin manually reassigns those products to another existing category. 4. The removal then succeeds. |
| **Postconditions** | The category list and the storefront filter reflect the change |
| **Exceptions** | Duplicate name → rejected; removal with products → refused with guidance. There is **no** automatic fallback category. |
| **Requirements** | FR-6.6, FR-2.3 |

---

### UC-11 — Admin Manages Orders and Fulfilment

| Field | Detail |
|---|---|
| **Primary actor** | Admin |
| **Goal** | Progress or cancel an order and settle a COD payment |
| **Preconditions** | Authenticated with the `Admin` role |
| **Trigger** | The Admin opens P-17 and then P-18 |
| **Steps** | 1. The Admin opens an order and sees the items, totals, customer, delivery address, fulfilment status and payment status. 2. The Admin sets a new fulfilment status; the system validates the transition. 3. To cancel, the Admin selects `cancelled`; stock is restored only for still-available, non-soft-deleted products, and the COD payment moves `pending → failed` (retaining its `order_id`). 4. For a COD order whose cash has been collected, the Admin confirms collection, moving the payment `pending → success`. |
| **Postconditions** | The order status and payment status are updated; the customer's view reflects the change |
| **Exceptions** | An illegal transition (for example `delivered → cancelled`) is refused. A failed card payment **cannot** be marked successful. Cancelling does not restore items to the customer's cart. |
| **Requirements** | FR-6.7, FR-6.8, FR-6.9, FR-5.7, BR-7, BR-9, BR-13, BR-15 |

---

### UC-12 — Admin Resets a User's Password

| Field | Detail |
|---|---|
| **Primary actor** | Admin |
| **Goal** | Recover a locked-out customer account |
| **Preconditions** | Authenticated with the `Admin` role |
| **Trigger** | The Admin opens P-19 and then P-20 |
| **Steps** | 1. The Admin selects a user and sets a temporary password. 2. The system stores the hash and sets `must_change_password = 1`. 3. The Admin communicates the temporary password out of band. 4. At the next login, the User is routed to P-04 and must change the password before reaching any other protected page. |
| **Postconditions** | The account's temporary password is in force and a password change is required |
| **Exceptions** | The Admin has **no** means to view an existing password or hash. |
| **Requirements** | FR-6.10, FR-1.9, FR-1.10 |

---

### UC-13 — User Reviews Order History

| Field | Detail |
|---|---|
| **Primary actor** | User |
| **Goal** | Inspect past purchases, including cancelled orders |
| **Preconditions** | Authenticated with the `User` role |
| **Trigger** | The User opens P-11 |
| **Steps** | 1. The system greets the User by name and lists recent orders with status. 2. The User opens an order and sees P-12 with items, totals, date, status, payment method and the delivery address. 3. For a cancelled order, the system shows the cancelled status and "This order was cancelled by the store. No payment is required." 4. The User changes their name or password from P-13. |
| **Postconditions** | No state change, except a profile edit where requested |
| **Exceptions** | Another customer's order → HTTP 403 with no data disclosed. A User with no orders → the empty-state message. |
| **Requirements** | FR-5.1 … FR-5.7 |

---

### UC-14 — Access Control Denial

| Field | Detail |
|---|---|
| **Primary actor** | User or Admin (the wrong role) |
| **Goal** | Be refused access to a resource outside the account's role |
| **Preconditions** | The account is authenticated with a role that does not permit the resource |
| **Trigger** | The user navigates to, or directly requests, a protected resource |
| **Steps** | 1. The request reaches the central authorisation mechanism. 2. The mechanism determines the account's **current** role from the database and checks the requested action against the capability matrix. 3. Access is denied and P-21 is rendered with HTTP 403. |
| **Postconditions** | No state change occurs |
| **Exceptions** | Unauthenticated access → redirect to login with HTTP 401 (FR-7.2) |
| **Requirements** | FR-7.1 … FR-7.5, §15.2 |

---

## 14. Role-Based Access Control (Normative)

### 14.1 Model
- Exactly two roles exist in v1.0: `User` and `Admin`. Each account holds exactly one role.
- **There is no permissions table.** Role capabilities are fixed in the application layer and defined in **one** place (FR-7.1).
- The capability matrix in §5.1 is **normative** and is the single source of truth for authorisation decisions.
- Additional roles may later be inserted as **data only**, with no schema migration — but the application-layer mapping would have to be extended at the same time, since there is no data-driven permission model.

### 14.2 Enforcement Rules
| ID | Rule |
|---|---|
| **RBAC-1** | Every protected request is authorised server-side through the central mechanism. |
| **RBAC-2** | Interface-level hiding is never the control. Every protected action has a server-side check. |
| **RBAC-3** | A User reaching an Admin resource receives **HTTP 403** (FR-7.4). |
| **RBAC-4** | An Admin reaching a shopping resource receives **HTTP 403** (FR-7.5). |
| **RBAC-5** | An unauthenticated request to a protected resource receives **HTTP 401** and a redirect to login (FR-7.2). |
| **RBAC-6** | The role used for a decision is the account's **current** database role, re-read per request (FR-7.3). |
| **RBAC-7** | Ownership checks are applied in addition to role checks: a User may access only their own orders, carts and profile (FR-5.2). |
| **RBAC-8** | Registration never grants a privileged role (FR-1.4). |
| **RBAC-9** | The Admin interface provides no means to set or change a role (FR-6.10). |

### 14.3 Request-to-Role Matrix
| Request class | Visitor | User | Admin |
|---|---|---|---|
| Landing, listing, detail, search, pagination | Allow | Allow | Allow |
| Register / log in | Allow | n/a | n/a |
| Cart operations (add, update quantity, remove) | Deny (401) | Allow | **Deny (403)** |
| Payment page, checkout, order confirmation | Deny (401) | Allow | **Deny (403)** |
| User dashboard, customer order detail, profile | Deny (401) | Allow | **Deny (403)** |
| Admin dashboard, product, category, order, user management | Deny (401) | **Deny (403)** | Allow |
| Fulfilment status change, COD confirmation | Deny (401) | **Deny (403)** | Allow |
| Admin-initiated password reset | Deny (401) | **Deny (403)** | Allow |
| Account deactivation (P1) | Deny (401) | **Deny (403)** | Allow (P1) |

---

## 15. Security Requirements

### 15.1 Error Page Behaviour — 404
A request to an unknown URL renders P-07 with **HTTP 404**. The same page and status serve the "Product no longer available" response, with wording adapted. **No stack trace, SQL fragment, file path or internal detail** is disclosed (NFR-R-05).

### 15.2 Access-Denial Behaviour — 403
A role-based denial renders P-21 with **HTTP 403** and discloses only that the request was refused and which resources the account's role permits. **No data from the attempted resource** is included in the response.

### 15.3 Unexpected Server Error Behaviour — 500
An unexpected error renders P-22 with **HTTP 500** and a **generic** message ("Something went wrong. Please try again."). The underlying exception is **logged server-side** and is **never** rendered to the client. A failed database transaction is rolled back.

### 15.4 Input Validation and Upload Hardening
| ID | Rule |
|---|---|
| **SEC-1** | All database access uses **prepared statements** with bound parameters (NFR-S-03). String concatenation into SQL is prohibited. |
| **SEC-2** | All output derived from user input is **output-encoded** for the target context, including HTML attribute contexts. |
| **SEC-3** | Uploaded files are validated on **declared MIME type** *and* **actual image content** — the file must decode as a valid image. The extension alone is never trusted. |
| **SEC-4** | Uploads are limited to **JPG, PNG, WebP** and **2 MB**. Oversize requests are rejected before full buffering where possible. |
| **SEC-5** | Uploaded files are stored with **generated filenames** — the client-supplied filename is never used verbatim, preventing path traversal and overwriting. |
| **SEC-6** | Uploaded files are stored **outside the web root**, or served through a handler that sets a non-executable content type, so an uploaded file can never be executed. |
| **SEC-7** | Uploaded filenames are never reflected unencoded in HTML output or error messages. |
| **SEC-8** | Failed uploads leave no orphaned files (FR-6.4). |

### 15.5 Session and Authentication Security
| ID | Rule |
|---|---|
| **SEC-9** | The session identifier shall be regenerated on login (FR-1.5) and on password change (FR-5.6), to prevent session fixation. |
| **SEC-10** | The session cookie shall carry `HttpOnly`, `SameSite=Lax`, `use_strict_mode=1`, `sid_length=32`, and an environment-appropriate `Secure` flag (FR-7.6). |
| **SEC-11** | Logout shall destroy the server-side session record, not merely clear the browser cookie (FR-1.6). |
| **SEC-12** | Login failure messages shall be generic and shall not disclose account existence (FR-1.5). |
| **SEC-13** | Login attempts shall be rate-limited (NFR-S-06). |
| **SEC-14** | Account status and role shall be re-read from the database on every authenticated request (FR-7.3). |

### 15.6 Data Protection
| ID | Rule |
|---|---|
| **SEC-15** | **No card instrument data** shall be persisted, logged or echoed (FR-4.9, NFR-S-09). |
| **SEC-16** | The `payments` table shall contain no column capable of storing a card number. |
| **SEC-17** | Passwords shall be stored only as salted hashes; no plaintext shall appear in the database, in logs, or in error output (FR-1.3). |
| **SEC-18** | The Admin interface shall provide no means to retrieve an existing password or hash (FR-6.10). |
| **SEC-19** | The database shall run under **strict SQL mode** so that invalid data is rejected rather than silently coerced (R-SQL-1 … R-SQL-6). |
| **SEC-20** | `INSERT IGNORE` and `UPDATE IGNORE` are prohibited in application code, so that a skipped write can never be mistaken for a successful one (R-SQL-7). |
| **SEC-21** | Delivery addresses shall be visible only to the order's owner and to an Admin (FR-5.2, FR-6.8). |

---

## 16. Error Handling and Validation

### 16.1 Validation Rules
| ID | Rule | Failure response |
|---|---|---|
| **ER-1** | Registration fields: name required and within length; email required, well-formed and unique; password at least 8 characters. | Inline field errors; no row created (FR-1.1, FR-1.2, FR-1.3). |
| **ER-2** | Login: email and password required and well-formed. | Generic error message (FR-1.5). |
| **ER-3** | Product fields: name required; description optional; price required, non-negative, at most two decimal places; stock required, a non-negative integer; category required and existing; **image required on create**. | Inline errors; no row created (FR-6.2). |
| **ER-4** | Category name required, within length, and unique. | Inline error; no row created (FR-6.6). |
| **ER-5** | Cart quantity must be an integer of at least 1 and no greater than current available stock. | Clamped down with a notification, or rejected with a message (FR-3.6). |
| **ER-6** | Card fields: cardholder name required; card number required, digits only and of valid length; expiry required and in a valid future format; CVV required and of valid length. | Inline errors; **no payment record created** (FR-4.3). |
| **ER-7** | Delivery address: recipient name, phone, street address and city/area are all required. | Inline errors; the order is not created (FR-4.8). |
| **ER-8** | Search term and category identifier are sanitised and validated before use in a query. | Invalid values are ignored or rejected; the request does not error out (FR-2.5, FR-2.10). |
| **ER-9** | Page number must be a positive integer within range. | Falls back to a valid page (FR-2.10). |
| **ER-10** | A requested product, order, category or user must exist and be visible to the requester. | 404 for an unknown or soft-deleted product; 403 for another customer order (FR-2.8, FR-2.13, FR-5.2). |

### 16.2 Error Handling Rules
| ID | Rule |
|---|---|
| **ER-11** | Every database operation shall be checked for failure. The application shall not infer success from the absence of an exception (NFR-M-05). |
| **ER-12** | `INSERT IGNORE` and `UPDATE IGNORE` shall not be used. A write is either performed as an ordinary `INSERT`/`UPDATE` and verified, or it is not performed (R-SQL-7). |
| **ER-13** | After a statement, the application shall capture the **warning count immediately** — before executing any other query — and inspect the warnings if the count is non-zero (R-SQL-8). |
| **ER-14** | The application shall not treat `affected_rows = 0` from an `UPDATE` as proof of non-existence; it shall either enable `CLIENT_FOUND_ROWS` or verify existence with a separate `SELECT` (R-SQL-9). |
| **ER-15** | A foreign-key error shall be interpreted precisely: **1452** `ER_NO_REFERENCED_ROW_2` = a child write referenced a missing parent; **1451** `ER_ROW_IS_REFERENCED_2` = a parent delete/update was attempted while referenced (R-SQL-10). |
| **ER-16** | A `CHECK` constraint violation (error **3819**, `ER_CHECK_CONSTRAINT_VIOLATED`) shall be treated as a rejected write and handled as a validation failure, not retried silently. |
| **ER-17** | A duplicate-key error (**1062**, `ER_DUP_ENTRY`) shall be handled per its context — for example, treating a duplicate cart insert as "increment the existing row" (FR-3.3). |
| **ER-18** | **ENUM validation.** Under strict mode, an invalid `ENUM` value raises an error rather than being coerced. The canonical error is **1265 Data truncated** for an invalid member. **[Confirmed]** There is one documented special case: the *string* `'0'` is interpreted by MySQL as the **enum index**, so it is rejected as an invalid empty-string member — **even under strict mode**. Defence in depth: the application validates the value against the permitted set before the query, and `chk_payment_order_binding` independently rejects the error member. The application shall **not** rely on a specific error code being the only signal that a value is invalid. |
| **ER-19** | An unexpected server error shall render P-22 with a generic message and HTTP 500. The underlying exception shall be logged server-side and never rendered (NFR-R-05, §15.3). |
| **ER-20** | A failed checkout transaction shall roll back completely, leaving no order, no order items, no payment row and unchanged stock (FR-4.15). |
| **ER-21** | A failed image upload shall roll back the database operation **and** explicitly delete any files already written (FR-6.4). |
| **ER-22** | A JSON endpoint failure shall return an appropriate HTTP status and a machine-readable error code alongside a human-readable message (§9.4). |
| **ER-23** | An out-of-stock or soft-deleted item detected at checkout shall abort the order, roll back, and name the offending item to the User (FR-4.11). |

### 16.3 HTTP Status Summary
| Status | Meaning | Where used |
|---|---|---|
| **200** | Success | Normal page renders, successful AJAX operations |
| **302** | Redirect | Post-login landing, post-logout, post-checkout navigation |
| **400** | Malformed request | Unparseable parameters |
| **401** | Unauthenticated | Protected page or endpoint accessed without a session (FR-7.2) |
| **403** | Forbidden | Wrong role for the resource (FR-7.4, FR-7.5); another customer order (FR-5.2) |
| **404** | Not found | Unknown URL (FR-2.13); soft-deleted or non-existent product (FR-2.8) |
| **409** | Conflict | Duplicate key, stock conflict, illegal state transition |
| **422** | Validation failure | Field-level validation errors on an API endpoint |
| **500** | Server error | Unexpected exception; generic page P-22 |

---

## 17. Acceptance Criteria

### 17.1 Feature-Level Acceptance Criteria

| # | Scenario | Pass condition | Requirements |
|---|---|---|---|
| AC-1 | A new visitor registers, logs in and reaches the User Dashboard | The account is created with the `User` role, a salted hash and `is_active = 1`; login establishes a DB-backed session; the landing page is P-11 | FR-1.1 … FR-1.5, FR-1.7, FR-7.6 |
| AC-2 | Duplicate email registration | The registration is refused with a clear message; no second row is created; the database rejects a direct duplicate with 1062 | FR-1.2 |
| AC-3 | A weak password | Registration is refused with an inline message; nothing is stored | FR-1.3 |
| AC-4 | Registration cannot grant Admin | A request carrying `role_id` or `role = Admin` still yields a `User` account; the first registered account is not an Admin | FR-1.4 |
| AC-5 | Products display image-led with category filter | Each card leads with a thumbnail, then the name and price; the category filter narrows the set and combines with search using AND | FR-2.1, FR-2.2, FR-2.4 |
| AC-6 | Name search | "fern" matches "Boston Fern"; "FERN" matches identically; a multi-word term requires all words; no match shows "No products found" with a Clear Search action | FR-2.5, FR-2.6, FR-2.9 |
| AC-7 | Pagination | 25 products produce pages of 12/12/1; the page is in the URL; filters survive pagination; changing a filter resets to page 1; an invalid page falls back | FR-2.10 |
| AC-8 | Sorting | Newest products appear first; no sort control is offered; the order is deterministic | FR-2.11 |
| AC-9 | Add to Cart from the listing | The count updates with no page reload; a toast with a "View Cart" action appears; the count survives a reload; adding twice yields quantity 2 | FR-3.1, FR-3.3 |
| AC-10 | Add to Cart from the detail page | The user is taken to the Cart page with the item present | FR-3.12 |
| AC-11 | Visitor Add to Cart | The visitor is redirected to login; after login the item is added exactly once; a second login does not re-add it; an unavailable product produces a message | FR-3.4 |
| AC-12 | Cart management | Items can be removed and their quantities changed without a page reload; totals recalculate; a quantity below 1 is rejected; a quantity above stock is clamped with a notification | FR-3.5, FR-3.6, FR-3.8 |
| AC-13 | Cart price change | After an Admin changes a price, the cart shows the old and the new price and the totals use the new price | FR-3.7 |
| AC-14 | Cart persistence | Cart contents survive logout/login and session expiry | FR-3.10 |
| AC-15 | Unavailable cart item | A zero-stock or soft-deleted item shows "No longer available" and blocks checkout, and is not removed automatically | FR-3.8, FR-6.5 |
| AC-16 | Payment page | The order summary, delivery address form and payment form are present; Card is selected by default; COD hides the card fields immediately | FR-4.1, FR-4.2 |
| AC-17 | Card validation failure | Inline errors are shown; **no payment row is created**; the cart is unchanged | FR-4.3 |
| AC-18 | Card success | The order and items exist, stock is reduced, the cart is cleared, one `payments` row exists with `card`/`success`/`paid_at` set/`order_id` set, and P-10 is shown | FR-4.4, FR-4.13, FR-4.15 |
| AC-19 | Card decline | **No order** is created, stock is unchanged, the cart is unchanged, one `payments` row exists with `card`/`failed`/`order_id` **NULL**, and a decline message is shown | FR-4.5, FR-4.6 |
| AC-20 | Cash on Delivery | The order is created with status `placed`, stock is reduced, the cart is cleared, and one `payments` row exists with `cod`/`pending`/`order_id` set | FR-4.7, FR-4.10 |
| AC-21 | Delivery address snapshot | The address is required, stored on the order, unchangeable afterwards, and visible on the Admin order detail page | FR-4.8, FR-6.8 |
| AC-22 | Checkout re-validation | An item that went out of stock prevents the order; nothing partial survives; the User is told which item; two simultaneous checkouts for the last unit yield one order | FR-4.11, FR-4.15 |
| AC-23 | Order Confirmation page | The order number, itemised summary, total, method and payment status are shown, with a dashboard link — a distinct page from the dashboard | FR-4.13, FR-4.14 |
| AC-24 | Admin product management | A product is created with a mandatory image; an update may retain the image; a change appears on the storefront immediately | FR-6.2, FR-6.3 |
| AC-25 | Image validation and atomicity | A non-image file and an oversize file are rejected; a thumbnail failure leaves no files, no row, and the previous image intact | FR-2.12, FR-6.4 |
| AC-26 | Product removal | The product is absent from listings and search; its URL returns 404 with "Product no longer available"; the row survives; past orders still show it | FR-2.8, FR-6.5 |
| AC-27 | Category management | Categories are added, renamed and removed; a category with products cannot be removed until they are manually reassigned; there is no "Uncategorised" fallback | FR-2.3, FR-6.6 |
| AC-28 | Admin order management | All orders are listed with customer, number, date, fulfilment status, total and a distinct payment status; the order detail shows items, totals, address and customer | FR-6.7, FR-6.8 |
| AC-29 | Fulfilment transitions | `placed → processing → shipped → delivered` succeed; `delivered → cancelled` is refused; `shipped → processing` is permitted; a customer has no control | FR-6.8, BR-7 |
| AC-30 | Order cancellation | Stock is restored only for still-available products; a soft-deleted product is logged for manual handling; the cart is not restored; the COD payment becomes `failed` with its `order_id` retained | FR-6.8, BR-13, BR-15 |
| AC-31 | COD cash confirmation | The payment moves `pending → success` with `paid_at` set, visible on both the Admin list and the customer order detail | FR-6.9 |
| AC-32 | Failed card payment immutability | No Admin control marks it successful, and a direct service-layer attempt is rejected | FR-6.9, BR-9 |
| AC-33 | Admin user list and password reset | The list shows name, email and registration date; a temporary password forces a change at next login; no password is ever viewable | FR-6.10, FR-1.9 |
| AC-34 | Dashboard and order history | The User is greeted by name; recent orders show status; the full order detail shows items, totals, date, status and address; another customer order returns 403 | FR-5.1, FR-5.2 |
| AC-35 | Cancelled orders stay visible | A cancelled order remains in the history and the recent-orders list, with the cancelled status, the store-cancellation explanation, and all its items intact | FR-5.7 |
| AC-36 | Profile editing | The User changes their own name and password; the current password is required to change a password; role and status fields are ignored | FR-5.5, FR-5.6 |
| AC-37 | Role separation | A User reaching any Admin resource receives 403; an Admin reaching any shopping resource receives 403; an unauthenticated request receives 401 | FR-7.2 … FR-7.5, RBAC-1 … RBAC-9 |
| AC-38 | Session security | Sessions are DB-backed and survive a process restart; the cookie is httpOnly and SameSite=Lax; `Secure` is present under HTTPS and absent in local HTTP dev; the 8-hour and 7-day limits are enforced server-side; no JWT or browser-storage token exists | FR-1.10, FR-7.6, NFR-S-10 |
| AC-39 | CSRF | A state-changing request without a valid token is rejected with no state change, including the background cart endpoints | FR-7.7 |
| AC-40 | Seed script | The script creates 2 roles, 1 Admin, 1 test User, 5 categories and 25–30 products with images; a second run creates no duplicates; it refuses to run in production | FR-6.12 |
| AC-41 | Strict SQL mode | A connection reports `STRICT_TRANS_TABLES` in `@@SESSION.sql_mode`; an invalid insert raises an error rather than being coerced | R-SQL-1 … R-SQL-6, NFR-S-07 |
| AC-42 | No IGNORE in writes | No `INSERT IGNORE` or `UPDATE IGNORE` appears in the code base; a skipped write is never mistaken for a successful one | R-SQL-7, NFR-M-04 |
| AC-43 | Payment data model | Only the five permitted `(method, status, order_id)` shapes can be stored; `orders` has no `payment_status`; a second successful payment for one order is rejected with 1062 | BR-8, FR-4.16 |
| AC-44 | Error pages | An unknown URL returns 404 (P-07); a role denial returns 403 (P-21); an unexpected error returns 500 (P-22) with a generic message and no internal detail | FR-2.13, §15.1 … §15.3 |
| AC-45 | Account deactivation (P1) | A deactivated account cannot log in; its existing session fails on the next request; order history is retained; reactivation restores access | FR-6.11, FR-7.3, BR-14 |

### 17.2 Exit Criteria for v1.0
1. All **P0** requirements are implemented and their acceptance criteria pass: FR-1.1 … FR-1.10 (excluding the retired FR-1.8), FR-2.1 … FR-2.13, FR-3.1 … FR-3.12, FR-4.1 … FR-4.16, FR-5.1 … FR-5.7, FR-6.1 … FR-6.10, FR-6.12, FR-7.1 … FR-7.7.
2. All **P0** acceptance criteria AC-1 … AC-44 pass, excluding AC-45 (P1).
3. The seed script produces a runnable demo: an Admin can log in and manage the catalogue, and the test User can complete a full card checkout and a full COD checkout.
4. No open **P0** defect remains.
5. The traceability matrix shows every PRD feature mapped to at least one SRS requirement with a testable acceptance criterion.

---

## 18. System Constraints and Assumptions

### 18.1 Constraints
| ID | Constraint |
|---|---|
| **SC-1** | **Payment is simulated in v1.0.** No real money moves. A real gateway must be integrated before the store goes live (PRD §13.1). |
| **SC-2** | **MySQL 8.0.16 or later is mandatory**, because `CHECK` constraints are enforced only from that version. |
| **SC-3** | The stack is **plain PHP MVC + MySQLi + server-rendered HTML/CSS** with targeted JavaScript. There is **no SPA, no React, no Vue, no Inertia.js, no Laravel and no Blade** (PRD §1). |
| **SC-4** | **No email service** is a dependency in v1.0; password recovery is Admin-initiated (FR-6.10). |
| **SC-5** | **Exactly two roles** exist. There is no data-driven permission model. |
| **SC-6** | The store currency is a **single configurable value**, and it **must not be changed once orders exist** (BR-16). |
| **SC-7** | **No tax** is calculated, displayed, or invoiced in v1.0 (BR-2). |
| **SC-8** | **Search is name-only, substring, case-insensitive.** No fuzzy, full-text or typo-tolerant matching (FR-2.5). |
| **SC-9** | **Pagination is fixed at 12 per page** on all screen sizes, with no user-configurable page size (FR-2.10). |
| **SC-10** | **Sorting is fixed at newest-first.** No featured flag, no alternative sorts (FR-2.11). |
| **SC-11** | Products are **never hard-deleted**; the catalogue row always survives for order history and cart references (FR-6.5). |
| **SC-12** | **Guest checkout is not supported**; registration and login are prerequisites for shopping (PRD §3.2). |
| **SC-13** | **Customers cannot cancel their own orders.** Cancellation is Admin-only (FR-6.8). |
| **SC-14** | **COD is available for every product**, with no per-product restriction (FR-4.10). |
| **SC-15** | The **checkout-error-rate metric** depends on declined attempts persisting a payment record; this is a deliberate data-retention decision (FR-4.5, PRD §11). |
| **SC-16** | The **seed script is development-only** and refuses to run in production (FR-6.12, BR-17). |

### 18.2 Assumptions
| ID | Assumption |
|---|---|
| **SA-1** | **[Confirmed]** Product photos and descriptions are supplied by the store owner. |
| **SA-2** | **[Confirmed]** A hosting environment and database are available. |
| **SA-3** | **[Confirmed]** No email service is required for v1.0. |
| **SA-4** | **[Assumption]** The store fulfils orders manually; no courier integration is required in v1.0. |
| **SA-5** | **[Assumption]** The configured currency remains unchanged once orders exist. |
| **SA-6** | **[Assumption]** The Admin is the only staff account in v1.0; no third role is planned for the next 6–12 months. |
| **SA-7** | **[Assumption]** A single developer delivers v1.0; the release-phase durations in PRD §12.2 reflect that. |
| **SA-8** | **[Assumption]** Storefront images for the initial catalogue are realistic local placeholders, replaced later with real photography. |
| **SA-9** | **[Assumption]** The checkout-error-rate metric is measured as failed payment attempts divided by total payment attempts. |
| **SA-10** | **[Assumption]** The address fields collected at checkout (name, phone, street address, city/area) are sufficient for manual fulfilment in the target market. |

### 18.3 External Dependencies
| Dependency | Needed for | Status |
|---|---|---|
| PHP runtime with MySQLi | Application runtime | Required |
| MySQL 8.0.16+ | Data store; `CHECK` constraint enforcement | Required |
| Web server (Apache/Nginx) | Hosting | Required |
| HTTPS certificate | Transport security | Required for production |
| Docker | Portability and deployment | Required for production packaging |
| Image processing extension (`GD`/`Imagick`) | Thumbnail generation | Required |
| Product photos | Catalogue realism | Supplied by the store owner; placeholders provided |
| Email/SMTP provider | — | **Not required** — out of scope for v1.0 |
| Payment gateway account | — | **Not required** — out of scope for v1.0 |

---

## Appendix A — Requirement Traceability

Every PRD feature maps to the SRS requirements and acceptance criteria listed here. The complete matrix — including the 22-page inventory and the retired/replaced v1.0 requirements — is maintained in:

**`GreenCorner_Requirement_Traceability_Matrix.md`**

## Appendix B — Document History

| Version | Date | Change |
|---|---|---|
| 1.0 | October 31, 2026 | Initial draft. Seven functional-requirement groups with 37 FRs, no roles, no preconditions, no acceptance criteria, a table-list database section, and a non-binding technology suggestion. |
| 2.0 | November 1, 2026 | Rewritten following a structured requirements-grilling session. Now 18 sections. Every FR carries a unique ID, a responsible role, preconditions, expected behaviour and acceptance criteria. Added: the 22-page inventory (§4.3); the full database specification with the final `payments` DDL and a 16-row schema delta (§10); the MySQL strict-mode, `IGNORE`-prohibition and diagnostics specification (§6.4); 14 use cases with preconditions, steps, postconditions and exceptions (§13); the normative RBAC model and request-to-role matrix (§14); 21 security rules (§15); 23 error-handling and validation rules (§16); 45 feature-level acceptance criteria plus exit criteria (§17); 16 constraints and 10 assumptions (§18). FR-1.8 (email reset) retired and FR-4.8 (no card storage) superseded; FR-3.1 rewritten for hybrid add-to-cart with FR-3.12 added for the detail-page variant; FR-6.10 absorbs admin-initiated password reset and FR-6.11 carries the deferred deactivation behaviour. |
