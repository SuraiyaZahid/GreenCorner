# GreenCorner – Requirement Traceability Matrix

**Version:** 2.0  |  **Status:** Approved (Grilling Session Complete)  |  **Date:** November 1, 2026
**Companion documents:** `GreenCorner_PRD.md` (v2.0), `GreenCorner_SRS.md` (v2.0)

> **Purpose.** This matrix closes the loop between the PRD and the SRS. It maps every PRD feature to the SRS requirements that implement it, to the use cases that exercise it, and to the acceptance criteria that verify it. It also records the v1.0 requirements that were retired or superseded, so nothing is silently lost.
>
> **Reading the matrix.** A row with a complete mapping means the PRD feature is fully traceable to at least one implemented requirement and at least one testable acceptance criterion. Rows marked **[Confirmed]** were explicitly decided in the grilling session; rows marked **[Assumption]** close a gap with a stated default.

---

## 1. Coverage Summary

| Metric | Count |
|---|---|
| PRD feature groups traced | 26 |
| PRD page-inventory entries traced | 22 |
| SRS functional requirements (FR-1 … FR-7) | 60 (59 active + 1 retired) |
| SRS non-functional requirements | 33 (NFR-P, NFR-S, NFR-U, NFR-R, NFR-M, NFR-PT) |
| SRS business rules | 17 |
| SRS use cases | 14 |
| SRS database-configuration rules | 10 |
| SRS security rules | 21 |
| SRS error-handling / validation rules | 23 |
| SRS acceptance criteria | 45 |
| v1.0 requirements retired or superseded | 5 |
| **Unmapped PRD features** | **0** |
| **Unmapped SRS requirements** | **0** |

---

## 2. PRD Feature → SRS Requirement Matrix

### 2.1 Authentication and Registration (PRD §7.1)

| PRD feature | SRS requirement | Use case | Acceptance criterion | Priority | Provenance |
|---|---|---|---|---|---|
| Register with name, email, password | FR-1.1 | UC-1 | AC-1 | P0 | [Confirmed] |
| Email unique; duplicate rejected | FR-1.2, ER-1 | UC-1 | AC-2 | P0 | [Confirmed] |
| Password ≥ 8 chars, salted hash only | FR-1.3, NFR-S-02, SEC-17 | UC-1 | AC-3 | P0 | [Confirmed] |
| Every registration gets the `User` role; first User never Admin | FR-1.4, RBAC-8 | UC-1 | AC-4 | P0 | [Confirmed] |
| Log in with email and password | FR-1.5 | UC-1 | AC-1 | P0 | [Confirmed] |
| Log out invalidates the session | FR-1.6, SEC-11 | — | AC-38 | P0 | [Confirmed] |
| Users → User Dashboard; Admins → Admin Dashboard | FR-1.7 | UC-1 | AC-1 | P0 | [Confirmed] |
| Server-side, DB-backed sessions; no JWT / no Remember Me | FR-7.6, NFR-S-10, SEC-9, SEC-10 | UC-1, UC-14 | AC-38 | P0 | [Confirmed] |
| 8-hour inactivity / 7-day absolute lifetime | FR-1.10, FR-7.6 | — | AC-38 | P0 | [Confirmed] |
| Admin-initiated password reset; User must change at next login | FR-1.9, FR-6.10 | UC-12 | AC-33 | P0 | [Confirmed] |
| Email self-service reset **out of scope** | FR-1.8 (RETIRED), SC-4 | — | — | Out | [Confirmed] |
| Wrong-password error does not reveal which field was wrong | FR-1.5, ER-2, SEC-12 | UC-1 | AC-1 | P0 | [Confirmed] |
| Login attempts rate-limited | NFR-S-06, SEC-13 | UC-1 | — | P0 | [Confirmed] |

### 2.2 Product Catalogue (PRD §7.2)

| PRD feature | SRS requirement | Use case | Acceptance criterion | Priority | Provenance |
|---|---|---|---|---|---|
| Listing shows thumbnail, name, price, Add to Cart | FR-2.1 | UC-2 | AC-5 | P0 | [Confirmed] |
| Image-led theme; image leads the card, name beneath | FR-2.2 | UC-2 | AC-5 | P0 | [Confirmed] |
| Each product in exactly one category | FR-2.3, ER-4 | UC-10 | AC-27 | P0 | [Confirmed] |
| Category filter | FR-2.4 | UC-2 | AC-5 | P0 | [Confirmed] |
| Search by name, case-insensitive substring | FR-2.5, NFR-U-05 | UC-2 | AC-6 | P0 | [Confirmed] |
| Multi-word search — all words must match | FR-2.6 | UC-2 | AC-6 | P0 | [Confirmed] |
| Search AND category, category preserved | FR-2.4 | UC-2 | AC-5, AC-6 | P0 | [Confirmed] |
| Search input whitespace trimmed | FR-2.5 | UC-2 | AC-6 | P0 | [Confirmed] |
| "No products found" with a Clear Search action | FR-2.9 | UC-2 | AC-6 | P0 | [Confirmed] |
| Pagination — classic controls, 12/page all screens | FR-2.10, NFR-P-04 | UC-2 | AC-7 | P0 | [Confirmed] |
| Page number in URL; filters preserved across pages | FR-2.10 | UC-2 | AC-7 | P0 | [Confirmed] |
| Filter change resets to page 1; invalid page falls back | FR-2.10, ER-9 | UC-2 | AC-7 | P0 | [Confirmed] |
| Sorting — newest first; no featured flag | FR-2.11, SC-10 | UC-2 | AC-8 | P0 | [Confirmed] |
| Detail page shows original image, description, price, stock, category | FR-2.7, FR-2.12 | UC-2 | AC-24 | P0 | [Confirmed] |
| Out-of-stock marked; Add to Cart disabled | FR-2.7 | UC-2 | AC-24 | P0 | [Confirmed] |
| Soft-deleted excluded from listings **and** search | FR-2.8, FR-6.5 | UC-9 | AC-26 | P0 | [Confirmed] |
| Soft-deleted URL → "Product no longer available", HTTP 404 | FR-2.8, FR-2.13, §15.1 | UC-9 | AC-26, AC-44 | P0 | [Confirmed] |

### 2.3 Cart (PRD §7.3)

| PRD feature | SRS requirement | Use case | Acceptance criterion | Priority | Provenance |
|---|---|---|---|---|---|
| Add to Cart from listing — background, no reload | FR-3.1, NFR-P-05 | UC-3 | AC-9 | P0 | [Confirmed] |
| Cart count updates without a page reload | FR-3.1 | UC-3 | AC-9 | P0 | [Confirmed] |
| Success toast with a "View Cart" action | FR-3.1 | UC-3 | AC-9 | P0 | [Confirmed] |
| Add to Cart from detail page → navigates to Cart | FR-3.12 | UC-3 | AC-10 | P0 | [Confirmed] |
| Duplicate prevented — quantity incremented, never a second row | FR-3.3, ER-17 | UC-3 | AC-9 | P0 | [Confirmed] |
| Visitor → login redirect; pending add held in session | FR-3.4 | UC-4 | AC-11 | P0 | [Confirmed] |
| Pending add re-validated for stock after login; cleared afterwards | FR-3.4 | UC-4 | AC-11 | P0 | [Confirmed] |
| Unavailable/out-of-stock pending add → message, not added | FR-3.4 | UC-4 | AC-11 | P0 | [Confirmed] |
| Cart page shows image, name, unit price, quantity, line total, total | FR-3.2 | UC-5 | AC-12 | P0 | [Confirmed] |
| Remove any item | FR-3.5 | UC-5 | AC-12 | P0 | [Confirmed] |
| Change quantity | FR-3.6 | UC-5 | AC-12 | P0 | [Confirmed] |
| Quantity auto-reduced when stock is lower, with notification | FR-3.8 | UC-5 | AC-12 | P0 | [Confirmed] |
| Cart shows current price; price change notified with old and new | FR-3.7, BR-12 | UC-5 | AC-13 | P0 | [Confirmed] |
| Cart persists between sessions | FR-3.10 | UC-5 | AC-14 | P0 | [Confirmed] |
| Empty cart message + link back to listing | FR-3.9 | UC-5 | — | P0 | [Confirmed] |
| Proceed to Payment button | FR-3.11 | UC-5 | — | P0 | [Confirmed] |
| Unavailable cart item → "No longer available", checkout blocked | FR-3.8 | UC-5 | AC-15 | P0 | [Confirmed] |
| Soft-deleted cart item kept visible, never auto-removed | FR-3.8, FR-6.5 | UC-5 | AC-15 | P0 | [Confirmed] |

### 2.4 Pricing and Stock Rules (PRD §7.4)

| PRD feature | SRS requirement | Use case | Acceptance criterion | Priority | Provenance |
|---|---|---|---|---|---|
| Cart displays current price; `price_at_add` for detection only | FR-3.7, BR-12 | UC-5 | AC-13 | P0 | [Confirmed] |
| Price re-validated at checkout; updated total shown | FR-4.12 | UC-6 | AC-22 | P0 | [Confirmed] |
| Stock re-validated at checkout | FR-4.11, BR-11 | UC-6 | AC-22 | P0 | [Confirmed] |
| Price at purchase recorded as an immutable snapshot | FR-4.4, BR-6, FR-5.2 | UC-6 | AC-18 | P0 | [Confirmed] |
| Product name snapshotted on the order line | BR-5 | UC-6 | AC-18 | P0 | [Confirmed] |
| Stock reduced when an order is created (incl. COD) | BR-10, FR-4.7 | UC-6, UC-8 | AC-18, AC-20 | P0 | [Confirmed] |
| A failed payment never reduces stock | BR-10, FR-4.5 | UC-7 | AC-19 | P0 | [Confirmed] |
| Row locking prevents overselling | FR-4.11, BR-11 | UC-6 | AC-22 | P0 | [Confirmed] |

### 2.5 Payment and Checkout (PRD §7.5)

| PRD feature | SRS requirement | Use case | Acceptance criterion | Priority | Provenance |
|---|---|---|---|---|---|
| Payment page shows order summary, address form, payment form | FR-4.1 | UC-6 | AC-16 | P0 | [Confirmed] |
| Card / COD chosen via radio buttons, mutually exclusive | FR-4.2, FR-4.10 | UC-6, UC-8 | AC-16 | P0 | [Confirmed] |
| Card selected by default | FR-4.2 | UC-6 | AC-16 | P0 | [Confirmed] |
| Card fields show/hide immediately | FR-4.2 | UC-6 | AC-16 | P0 | [Confirmed] |
| Both methods available for every product | FR-4.10, SC-14 | UC-6, UC-8 | AC-20 | P0 | [Confirmed] |
| Card fields validated client-side and server-side | FR-4.3, ER-6 | UC-6 | AC-17 | P0 | [Confirmed] |
| Simulated card supports **success and decline** | FR-4.4, FR-4.5, FR-4.6 | UC-6, UC-7 | AC-18, AC-19 | P0 | [Confirmed] |
| Card success → order, stock reduced, cart cleared, payment recorded | FR-4.4, FR-4.15 | UC-6 | AC-18 | P0 | [Confirmed] |
| Card decline → failed payment, no order, stock and cart unchanged | FR-4.5, BR-10 | UC-7 | AC-19 | P0 | [Confirmed] |
| Validation failure → no payment record at all | FR-4.3 | UC-6 | AC-17 | P0 | [Confirmed] |
| COD → order created immediately, status `placed`, payment `pending` | FR-4.7 | UC-8 | AC-20 | P0 | [Confirmed] |
| COD reduces stock and clears the cart at creation | FR-4.7, BR-10 | UC-8 | AC-20 | P0 | [Confirmed] |
| Delivery address collected, stored as an immutable snapshot | FR-4.8, ER-7 | UC-8 | AC-21 | P0 | [Confirmed] |
| No courier API / no automatic tracking | SC-1, §9.3 | — | — | P0 | [Confirmed] |
| Order Confirmation page after successful checkout | FR-4.13 | UC-6, UC-8 | AC-23 | P0 | [Confirmed] |
| Unique human-readable order number | FR-4.14 | UC-6 | AC-23 | P0 | [Confirmed] |
| Transactional checkout — all or nothing | FR-4.15, NFR-R-02 | UC-6 | AC-18 | P0 | [Confirmed] |
| Real card details never stored | FR-4.9, SEC-15, SEC-16 | — | AC-43 | P0 | [Confirmed] |

### 2.6 Order Fulfilment (PRD §7.6)

| PRD feature | SRS requirement | Use case | Acceptance criterion | Priority | Provenance |
|---|---|---|---|---|---|
| Fulfilment status ∈ {placed, processing, shipped, delivered, cancelled} | FR-6.8, BR-7 | UC-11 | AC-29 | P0 | [Confirmed] |
| System sets `placed` automatically | FR-4.7, FR-6.8 | UC-11 | AC-29 | P0 | [Confirmed] |
| Only Admin changes fulfilment status | FR-6.8, BR-7 | UC-11 | AC-29 | P0 | [Confirmed] |
| Customers cannot change status or cancel | FR-6.8 | UC-11 | AC-29 | P0 | [Confirmed] |
| `cancelled` is terminal | FR-6.8, BR-7 | UC-11 | AC-29 | P0 | [Confirmed] |
| Backward transitions permitted | FR-6.8 | UC-11 | AC-29 | P0 | [Confirmed] |
| All transitions validated | FR-6.8, ER-16 | UC-11 | AC-29 | P0 | [Confirmed] |
| Cancel restores stock only for existing, non-soft-deleted products | FR-6.8, BR-13 | UC-11 | AC-30 | P0 | [Confirmed] |
| Unrestorable cases logged for manual handling | FR-6.8, BR-13 | UC-11 | AC-30 | P0 | [Confirmed] |
| Cancel does not restore items to the cart | FR-6.8, BR-13 | UC-11 | AC-30 | P0 | [Confirmed] |
| Cancelled COD payment moves pending → failed | FR-6.8, BR-15 | UC-11 | AC-30 | P0 | [Confirmed] |
| Admin confirms COD cash collection | FR-6.9 | UC-11 | AC-31 | P0 | [Confirmed] |
| Failed card payment is immutable | FR-6.9, BR-9 | UC-11 | AC-32 | P0 | [Confirmed] |

### 2.7 User Dashboard (PRD §7.7)

| PRD feature | SRS requirement | Use case | Acceptance criterion | Priority | Provenance |
|---|---|---|---|---|---|
| Dashboard greets the user by name | FR-5.1 | UC-13 | AC-34 | P0 | [Confirmed] |
| Recent orders with status | FR-5.1 | UC-13 | AC-34 | P0 | [Confirmed] |
| Full order details | FR-5.2 | UC-13 | AC-34 | P0 | [Confirmed] |
| Cancelled orders remain visible with the store-cancellation notice | FR-5.7 | UC-13 | AC-35 | P0 | [Confirmed] |
| Cancelled order items and purchase-time info preserved | FR-5.7, BR-5, BR-6 | UC-13 | AC-35 | P0 | [Confirmed] |
| Quick links to browse and cart | FR-5.3 | UC-13 | — | P0 | [Confirmed] |
| Empty order-history state | FR-5.4 | UC-13 | — | P0 | [Confirmed] |
| User can edit their own name | FR-5.5 | UC-13 | AC-36 | P0 | [Confirmed] |
| User can change their password with the current password | FR-5.6 | UC-13 | AC-36 | P0 | [Confirmed] |
| User can never change their own role | FR-5.5, RBAC-9 | UC-13 | AC-36 | P0 | [Confirmed] |
| A user may only view their own orders | FR-5.2, RBAC-7 | UC-13 | AC-34 | P0 | [Confirmed] |

### 2.8 Admin Panel (PRD §7.8)

| PRD feature | SRS requirement | Use case | Acceptance criterion | Priority | Provenance |
|---|---|---|---|---|---|
| Dashboard counts of products, orders, users | FR-6.1 | — | — | P0 | [Confirmed] |
| Product count excludes soft-deleted products | FR-6.1 | — | — | P0 | [Confirmed] |
| Add a product | FR-6.2 | UC-9 | AC-24 | P0 | [Confirmed] |
| Update any product field, including the image | FR-6.3 | UC-9 | AC-24 | P0 | [Confirmed] |
| Image mandatory on create; optional on update | FR-2.12, FR-6.3 | UC-9 | AC-24 | P0 | [Confirmed] |
| Image formats JPG/PNG/WebP, max 2 MB, server-side validated | FR-2.12, SEC-3, SEC-4, NFR-S-05 | UC-9 | AC-25 | P0 | [Confirmed] |
| Original **and** generated thumbnail retained | FR-2.12, FR-6.4 | UC-9 | AC-24 | P0 | [Confirmed] |
| Listing uses thumbnail; detail uses original | FR-2.1, FR-2.2, FR-2.7 | UC-2 | AC-5 | P0 | [Confirmed] |
| Lazy loading of images | FR-2.12, NFR-P-03 | UC-2 | — | P0 | [Confirmed] |
| Failure ⇒ DB rollback **and** explicit deletion of written files | FR-6.4, ER-21, NFR-R-04 | UC-9 | AC-25 | P0 | [Confirmed] |
| Previous image files deleted only after new ones are stored | FR-6.3, FR-6.4 | UC-9 | AC-25 | P0 | [Confirmed] |
| Removal is always a soft delete; never hard-deleted | FR-6.5 | UC-9 | AC-26 | P0 | [Confirmed] |
| Soft-deleted excluded from listings and search; not purchasable | FR-2.8, FR-6.5 | UC-9 | AC-26 | P0 | [Confirmed] |
| Soft-deleted in a cart → "No longer available", checkout blocked | FR-3.8, FR-6.5 | UC-5 | AC-15 | P0 | [Confirmed] |
| Add, rename, remove categories | FR-6.6 | UC-10 | AC-27 | P0 | [Confirmed] |
| Category with products cannot be removed until reassigned | FR-6.6 | UC-10 | AC-27 | P0 | [Confirmed] |
| Reassignment is manual; no "Uncategorised" fallback | FR-6.6, FR-2.3 | UC-10 | AC-27 | P0 | [Confirmed] |
| View all orders and their details | FR-6.7, FR-6.8 | UC-11 | AC-28 | P0 | [Confirmed] |
| Update fulfilment status | FR-6.8 | UC-11 | AC-29 | P0 | [Confirmed] |
| Confirm COD cash collection | FR-6.9 | UC-11 | AC-31 | P0 | [Confirmed] |
| Order list shows payment status distinctly | FR-6.7, FR-4.16 | UC-11 | AC-28 | P0 | [Confirmed] |
| User list — name, email, registration date | FR-6.10 | UC-12 | AC-33 | P0 | [Confirmed] |
| Admin-initiated password reset | FR-6.10, FR-1.9 | UC-12 | AC-33 | P0 | [Confirmed] |
| Admin can never view an existing password | FR-6.10, SEC-18 | UC-12 | AC-33 | P0 | [Confirmed] |
| Deactivate / reactivate an account | FR-6.11, BR-14 | — | AC-45 | **P1** | [Confirmed] — behaviour specified, implementation deferred |
| Deactivated user cannot log in | FR-6.11, BR-14 | — | AC-45 | **P1** | [Confirmed] |
| Existing sessions invalid on the next authenticated request | FR-7.3, FR-6.11 | — | AC-45 | **P1** | [Confirmed] |
| Existing orders and history retained on deactivation | FR-6.11, BR-14 | — | AC-45 | **P1** | [Confirmed] |

### 2.9 Role-Based Access Control (PRD §7.9)

| PRD feature | SRS requirement | Use case | Acceptance criterion | Priority | Provenance |
|---|---|---|---|---|---|
| Every protected request checked server-side | FR-7.1, RBAC-1, RBAC-2, NFR-S-08 | UC-14 | AC-37 | P0 | [Confirmed] |
| One central authorisation mechanism | FR-7.1, NFR-M-03 | UC-14 | AC-37 | P0 | [Confirmed] |
| User → Admin page/endpoint ⇒ HTTP 403 | FR-7.4, RBAC-3 | UC-14 | AC-37 | P0 | [Confirmed] |
| Admin → shopping feature ⇒ HTTP 403 | FR-7.5, RBAC-4 | UC-14 | AC-37 | P0 | [Confirmed] |
| Unauthenticated ⇒ HTTP 401 redirect to login | FR-7.2, RBAC-5 | UC-14 | AC-37 | P0 | [Confirmed] |
| Role re-read from the database on every request | FR-7.3, RBAC-6, SEC-14 | UC-14 | AC-37 | P0 | [Confirmed] |
| Ownership checks in addition to role checks | RBAC-7, FR-5.2 | UC-13 | AC-34 | P0 | [Confirmed] |
| Two roles only; no permissions table | FR-1.4, SC-5 | — | AC-4 | P0 | [Confirmed] |
| `roles` table retained; extra roles are data-only | §10.3, PRD §5.3 | — | — | P0 | [Confirmed] |

### 2.10 Business Rules (PRD §8)

| PRD rule | SRS requirement | Acceptance criterion | Provenance |
|---|---|---|---|
| Single configurable currency, BDT (৳) | BR-1, SC-6 | — | [Confirmed] |
| Currency displayed consistently everywhere | BR-1, FR-2.1, FR-3.2, FR-4.1 | AC-5, AC-12, AC-16 | [Confirmed] |
| All money stored as `DECIMAL(10,2)`; floats prohibited | BR-1, NFR-M-01 | AC-24 | [Confirmed] |
| Admin price is the final price; no tax; no tax invoice | BR-2, SC-7 | AC-24 | [Confirmed] |
| Amounts always show two decimal places | BR-1 | AC-5 | [Confirmed] |
| Multi-currency out of scope | SC-6 | — | [Confirmed] |
| `products.stock` is the current physical quantity | FR-2.7, FR-3.8 | AC-24 | [Confirmed] |
| Stock reduced when an order is created (incl. COD) | BR-10 | AC-20 | [Confirmed] |
| A failed payment never reduces stock | BR-10 | AC-19 | [Confirmed] |
| Stock re-checked inside the checkout transaction | FR-4.11, BR-11 | AC-22 | [Confirmed] |
| Currency must not change once orders exist | BR-16, SC-6 | — | [Assumption] |
| Payment and fulfilment status are separate | BR-4 | AC-28 | [Confirmed] |
| Payment status ∈ {pending, success, failed}; no `refunded` | BR-3, FR-4.16 | AC-43 | [Confirmed] |
| Five permitted payment records | BR-8, §10.4 | AC-43 | [Confirmed] |
| App decides when records are written; the DB guarantees the shapes | BR-8, §10.4 | AC-43 | [Confirmed] |
| Failed card payments are immutable | BR-9 | AC-32 | [Confirmed] |
| Deactivation behaviour (see §2.8) | BR-14, FR-6.11 | AC-45 | [Confirmed] |
| Seed: 2 roles, 1 Admin, 1 test User, 5 categories, 25–30 products, images | FR-6.12 | AC-40 | [Confirmed] |
| Seed is development-only and refuses to run in production | FR-6.12, BR-17, SC-16 | AC-40 | [Confirmed] |
| Seed is idempotent | FR-6.12, BR-17 | AC-40 | [Confirmed] |
| Dev credentials documented in the README | FR-6.12 | AC-40 | [Confirmed] |

### 2.11 UI/UX Requirements (PRD §9)

| PRD requirement | SRS requirement | Acceptance criterion | Provenance |
|---|---|---|---|
| Green, nature-inspired palette; generous white space; rounded cards | §9.1 | — | [Confirmed] |
| Image-first cards; name beneath the image | FR-2.2 | AC-5 | [Confirmed] |
| Navigation bar — logo, categories, search, cart icon with count, account menu | §9.1, FR-3.1 | AC-9 | [Confirmed] |
| Responsive from 360 px upward | NFR-U-01 | — | [Confirmed] |
| Single-column grid on phones, multi-column above | NFR-U-01 | — | [Confirmed] |
| Toasts / inline messages for add, remove, success, error | FR-3.1, FR-3.5, NFR-U-03 | AC-9 | [Confirmed] |
| Add-to-Cart toast includes a "View Cart" action | FR-3.1 | AC-9 | [Confirmed] |
| Alt text on all images, keyboard-friendly forms, colour contrast | NFR-U-04 | — | [Confirmed] |
| Browse-to-checkout in five steps or fewer | NFR-U-02 | — | [Confirmed] |
| Nine distinct clear states | NFR-U-05, and the FRs for each | AC-6, AC-12, AC-13, AC-15, AC-19, AC-35 | [Confirmed] |

### 2.12 Non-Functional Expectations (PRD §10)

| PRD expectation | SRS requirement | Acceptance criterion | Provenance |
|---|---|---|---|
| Page load within 3 seconds | NFR-P-01 | — | [Confirmed] |
| 200 concurrent users | NFR-P-02 | — | [Confirmed] |
| Images compressed and lazy-loaded; listing uses thumbnails | NFR-P-03, FR-2.2 | — | [Confirmed] |
| Listing loads only the current page | NFR-P-04, FR-2.10 | AC-7 | [Confirmed] |
| HTTPS for all traffic | NFR-S-01 | — | [Confirmed] |
| Salted password hashing | NFR-S-02, FR-1.3 | AC-3 | [Confirmed] |
| DB-backed sessions; httpOnly, SameSite, environment-aware Secure | FR-7.6, NFR-S-10, SEC-10 | AC-38 | [Confirmed] |
| SQL injection, XSS, CSRF protection | NFR-S-03, NFR-S-04, SEC-1, SEC-2 | AC-39 | [Confirmed] |
| Upload validation on type and content, 2 MB limit | NFR-S-05, SEC-3, SEC-4 | AC-25 | [Confirmed] |
| Login rate limiting | NFR-S-06, SEC-13 | — | [Confirmed] |
| Strict SQL mode | NFR-S-07, R-SQL-1 … R-SQL-6 | AC-41 | [Confirmed] |
| Responsive 360 px → desktop | NFR-U-01 | — | [Confirmed] |
| Core flow in five steps or fewer | NFR-U-02 | — | [Confirmed] |
| Clear messages; basic accessibility | NFR-U-03, NFR-U-04 | — | [Confirmed] |
| 99% uptime target | NFR-R-01 | — | [Assumption] |
| Transactional checkout | NFR-R-02, FR-4.15 | AC-18 | [Confirmed] |
| Daily database backups | NFR-R-03 | — | [Confirmed] |
| Modular code with separate layers | NFR-M-01 | — | [Confirmed] |
| API documentation and automated tests | NFR-M-02 | — | [Confirmed] |
| Capability matrix defined in exactly one place | NFR-M-03, FR-7.1 | AC-37 | [Confirmed] |
| Runs on any major browser; Docker-deployable | NFR-PT-01 | — | [Confirmed] |

### 2.13 Success Metrics (PRD §11)

| Metric | Measured by | Acceptance criterion | Provenance |
|---|---|---|---|
| Registration completion rate ≥ 70% | FR-1.1, FR-1.2 | AC-1 | [Confirmed] |
| Cart-to-checkout conversion ≥ 40% | FR-3.1, FR-4.15 | AC-9, AC-18 | [Confirmed] |
| Landing → completed order under 3 minutes | FR-4.15, NFR-U-02 | AC-18 | [Confirmed] |
| Checkout error rate under 2% | FR-4.5 (persisted decline), FR-4.6 | AC-19 | [Assumption] |
| Admin time to add a product under 2 minutes | FR-6.2, FR-6.4 | AC-24 | [Confirmed] |
| Zero unauthorised access incidents | FR-7.1 … FR-7.5, FR-7.7 | AC-37, AC-39 | [Confirmed] |

### 2.14 Scope and Release (PRD §12)

| Feature | Priority | SRS requirement | Provenance |
|---|---|---|---|
| Registration, login, session management | P0 | FR-1.1 … FR-1.7, FR-1.10, FR-7.6 | [Confirmed] |
| Role-based access control (centralised) | P0 | FR-7.1 … FR-7.7 | [Confirmed] |
| Catalogue, categories, search, pagination, sorting | P0 | FR-2.1 … FR-2.11 | [Confirmed] |
| Product image upload with thumbnail generation | P0 | FR-2.12, FR-6.4 | [Confirmed] |
| Cart with hybrid Add-to-Cart | P0 | FR-3.1, FR-3.12 | [Confirmed] |
| Card (simulated) and COD checkout | P0 | FR-4.1 … FR-4.16 | [Confirmed] |
| Order Confirmation page | P0 | FR-4.13, FR-4.14 | [Confirmed] |
| Order fulfilment status management | P0 | FR-6.7, FR-6.8 | [Confirmed] |
| COD cash confirmation | P0 | FR-6.9 | [Confirmed] |
| Admin user list | P0 | FR-6.10 | [Confirmed] |
| Admin-initiated password reset | P0 | FR-6.10 | [Confirmed] |
| Profile name editing | P0 | FR-5.5 | [Confirmed] |
| Profile password changing | P0 | FR-5.6 | [Confirmed] |
| Account deactivation / reactivation | **P1** | FR-6.11 | [Confirmed] — deferred |
| Email self-service password reset | **Out of scope** | FR-1.8 (RETIRED) | [Confirmed] |

---

## 3. Page Inventory → Requirement Matrix

All 22 pages are mapped. No page lacks a requirement, and no 403/404 behaviour is left unaccounted for.

| Page | Purpose | SRS requirement(s) | Role(s) | HTTP on failure |
|---|---|---|---|---|
| **P-01** | Landing | FR-2.1, FR-2.4 | All | 404 for unknown routes |
| **P-02** | Register | FR-1.1, FR-1.2, FR-1.3, FR-1.4, ER-1 | Visitor | 422 validation |
| **P-03** | Login (resumes pending add) | FR-1.5, FR-1.7, FR-3.4, FR-7.6 | Visitor | 401 generic error |
| **P-04** | Forced Change Password | FR-1.9, FR-1.3, FR-1.10 | User / Admin | Blocks all other protected pages |
| **P-05** | Product Listing | FR-2.1 … FR-2.11 | All | 404 unknown URL; 401 if protected params need a session |
| **P-06** | Product Detail | FR-2.7, FR-2.12, FR-3.12, FR-2.8 | All | **404** for soft-deleted / unknown product |
| **P-07** | 404 Not Found | FR-2.8, FR-2.13, §15.1 | All | — |
| **P-08** | Cart | FR-3.2, FR-3.5 … FR-3.11, FR-7.5 | User only | **401** anonymous; **403** Admin |
| **P-09** | Payment / Checkout | FR-4.1 … FR-4.8 | User only | **401** anonymous; **403** Admin; 422 validation |
| **P-10** | Order Confirmation | FR-4.13, FR-4.14, FR-4.16 | User only | **401** anonymous; **403** Admin |
| **P-11** | User Dashboard | FR-5.1, FR-5.3, FR-5.4, FR-5.7, FR-7.5 | User only | **401** anonymous; **403** Admin |
| **P-12** | Customer Order Detail | FR-5.2, FR-5.7, RBAC-7 | User only | **401** anonymous; **403** Admin; **403** another customer order; 404 unknown order |
| **P-13** | Profile / Account Settings | FR-5.5, FR-5.6 | User only | **401** anonymous; **403** Admin |
| **P-14** | Admin Dashboard | FR-6.1 | Admin only | **401** anonymous; **403** User |
| **P-15** | Admin Product Management | FR-6.2 … FR-6.5, FR-2.12 | Admin only | **401** anonymous; **403** User; 422 validation |
| **P-16** | Admin Category Management | FR-6.6, FR-2.3 | Admin only | **401** anonymous; **403** User; 409 when the category still has products |
| **P-17** | Admin Orders List | FR-6.7, FR-4.16 | Admin only | **401** anonymous; **403** User |
| **P-18** | Admin Order Detail | FR-6.8, FR-6.9, FR-4.8 | Admin only | **401** anonymous; **403** User; 409 illegal transition |
| **P-19** | Admin User List | FR-6.10 | Admin only | **401** anonymous; **403** User |
| **P-20** | Admin User Detail | FR-6.10, FR-6.11, FR-1.9 | Admin only | **401** anonymous; **403** User; 404 unknown user |
| **P-21** | 403 Access Denied | FR-7.4, FR-7.5, §15.2 | All | — |
| **P-22** | Error (500) | §15.3, NFR-R-05 | All | — |

**Page-count check:** 7 Public + 3 Shopping + 3 User + 7 Admin + 2 System = **22**. ✔

---

## 4. Retired and Superseded v1.0 Requirements

| v1.0 FR | Original text (abridged) | Disposition | Replaced by | Rationale |
|---|---|---|---|---|
| **FR-1.8** | "The system should provide password reset by email (optional in v1.0)." | **RETIRED — Out of Scope** | FR-6.10 (Admin-initiated reset) | No email service is a v1.0 dependency. Admin-initiated reset solves the locked-out-customer problem without SMTP. |
| **FR-4.8** | "Real card details shall never be stored by the system." | **SUPERSEDED** | FR-4.9 | The original statement is preserved in intent but is strengthened with the retention rule (discard immediately after the simulated processor) and an explicit schema prohibition. |
| **FR-3.1** (v1.0) | "Clicking Add to Cart shall add the product to the logged-in user's cart and **navigate to the Cart page**." | **SUPERSEDED** | FR-3.1 (listing → background update) + FR-3.12 (detail page → navigate) | The hybrid behaviour: a background update from the listing, a navigation from the detail page. |
| **FR-6.7** (v1.0) | "Admins shall be able to view the user list and, optionally, deactivate an account." | **SPLIT** | FR-6.10 (P0) + FR-6.11 (P1) | "Optionally" hid a real scope decision. The user list and admin-initiated password reset are P0; deactivation is P1 with its behaviour fully specified now. |
| **FR-5.2** (v1.0) | "The user shall be able to view order details (items, totals, date)." | **EXTENDED** | FR-5.2, FR-5.7 | Extended with fulfilment status, payment method, payment status, delivery address, ownership enforcement (RBAC-7), and cancelled-order visibility. |
| **FR-6.4** (v1.0) | "If the product appears in past orders, it shall be soft-deleted (hidden)." | **REWORDED** | FR-6.5 | The v1.0 wording made soft deletion conditional on appearing in past orders. Soft delete is now **always** the removal behaviour, with the storefront exclusion, cart and checkout rules specified in full. |

---

## 5. New SRS Requirements Not Present in v1.0

These requirements were added because the grilling session found the v1.0 documents silent or contradictory on them.

| New requirement | Gap it closes | Section |
|---|---|---|
| FR-1.9 | No forced-password-change mechanism existed | SRS §7.1 |
| FR-1.10 | No session lifetime was defined | SRS §7.1 |
| FR-2.8 | Soft-delete storefront exclusion was undefined | SRS §7.2 |
| FR-2.9 | No empty-search-result state | SRS §7.2 |
| FR-2.10 | No pagination rule at all | SRS §7.2 |
| FR-2.11 | No sort order was defined | SRS §7.2 |
| FR-2.12 | Image requirements were entirely absent | SRS §7.2 |
| FR-2.13 | No 404 behaviour | SRS §7.2 |
| FR-3.1 | Add-to-Cart behaviour was a single flat rule | SRS §7.3 |
| FR-3.7 | Price-at-add vs. current-price was ambiguous | SRS §7.3 |
| FR-3.8 | Stock changes in the cart were undefined | SRS §7.3 |
| FR-3.11 | No rule gating Proceed to Payment | SRS §7.3 |
| FR-3.12 | No detail-page Add-to-Cart variant | SRS §7.3 |
| FR-4.5 | Failure behaviour had no data model | SRS §7.4 |
| FR-4.6 | "Always succeeds" contradicted the decline path | SRS §7.4 |
| FR-4.8 | No delivery address existed anywhere | SRS §7.4 |
| FR-4.11 | No stock re-validation at checkout | SRS §7.4 |
| FR-4.12 | No price re-validation at checkout | SRS §7.4 |
| FR-4.13 | The v1.0 flow redirected straight to the dashboard | SRS §7.4 |
| FR-4.14 | Orders had no human-readable number | SRS §7.4 |
| FR-4.15 | "Transactional" was asserted but not specified | SRS §7.4 |
| FR-4.16 | Payment status had three possible homes | SRS §7.4 |
| FR-5.7 | Cancelled-order visibility was undefined | SRS §7.5 |
| FR-6.4 | Image persistence had no atomicity rule | SRS §7.6 |
| FR-6.5 | Soft delete was conditional in v1.0 | SRS §7.6 |
| FR-6.9 | COD cash confirmation did not exist | SRS §7.6 |
| FR-6.10 | Password reset did not exist (FR-1.8 retired) | SRS §7.6 |
| FR-6.11 | Deactivation was "optional" and unspecified | SRS §7.6 |
| FR-6.12 | No seed or bootstrap existed | SRS §7.6 |
| FR-7.3 | Account status was checked only at login | SRS §7.7 |
| FR-7.5 | No rule prevented an Admin from shopping | SRS §7.7 |
| FR-7.6 | Sessions were "session or token" — undefined | SRS §7.7 |
| FR-7.7 | CSRF appeared only in the NFR section | SRS §7.7 |
| R-SQL-1 … R-SQL-10 | No database configuration requirement existed | SRS §6.4 |
| BR-1 … BR-17 | Business rules were scattered through the NFRs | SRS §12 |
| UC-1 … UC-14 | Five informal use cases only | SRS §13 |
| SEC-1 … SEC-21 | Security requirements were generic NFRs | SRS §15 |
| ER-1 … ER-23 | No error-handling or validation specification | SRS §16 |
| AC-1 … AC-45 | No testable acceptance criteria | SRS §17 |
| SC-1 … SC-16, SA-1 … SA-10 | Constraints and assumptions were informal | SRS §18 |

---

## 6. Database Entity → Requirement Matrix

Every entity and every column group supports documented functionality.

| Entity | Columns | SRS requirement(s) | Supports |
|---|---|---|---|
| `roles` | id, name | FR-1.4, FR-7.1, BR-1 (model) | Role-centred authorisation; data-only extensibility |
| `users` | id, full_name, email, password_hash, role_id, is_active, must_change_password, created_at | FR-1.1, FR-1.2, FR-1.3, FR-1.4, FR-1.5, FR-1.9, FR-5.5, FR-5.6, FR-6.10, FR-6.11, FR-7.3 | Registration, login, forced reset, profile, deactivation |
| `categories` | id, name, description | FR-2.3, FR-2.4, FR-6.6 | Category filter, product classification, reassignment |
| `products` | id, name, description, price, stock, image_path, thumbnail_path, category_id, is_deleted, created_at, updated_at | FR-2.1, FR-2.2, FR-2.7, FR-2.8, FR-2.11, FR-2.12, FR-3.7, FR-6.2, FR-6.3, FR-6.4, FR-6.5, BR-1, BR-10 | Listing, detail, search, sort, images, soft delete, price, stock |
| `cart_items` | id, user_id, product_id, quantity, price_at_add, added_at | FR-3.2 … FR-3.10, FR-3.3 | Cart persistence, quantity, duplicate prevention, price-change detection |
| `orders` | id, order_number, user_id, total_amount, status, delivery_*, created_at | FR-4.7, FR-4.8, FR-4.14, FR-5.2, FR-5.7, FR-6.7, FR-6.8, BR-5, BR-7 | Order creation, order number, address snapshot, fulfilment status, history |
| `order_items` | id, order_id, product_id, product_name_at_purchase, unit_price_at_purchase, quantity | FR-4.4, FR-5.2, BR-5, BR-6 | Immutable purchase-time snapshots, order detail |
| `payments` | id, order_id (nullable), method, status, paid_at, success_order_id (virtual) | FR-4.4, FR-4.5, FR-4.6, FR-4.7, FR-4.9, FR-4.16, FR-6.8, FR-6.9, BR-3, BR-8, BR-9, BR-15 | Payment status authority, decline capture, one-success-per-order |

**Integrity rules and the requirements they serve:**

| Rule | SRS requirement(s) |
|---|---|
| `CHECK (quantity >= 1)` on `cart_items` and `order_items` | FR-3.6, FR-4.4 |
| `CHECK chk_payment_order_binding` on `payments` | BR-8, FR-4.16 |
| `UNIQUE (user_id, product_id)` on `cart_items` | FR-3.3 |
| `UNIQUE uq_payments_success_order (success_order_id)` | FR-4.16, BR-8 |
| `UNIQUE` on `users.email` | FR-1.2 |
| `UNIQUE` on `categories.name` | FR-6.6 |
| `UNIQUE` on `orders.order_number` | FR-4.14 |
| `UNIQUE` on `roles.name` | FR-1.4 |
| `NOT NULL` on `products.category_id` | FR-2.3 |
| `NOT NULL` on `products.price` | BR-1 |
| `DECIMAL(10,2)` on every monetary column | BR-1 |
| `FK fk_payments_order` with `ON DELETE RESTRICT` | FR-4.16 |
| `FK` on `products.category_id` with `ON DELETE RESTRICT` | FR-2.3, FR-6.6 |
| `FK` on `order_items.product_id` with `ON DELETE RESTRICT` | FR-6.5, BR-5 |
| `FK` on `cart_items.product_id` with `ON DELETE RESTRICT` | FR-6.5 |
| `FK` on `users.role_id` with `ON DELETE RESTRICT` | FR-1.4 |
| `VIRTUAL` generated column `success_order_id` | FR-4.16, BR-8 |

---

## 7. Use Case → Requirement Matrix

| Use case | SRS requirement(s) | Acceptance criterion(s) |
|---|---|---|
| UC-1 — Register and Log In | FR-1.1 … FR-1.5, FR-1.7, FR-7.6, ER-1 | AC-1, AC-2, AC-3, AC-4 |
| UC-2 — Browse, Filter, Search, Paginate | FR-2.1, FR-2.4 … FR-2.6, FR-2.9 … FR-2.11, FR-2.13 | AC-5, AC-6, AC-7, AC-8 |
| UC-3 — Add to Cart (authenticated) | FR-3.1, FR-3.3, FR-3.12, FR-7.7 | AC-9, AC-10 |
| UC-4 — Add to Cart as a Visitor | FR-3.4, FR-7.2, FR-3.1 | AC-11 |
| UC-5 — Manage the Cart | FR-3.2, FR-3.5 … FR-3.11 | AC-12, AC-13, AC-14, AC-15 |
| UC-6 — Card checkout (success) | FR-4.1 … FR-4.6, FR-4.9 … FR-4.16 | AC-16, AC-17, AC-18, AC-22, AC-23 |
| UC-7 — Card checkout (decline) | FR-4.5, FR-4.6, FR-4.15 | AC-19 |
| UC-8 — COD checkout | FR-4.7, FR-4.8, FR-4.10, FR-4.14, FR-4.15 | AC-20, AC-21, AC-23 |
| UC-9 — Admin manages products and images | FR-6.2 … FR-6.5, FR-2.8, FR-2.12 | AC-24, AC-25, AC-26 |
| UC-10 — Admin manages categories | FR-6.6, FR-2.3 | AC-27 |
| UC-11 — Admin manages orders and fulfilment | FR-6.7, FR-6.8, FR-6.9, FR-5.7, BR-7, BR-9, BR-13, BR-15 | AC-28, AC-29, AC-30, AC-31, AC-32 |
| UC-12 — Admin resets a user's password | FR-6.10, FR-1.9, FR-1.10 | AC-33 |
| UC-13 — User reviews order history | FR-5.1 … FR-5.7 | AC-34, AC-35, AC-36 |
| UC-14 — Access control denial | FR-7.1 … FR-7.5, §15.2 | AC-37 |

---

## 8. Coverage Verification

### 8.1 PRD → SRS
| Check | Result |
|---|---|
| Every PRD feature group traced | ✔ 26 / 26 |
| Every PRD page-inventory entry traced | ✔ 22 / 22 |
| Every PRD business rule traced | ✔ §8.1–§8.5 → BR-1 … BR-17 |
| Every PRD NFR traced | ✔ §10.1–§10.6 → NFR-P/S/U/R/M/PT |
| Every PRD success metric traced | ✔ §11 → 6 / 6 |
| Every PRD priority item traced | ✔ §12.1 → 15 / 15 |
| Every PRD open question resolved or deferred | ✔ §15 → 5 closed, 5 deferred to §15.1 |

### 8.2 SRS → PRD
| Check | Result |
|---|---|
| Every active FR traces to a PRD feature | ✔ 59 / 59 |
| Every FR carries an ID, role, preconditions, behaviour, acceptance criteria | ✔ 59 / 59 |
| Every NFR traces to a PRD expectation | ✔ 33 / 33 |
| Every BR traces to a PRD business rule | ✔ 17 / 17 |
| Every UC traces to a PRD journey | ✔ 14 / 14 |
| Every acceptance criterion traces to at least one FR | ✔ 45 / 45 |
| Every DB entity supports documented functionality | ✔ 8 / 8 |
| Every page maps to a requirement or documented system behaviour | ✔ 22 / 22 |
| 403 behaviour accounted for | ✔ FR-7.4, FR-7.5, §15.2 |
| 404 behaviour accounted for | ✔ FR-2.8, FR-2.13, §15.1 |
| 401 behaviour accounted for | ✔ FR-7.2, RBAC-5 |
| 500 behaviour accounted for | ✔ §15.3, NFR-R-05 |

### 8.3 Roles
| Check | Result |
|---|---|
| Roles do not conflict between PRD and SRS | ✔ Both define exactly `User` and `Admin` |
| The capability matrix is identical in both | ✔ PRD §5.1 ↔ SRS §5.1 |
| Admin shopping prohibition is stated in both | ✔ PRD §5.2 ↔ SRS FR-7.5 |
| Every FR names one of Visitor / User / Admin / operator | ✔ |
| Deactivation marked consistently as P1 | ✔ PRD §8.4, §12.1 ↔ SRS FR-6.11, BR-14, AC-45 |

### 8.4 Journeys
| Check | Result |
|---|---|
| PRD §6.1 shopper journey matches the FRs | ✔ 9 steps → FR-1.5, FR-2.1, FR-2.4, FR-2.5, FR-3.1, FR-3.2, FR-3.7, FR-4.2, FR-4.7, FR-4.13, FR-5.1 |
| PRD §6.2 admin journey matches the FRs | ✔ 6 steps → FR-1.7, FR-6.2 … FR-6.10 |
| Journey count matches the UC count | ✔ 14 UCs covering both journeys plus edge cases |

### 8.5 Priorities
| Check | Result |
|---|---|
| P0 items preserved from v1.0 | ✔ All 37 v1.0 FRs remain, at the same or higher priority |
| P1 items preserved or clarified | ✔ Deactivation — behaviour fully specified (FR-6.11, BR-14), implementation deferred |
| Out-of-scope items preserved or clarified | ✔ Email reset retired (FR-1.8) and re-scoped (FR-6.10) |
| No priority changed without discussion | ✔ Every change is traceable to a grilling-session decision |

---

## 9. Document Consistency Statement

The PRD and the SRS describe **one system**, and this matrix demonstrates it:

1. **Same features.** Every one of the 26 PRD feature groups maps to at least one SRS functional requirement, and every one of the 59 active SRS functional requirements traces back to a PRD feature. There is no orphan requirement in either direction.
2. **Consistent IDs.** Requirement IDs are unique within the SRS, stable across versions, and referenced consistently from the PRD's Appendix A, the SRS's own appendices, and this matrix.
3. **Consistent roles.** The two roles, their names, and the capability matrix are identical in both documents. The Admin prohibition is expressed in both and enforced in the SRS by FR-7.5 and RBAC-4.
4. **Consistent journeys.** Both shopper and admin journeys map step-for-step to requirements, and the same behaviour is exercised by the use cases.
5. **Supporting database.** All eight entities carry only the columns that documented functionality requires; the 16-row schema delta explains every change from v1.0.
6. **Testable acceptance criteria.** Every FR has acceptance criteria; the 45 feature-level criteria in SRS §17 aggregate them into verifiable scenarios, and §17.2 defines the exit criteria for v1.0.
7. **P0 / P1 / P2 preserved.** No priority changed silently. The two priority movements — password reset (P0, re-scoped) and deactivation (P1, deferred) — were both explicitly decided and are recorded in PRD §12.1 and §8.4.

---

## Appendix A — Document History

| Version | Date | Change |
|---|---|---|
| 1.0 | — | Did not exist. |
| 2.0 | November 1, 2026 | Created alongside PRD v2.0 and SRS v2.0 following the requirements-grilling session. Contains the PRD-feature mapping (26 groups), the page-inventory mapping (22 pages), the retired/superseded register (6 v1.0 FRs), the new-requirements register (47 additions), the database-entity mapping (8 entities), the use-case mapping (14 UCs), and the coverage-verification checklist. |

## Appendix B — Related Documents

| Document | Purpose |
|---|---|
| `GreenCorner_PRD.md` (v2.0) | Product-level requirements: vision, goals, personas, roles, journeys, features, business rules, UI/UX, NFR expectations, metrics, scope, risks, dependencies, open questions, roadmap. |
| `GreenCorner_SRS.md` (v2.0) | Technical specification: 18 sections including the database design, the MySQL configuration rules, use cases, RBAC, security, error handling, acceptance criteria and constraints. |
