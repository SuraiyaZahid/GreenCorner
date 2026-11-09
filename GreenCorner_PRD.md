# GreenCorner – Product Requirements Document (PRD)

**Version:** 2.0  |  **Status:** Approved (Grilling Session Complete)  |  **Date:** November 1, 2026
**Supersedes:** GreenCorner PRD v1.0 (October 31, 2026)
**Companion documents:** `GreenCorner_SRS.md` (v2.0), `GreenCorner_Requirement_Traceability_Matrix.md`

> **How to read this document.** Every requirement in this PRD was resolved through a structured requirements-grilling session. Statements are labelled:
> - **[Confirmed]** — explicitly decided and approved during the session.
> - **[Assumption]** — a reasonable default adopted to close a gap; flagged for review.
> - **[Suggestion]** — an optional improvement, explicitly *not* in v1.0 scope.
>
> The PRD remains at product level. Technical detail lives in the SRS.

---

## 1. Product Overview

**GreenCorner** is an online shopping store for plants, seeds, pots, tools and fertilisers. It has a fresh, image-led design: shoppers discover products by category, add them to a cart, choose a payment method, check out, and track purchases from a personal dashboard. Store admins manage the catalogue themselves through a simple admin panel.

GreenCorner is a **standalone three-tier web application**: a browser front end, a back-end application tier, and a relational database organised around a `roles` table that drives authorisation.

**[Confirmed]** The application is built as a **plain PHP MVC** application using **MySQLi** for database access and **MySQL 8.0.16+** as the database engine. The front end is server-rendered HTML/CSS with targeted JavaScript and a small set of JSON/AJAX endpoints for background cart operations. It is **not** an SPA and uses no JavaScript UI framework.

> **Note:** The project directory contained no pre-existing PHP implementation at the time of this document. The architecture described here is the **planned implementation target**, not a description of an existing codebase.

---

## 2. Vision and Problem Statement

### 2.1 Vision
Make buying from a small, curated plant store feel as simple and pleasant as walking through a green, well-organised shop: **see it, pick it, pay, done.**

### 2.2 Problem Statement
Small sellers often rely on social media or chat to sell. That means no structured catalogue, manual order tracking, and no customer accounts. Shoppers on those channels cannot browse by category, review a cart, or see their order history. GreenCorner gives both sides one place: a structured catalogue and order management for the owner, and browse–cart–checkout–history for the shopper.

### 2.3 Target Market
**[Confirmed]** The initial target market is **Bangladesh**. The store currency is **BDT (৳)**.

---

## 3. Goals and Non-Goals

### 3.1 Goals (v1.0)
| # | Goal |
|---|---|
| G-1 | Let a new shopper go from landing page to completed order in under three minutes |
| G-2 | Give admins full control of products and categories without developer help |
| G-3 | Keep customer and admin capabilities strictly separated through role-based access control |
| G-4 | Deliver a clean, image-first experience that works well on phones |
| G-5 | Support both card and Cash on Delivery, with a payment design that allows a real gateway later |

### 3.2 Non-Goals (v1.0) — explicitly excluded
- Real payment gateway integration
- Discount codes and coupons
- Product reviews and ratings
- Wishlists
- Shipping-rate calculation
- Multi-vendor marketplace
- Native mobile apps
- **Tax calculation and tax display** — v1.0 has no tax line item and produces **no formal tax invoice**
- **Email self-service password reset** — no email/SMTP dependency in v1.0
- **Guest checkout** — registration/login is required before shopping
- **Customer-initiated order cancellation** — only Admins may cancel
- **Fuzzy / typo-tolerant search**
- **A dynamic permissions-management system** — role capabilities are fixed in the application layer for v1.0

---

## 4. Target Users and Personas

### Persona A — Rina, the Shopper (User role)
- 27, shops mostly on her phone, values clear photos and quick checkout.
- **Needs:** browse by category, see product images and prices, manage a cart, pay easily, see past orders.
- **Frustrations:** cluttered pages, forced steps, unclear totals.

### Persona B — Karim, the Store Owner (Admin role)
- 38, runs the store, not technical.
- **Needs:** add new products with photos, change prices and stock, remove discontinued items, see incoming orders, manage order fulfilment, confirm cash collection.
- **Frustrations:** editing data in spreadsheets, customers ordering items that are sold out.

### Persona C — Visitor (unauthenticated)
- Arrives from a social-media link or search. Browses, then registers to buy.

---

## 5. User Roles and Permissions

**[Confirmed]** GreenCorner has exactly **two roles** — `User` and `Admin` — each user holding exactly one role. Permissions are defined in the application layer through a single central authorisation mechanism and enforced **server-side on every protected request**. Hiding or disabling buttons in the interface is never sufficient.

### 5.1 Capability Matrix

| Capability | Visitor | User | Admin |
|---|---|---|---|
| Browse products and categories | Yes | Yes | Yes |
| Register / Log in | Yes | n/a | n/a |
| View product detail | Yes | Yes | Yes |
| Add to cart / remove from cart | No | **Yes** | **No** |
| Proceed to payment / checkout | No | **Yes** | **No** |
| User Dashboard and order history | No | **Yes** | **No** |
| Add / update / remove products | No | No | Yes |
| Manage categories | No | No | Yes |
| View all orders and users | No | No | Yes |
| Update order fulfilment status | No | No | Yes |
| Confirm COD cash collection | No | No | Yes |
| Reset a user's password | No | No | Yes |
| Deactivate / reactivate an account | No | No | **P1 — deferred** |

### 5.2 Admin Is Strictly a Management Role
**[Confirmed]** An Admin **cannot shop**. An Admin may browse the catalogue and view the storefront, but cannot add to cart, reach checkout or payment, or access the User Dashboard or customer order history. Only registered Users can use shopping features.

**Why this matters product-wise:** it keeps the two lifecycles clean. The Admin manages the catalogue and fulfils orders; the User shops. A separate test User account is provided for exercising the shopping and checkout workflow.

### 5.3 The `roles` Table
**[Confirmed]** The database is **role-centred**: every account references a role, and permissions flow from it. The `roles` table (`id`, `name`) is retained exactly as specified and is seeded with `User` and `Admin`. Additional roles may be inserted later as **data only, without a schema migration**.

**[Confirmed]** No separate permissions table, permission flags, or dynamic permission management is required in v1.0. The permission model remains fixed per role in the application layer.

### 5.4 Centralised Authorisation
**[Confirmed]** One central helper or middleware maps roles to permitted actions. **All** protected server-side operations route through it, so the capability matrix is defined in one place rather than scattered across controllers.

---

## 6. User Journeys

### 6.1 Shopper Journey (Core Flow)
1. **Arrive** — Land on the Landing page (store name, short introduction, category navigation strip, Browse Products call to action).
2. **Register / Log in** — A Visitor creates an account (always assigned the `User` role) and logs in. Registration does **not** auto-login; the customer logs in separately.
3. **Browse** — Product listing shows image-led cards (thumbnail, name, price, Add to Cart). Filter by category; search by name. Newest products appear first, 12 per page.
4. **Add to Cart** — From the **listing**, the item is added in the background: the cart count updates without a page reload and a success toast with a "View Cart" action appears. From the **Product Detail page**, Add to Cart adds the item and navigates to the Cart.
5. **Review Cart** — Adjust quantities, remove items, see line totals and the grand total. Price changes and stock reductions are surfaced as notifications.
6. **Payment** — Proceed to Payment opens the Payment page with an order summary, a delivery address form, and a payment form. **Card** (default) or **Cash on Delivery**, chosen via radio buttons; card fields appear or hide immediately.
7. **Checkout** — Checkout submits the order. The system re-validates prices and stock, then creates the order.
8. **Confirmation** — An **Order Confirmation** page shows the order number, itemised summary, total, payment method and payment status, with a link to the dashboard.
9. **Dashboard** — The User Dashboard greets the customer by name and lists recent orders with status.

**Target: landing page to completed order in under three minutes for a returning user** (measured in §11).

### 6.2 Admin Journey
1. Log in → redirected to the **Admin Dashboard** (counts of products, orders and users).
2. **Manage Products** — add (image mandatory), update any field including the image, or remove (always a soft delete).
3. **Manage Categories** — add, rename, or remove. A category with products cannot be removed until every product is reassigned to another existing category.
4. **Review Orders** — list all orders with fulfilment and payment status; open an order to see items, totals, delivery address and the customer; update fulfilment status; confirm COD cash collection.
5. **Manage Users** — list registered users (name, email, registration date); open a user to initiate a password reset.
6. **Deactivate a user** — *P1, deferred to a later release* (behaviour specified in §8.4).

---

## 7. Functional Features and Requirements

Priority key: **P0** = must have for launch · **P1** = should have, deferred · **P2** = nice to have.

### 7.1 Authentication and Registration — P0
- Register with full name, email and password. Email must be unique; duplicate registration is rejected with a clear message.
- Passwords are at least 8 characters and stored only as salted hashes.
- **Every new registration is assigned the `User` role.** Admin accounts are created only by the seed script or by an existing Admin. The first registered User is **never** promoted to Admin.
- Log in with email and password; log out invalidates the session.
- After login, Users land on the User Dashboard and Admins on the Admin Dashboard.
- **Sessions are server-side and database-backed.** The browser holds only a session identifier in an httpOnly cookie. No JWT, no browser-storage tokens, no "Remember Me".
- Session lifetime: **8 hours of inactivity**, with a **7-day absolute** maximum. Cart contents survive session expiry because the cart lives in the database.
- **Admin-initiated password reset** is in v1.0. An Admin sets a temporary password; the User must change it at next login. An Admin can never view an existing password.
- *Email self-service password reset is out of scope — no email service is required.*

**Acceptance:** duplicate email rejected; a wrong password shows an error without revealing which field was wrong; each role lands on the correct dashboard; a temporary password forces a change at next login.

### 7.2 Product Catalogue — P0
- The listing page shows product cards with **thumbnail image, name, price and an Add to Cart button**.
- Products are presented in an **image-led theme**: the image leads the card, with the name displayed beneath it.
- Every product belongs to exactly one category (e.g. Plants, Seeds, Pots, Tools, Fertilisers).
- **Category filter** — filtering shows only that category's products.
- **Search by name** — case-insensitive substring match on the product name. Multi-word queries match when **all** words appear in the name. Search is combined with the category filter using **AND** logic and does not clear the selected category. Leading/trailing whitespace in the search input is ignored. A no-match result shows a clear "No products found" message with a Clear Search action.
- **Pagination** — classic Previous / page numbers / Next controls, **12 products per page on all screen sizes**. The current page is in the URL. Search term and category are preserved across pages; changing the search term or category resets to page 1; an invalid page falls back to a valid page. Only the current page of products is loaded.
- **Sorting** — newest first (`created_at` descending). No featured flag and no alternative sort options in v1.0.
- The detail page shows the **original image**, description, price, stock status and category.
- Out-of-stock products are visibly marked and their Add to Cart button is disabled.
- **Soft-deleted products are excluded from listings and search results.** Opening a soft-deleted product's URL returns a friendly "Product no longer available" message with HTTP status 404 and a link to browse other products.

### 7.3 Cart — P0
- **Add to Cart from the listing** adds the product in the background, updates the cart count **without a page reload**, and shows a success toast with a visible "View Cart" action. **Add to Cart from the Product Detail page** adds the product and navigates to the Cart page.
- Duplicate submissions are prevented: a product already in the cart has its quantity incremented, never duplicated as a second row.
- **Visitors** who click Add to Cart are redirected to login. The pending product is held in the server session, validated for stock after login, and added on success; it is cleared afterwards so it cannot re-execute. If the product is unavailable or out of stock, a message is shown instead.
- The Cart page lists each item with **image, name, unit price, quantity and line total**, plus a grand total.
- The user can remove any item and change quantities. Quantity is reduced automatically if available stock falls below it, with a notification.
- The cart **persists between sessions** — it is stored in the database and survives logout, login and session expiry.
- An empty cart shows a message and a link back to the product listing.
- The Cart page provides a **Proceed to Payment** button.

### 7.4 Pricing and Stock Rules — P0
- The cart always displays the **current** product price. The price at the moment of adding is retained **only to detect a change**; when the price differs, the customer sees a clear notification showing the old and new prices, and the cart total is computed from the current price.
- Prices and stock are **re-validated at checkout**, and the customer sees the updated total before confirming.
- **Stock** represents the current physical quantity available for sale. If available stock falls below the cart quantity, the quantity is automatically reduced to what is available and the customer is notified. If stock reaches zero the item is marked unavailable and **checkout is blocked** until the customer removes it.
- The price at purchase is recorded on the order line as an immutable snapshot, so historical order totals never change.

### 7.5 Payment and Checkout — P0
- The Payment page shows an **order summary**, a **delivery address form**, and a payment form.
- **Payment method** is chosen via clearly visible radio buttons: **Card** (selected by default) or **Cash on Delivery**. They are mutually exclusive. Card fields are shown when Card is selected and hidden immediately when COD is selected. Both methods are available for every product.
- Card fields are validated **client-side and server-side**.
- **Simulated card processing supports both successful and declined transactions.** There is no real gateway.
  - **Card — success:** the order and its items are created, stock is reduced, the cart is cleared, and a successful payment is recorded.
  - **Card — decline:** a failed payment is recorded with **no order**, stock is **unchanged**, and the cart is **unchanged**. The customer sees an error.
  - **Validation failure:** validation errors are shown inline and **no payment record is created at all**. The cart is unchanged.
- **Cash on Delivery:** the order is created immediately with the fulfilment status `placed` and payment status `pending`. Stock is reduced and the cart is cleared. The customer is not charged at checkout.
- A **delivery address is collected at checkout and stored on the order as an immutable snapshot** of the address used at purchase time. No courier API and no automatic delivery tracking in v1.0.
- On successful checkout the customer sees the **Order Confirmation page**.

### 7.6 Order Fulfilment — P0
- **Fulfilment status** takes one of: `placed`, `processing`, `shipped`, `delivered`, `cancelled`.
- The system sets `placed` automatically. **Only an Admin** changes fulfilment status; customers cannot change it and cannot cancel their own orders.
- `cancelled` is **terminal** — it cannot be undone. Backward status changes are permitted for corrections. All status changes are validated.
- **Cancelling an order** restores stock **only** where the product still exists and is not soft-deleted; otherwise the case is logged for manual handling. Cancelling does **not** restore items to the customer's cart.
- A cancelled COD order's payment moves from `pending` to `failed`.
- **Admin can confirm COD cash collection**, moving a COD payment from `pending` to `success`.
- A **failed card payment is immutable** — no Admin action can turn it into a success.

### 7.7 User Dashboard — P0
- Greets the user by name and shows recent orders with status.
- The user can view **full order details** (items, quantities, unit prices, totals, date, status).
- **Cancelled orders remain visible** in order history and the recent-orders list, with a clear cancelled status and the explanation *"This order was cancelled by the store. No payment is required."* All order items and purchase-time product information are preserved.
- Quick links to browse products and open the cart.
- **Profile editing:** the user can change their own name, and change their password after providing their current password. A user can never change their own role.

### 7.8 Admin Panel — P0
- **Dashboard** with counts of products, orders and users.
- **Product management:** add, update any field including the image, and remove.
  - **Image upload is mandatory when creating a product.** On update, the existing image may be retained — a new upload is not required for every edit.
  - Accepted formats: **JPG, PNG, WebP**; maximum **2 MB**; validated server-side.
  - Both the **original and a generated thumbnail** are retained. Listing cards use the thumbnail; the detail page uses the original.
  - If thumbnail generation or the database save fails, the database operation is rolled back **and any already-written files are deleted** — the product is never left incomplete. Previous image files are removed only after new ones are successfully stored.
- **Product removal is always a soft delete.** Products are never hard-deleted, so order history and existing cart references stay intact. A soft-deleted product is excluded from listings and search and cannot be purchased. If it is already in a customer's cart, it stays visible with a **"No longer available"** message and **checkout is blocked** until the customer removes it — it is never removed automatically.
- **Category management:** add, rename, remove. A category with products cannot be removed until every product is reassigned to another existing category. There is no reserved "Uncategorised" category.
- **Order management:** view all orders and their details, update fulfilment status, and confirm COD cash collection. The order list shows payment status distinctly (COD pending vs. card paid).
- **User management:** view the registered user list (name, email, registration date) and initiate a password reset for a user.

### 7.9 Role-Based Access Control — P0
- Every protected request is checked **server-side** against the authenticated user's role through the central authorisation mechanism.
- A User opening an Admin page or calling an Admin endpoint receives **HTTP 403**.
- An Admin opening a shopping feature (cart, payment, checkout, user dashboard) likewise receives **HTTP 403**.
- Unauthenticated access to protected pages redirects to login (**HTTP 401**).

---

## 8. Business Rules

### 8.1 Money and Currency
- A single currency applies to the whole store, set by configuration. Initial value: **BDT (৳)**.
- The currency is displayed consistently on listings, product details, cart, checkout and order history.
- All monetary values are stored as **`DECIMAL(10,2)`**. Floating-point types are prohibited.
- The Admin-entered product price **is** the final amount the customer pays. No tax is added at checkout.
- Displayed amounts always show two decimal places.
- Multi-currency is out of scope for v1.0.

### 8.2 Stock
- `products.stock` is the current physical quantity available for sale.
- Stock is reduced when an order is successfully created — including COD orders, where payment is still pending.
- A failed payment never reduces stock.
- Stock is re-checked inside the checkout transaction to prevent overselling.
- **Known constraint:** the configured currency must not be changed once orders exist, because order totals are stored without a per-row currency code. This is a deliberate, documented limitation.

### 8.3 Order and Payment Status Rules
- **Payment status** and **fulfilment status** are separate concerns and are never conflated.
- Payment status values: `pending`, `success`, `failed`. **No `refunded` value** — refunds are out of scope.
- The permitted payment records are exactly:

| Method | Status | Order link |
|---|---|---|
| `card` | `success` | Must reference an order |
| `card` | `failed` | No order (a declined attempt) |
| `cod` | `pending` | Must reference an order |
| `cod` | `success` | Must reference an order |
| `cod` | `failed` | Must reference an order (cancelled before cash collection) |

- The application decides **when** each record is written; the database guarantees no other combination can be stored.
- Failed card payments are immutable.

### 8.4 Account Deactivation (P1 — behaviour specified now, implementation deferred)
- An Admin can deactivate and reactivate a User.
- A deactivated User **cannot log in**.
- Existing authenticated sessions become invalid on the **next** authenticated request.
- A deactivated User cannot access their dashboard or order history.
- **Existing orders and order history are retained** in the database.
- Reactivating an account restores normal access.

### 8.5 Seed and Bootstrap
- A seed script creates the initial Admin account, a separate test User account, both roles, five sample categories, and approximately 25–30 sample products with local placeholder plant images.
- The seed is **development-only** and **must refuse to run against a production database**.
- The seed is **idempotent** — safe to run repeatedly without creating duplicates.
- Development credentials are documented in the README. The default Admin password must be changed before real use.

---

## 9. UI/UX Requirements

- **Theme:** green, nature-inspired palette with generous white space; rounded cards.
- **Image-first:** the product image leads every listing card, with the product name clearly beneath it.
- **Navigation bar:** logo, categories, search, cart icon with an item count, and a login/account menu.
- **Mobile-first responsive layout** from 360 px mobile width up to desktop. Product grids are single-column on phones and multi-column on larger screens.
- **Feedback:** toasts or inline messages for add, remove, success and error. The Add-to-Cart toast includes a "View Cart" action.
- **Accessibility:** alt text on all images, keyboard-friendly forms, sufficient colour contrast.
- **Core flow (browse to checkout) achievable in five steps or fewer.**
- **Clear, specific states** are required for: no search results, empty cart, empty order history, out-of-stock product, price-changed item, unavailable (soft-deleted or zero-stock) cart item, declined payment, validation errors, and cancelled order.

### 9.1 Complete Page Inventory — 22 pages

**Public**
| # | Page | Purpose |
|---|---|---|
| P-01 | Landing | Store name, short introduction, category strip, Browse Products CTA |
| P-02 | Register | Create an account |
| P-03 | Login | Sign in; resumes a pending Add-to-Cart action |
| P-04 | Forced Change Password | Shown when a temporary password must be changed; blocks normal navigation |
| P-05 | Product Listing | Catalogue with search, category filter, pagination, Add to Cart |
| P-06 | Product Detail | Full image, description, price, stock, category, Add to Cart |
| P-07 | 404 Not Found | Unknown URL; also the "Product no longer available" response |

**Shopping**
| # | Page | Purpose |
|---|---|---|
| P-08 | Cart | Line items, totals, quantity changes, removal, price/stock notifications |
| P-09 | Payment / Checkout | Order summary, delivery address, Card/COD selection, Checkout |
| P-10 | Order Confirmation | Order number, itemised summary, total, method, payment status, link to dashboard |

**User**
| # | Page | Purpose |
|---|---|---|
| P-11 | User Dashboard | Greeting, recent orders with status, quick links |
| P-12 | Customer Order Detail | Items, totals, date, status — including cancelled orders |
| P-13 | Profile / Account Settings | Edit name; change password with current password |

**Admin**
| # | Page | Purpose |
|---|---|---|
| P-14 | Admin Dashboard | Counts of products, orders, users |
| P-15 | Admin Product Management | Add, update, remove products; image upload |
| P-16 | Admin Category Management | Add, rename, remove categories; product reassignment |
| P-17 | Admin Orders List | All orders, fulfilment status, payment status |
| P-18 | Admin Order Detail | Items, totals, delivery address, status transitions, COD confirmation |
| P-19 | Admin User List | Name, email, registration date |
| P-20 | Admin User Detail | Password reset initiation; deactivation/reactivation controls |

**System**
| # | Page | Purpose |
|---|---|---|
| P-21 | 403 Access Denied | Role-based denial, in either direction |
| P-22 | Error (500) | Unexpected server error with a safe generic message |

---

## 10. Non-Functional Expectations

### 10.1 Performance
- Pages load within **3 seconds** on a typical broadband connection.
- Support at least **200 concurrent users** in v1.0.
- Product images are compressed and **lazy-loaded**; listing pages use thumbnails.
- Product listing queries load only the current page of results.

### 10.2 Security
- HTTPS for all traffic.
- Passwords hashed with a salted algorithm; the algorithm choice is an implementation detail.
- Sessions are server-side and database-backed, with an httpOnly, SameSite cookie. The `Secure` flag is enabled under HTTPS and disabled only for local HTTP development.
- Protection against SQL injection (parameterised queries), XSS (output encoding) and CSRF (tokens on state-changing requests).
- Image uploads limited to JPG, PNG and WebP with a **2 MB** maximum, validated server-side on both file type and actual image content.
- Login attempts are rate-limited.
- Database configuration runs with strict SQL mode so invalid data is rejected rather than silently coerced.

### 10.3 Usability
- Responsive layout from 360 px mobile width up to desktop.
- Core flow (browse to checkout) achievable in five steps or fewer.
- Clear error and success messages; basic accessibility (alt text, keyboard navigation, sufficient colour contrast).

### 10.4 Reliability and Availability
- Target uptime of **99%** for v1.0.
- Checkout is **transactional**: order creation, stock reduction and cart clearing succeed together or not at all.
- Daily database backups.

### 10.5 Maintainability
- Modular code with separate layers (routing/controllers, services, data access).
- API documentation and automated tests for critical flows.
- The authorisation capability matrix is defined in exactly one place.

### 10.6 Portability
- Runs on any major browser and can be deployed with Docker.

---

## 11. Success Metrics

| Metric | Target (first 3 months) |
|---|---|
| Registration completion rate | 70% or higher of started sign-ups |
| Cart-to-checkout conversion | 40% or higher |
| Time from landing to completed order | Under 3 minutes for a returning user |
| Checkout error rate | Under 2% |
| Admin time to add a product | Under 2 minutes |
| Unauthorised access incidents | Zero |

**[Assumption]** "Checkout error rate" is measured as failed payment attempts divided by total payment attempts. This is only measurable because declined attempts persist a payment record.

---

## 12. Project Scope and Release Plan

### 12.1 Feature Priorities

| Feature | Priority |
|---|---|
| Registration, login, session management | P0 |
| Role-based access control (centralised) | P0 |
| Catalogue, categories, search, pagination, sorting | P0 |
| Product image upload with thumbnail generation | P0 |
| Cart with hybrid Add-to-Cart | P0 |
| Card (simulated) and COD checkout | P0 |
| Order Confirmation page | P0 |
| Order fulfilment status management | P0 |
| COD cash confirmation | P0 |
| Admin user list | P0 |
| Admin-initiated password reset | P0 |
| Profile name editing | P0 |
| Profile password changing | P0 |
| Account deactivation / reactivation | **P1** |
| Email self-service password reset | **Out of scope** |

### 12.2 Release Phases

**[Assumption]** Phase durations are indicative and assume a single developer working full-time. They are estimates, not commitments, and should be re-baselined once the implementation plan exists.

| Phase | Scope | Indicative Duration |
|---|---|---|
| Phase 0 — Setup | Requirements sign-off, database design, project setup, **seed script**, UI wireframes | 1 week |
| Phase 1 — Foundation | Registration, login, sessions, roles, centralised RBAC | 1–2 weeks |
| Phase 2 — Catalogue | Categories, products, listing, detail, search, pagination, image upload | 2 weeks |
| Phase 3 — Admin Panel | Admin dashboard, product CRUD, category management, image pipeline | 1–2 weeks |
| Phase 4 — Shopping | Cart, payment page, checkout, orders, user dashboard, order confirmation | 2–3 weeks |
| Phase 5 — Order Fulfilment | Fulfilment status management, COD confirmation, user list, password reset | 1–2 weeks |
| Phase 6 — Quality and Launch | Testing, security review, performance tuning, deployment | 1–2 weeks |

> **Sequencing note:** the Admin product management phase precedes the Shopping phase. The catalogue must be populated before cart and checkout can be meaningfully developed or demonstrated.

---

## 13. Risks and Assumptions

### 13.1 Risks
| Risk | Impact | Mitigation |
|---|---|---|
| Users bypass the UI to reach admin features | High | Server-side role checks on every protected request via one central authorisation mechanism |
| Two shoppers buy the last item simultaneously | Medium | Re-check stock inside a database transaction at checkout, with row locking |
| Large product images slow the site | Medium | Thumbnail generation on upload, lazy-loading, 2 MB limit, listing uses thumbnails |
| Sensitive payment data mishandled | High | Never store card data; simulated processor only; real gateway required before going live |
| Scope growth delays launch | Medium | Hold to P0; defer P1 and out-of-scope items; no speculative permissions system |
| Cart pricing drifts from the price shown at checkout | Medium | Cart always displays the current price; changes are surfaced and re-validated at checkout |
| COD orders never settle | Medium | COD payment status is visible in the Admin order list; Admin confirms cash collection |
| Store owner cannot recover a locked-out customer | Low | Admin-initiated password reset — no email service required |
| Account deactivation ships late | Low | Behaviour fully specified now; implementation deferred to P1 without affecting P0 flows |

### 13.2 Assumptions
- **[Confirmed]** Product photos and descriptions are supplied by the store owner.
- **[Confirmed]** A hosting environment and database are available.
- **[Assumption]** The store fulfils orders manually; no courier integration is required in v1.0.
- **[Assumption]** The configured currency remains unchanged once orders exist.
- **[Assumption]** The Admin is the only staff account in v1.0; no third role is planned for the next 6–12 months.
- **[Assumption]** A single developer delivers v1.0; the phase durations in §12.2 reflect that.
- **[Assumption]** Storefront images for the initial catalogue are realistic local placeholders, replaced later with real photography.
- **[Confirmed]** No email service is required for v1.0.

---

## 14. Dependencies

| Dependency | Needed for | Status |
|---|---|---|
| PHP runtime (with MySQLi extension) | Application runtime | Required |
| MySQL 8.0.16+ | Data store, CHECK constraint enforcement | Required |
| Web server (Apache/Nginx) | Hosting | Required |
| HTTPS certificate | Transport security | Required for production |
| Docker | Portability and deployment | Required for production packaging |
| Product photos | Catalogue realism | Supplied by the store owner; placeholders provided |
| Email/SMTP provider | — | **Not required** — out of scope for v1.0 |
| Payment gateway account | — | **Not required** — out of scope for v1.0 |

---

## 15. Open Questions

**[Confirmed] All Open Questions from PRD v1.0 are now closed:**

| Original question | Resolution |
|---|---|
| Should Admins also be able to shop? | **No.** Admin is strictly a management role (§5.2) |
| Should checkout support COD in addition to card? | **Yes** — both, mutually exclusive, Card default (§7.5) |
| Is guest checkout needed? | **No** — out of scope for v1.0 (§3.2) |
| What currency and tax rules apply? | **BDT (৳)**, single configurable currency; **no tax** in v1.0 (§8.1) |
| Which technology stack will be used? | **Plain PHP MVC + MySQLi + MySQL 8.0.16+**, server-rendered with targeted JS (§1) |

### 15.1 Remaining Open Questions for Later Versions
These are **not** blockers for v1.0 and are recorded for future planning:
- When and how to introduce tax, and whether to issue formal tax invoices.
- Whether a real payment gateway replaces the simulator, and which provider.
- Whether a third role (for example Store Manager or Courier) is needed, and whether a permissions system is warranted.
- Whether COD availability should ever be restricted per product or per customer.
- Whether email self-service password reset is worth the SMTP dependency.

---

## 16. Future Roadmap

1. **Real payment gateway** — replace the simulator behind the same payment interface.
2. **Tax and formal invoicing** — tax rates, tax-inclusive pricing, invoice generation.
3. **Email self-service password reset** — token lifecycle and an email service.
4. **Coupons, promotions and discounts** — with a rules engine.
5. **Product reviews and ratings**.
6. **Wishlist**.
7. **Order tracking and email notifications** — shipment events and customer alerts.
8. **Sales analytics for admins**.
9. **Additional roles and a permissions system** — if the team grows beyond a single Admin.
10. **Mobile app**.

### 16.1 Suggestions — explicitly not in v1.0
- **[Suggestion]** A "featured" flag to let the Admin pin seasonal products to the top of the listing. Not included; the default sort is newest-first.
- **[Suggestion]** A "Recently viewed" section. Not included.
- **[Suggestion]** Multiple product images per product, rather than a single original plus thumbnail. Not included.

---

## Appendix A — Requirement Traceability

Every feature in this PRD maps to functional requirements in `GreenCorner_SRS.md` and to acceptance criteria. The complete mapping — including the 22-page inventory and the retired/replaced v1.0 requirements — is maintained in:

**`GreenCorner_Requirement_Traceability_Matrix.md`**

## Appendix B — Document History

| Version | Date | Change |
|---|---|---|
| 1.0 | October 31, 2026 | Initial draft |
| 2.0 | November 1, 2026 | Rewritten following a structured requirements-grilling session. 16 decisions and 19 corrections applied. Admin shopping, payment methods, order statuses, currency and tax, cart pricing, search and pagination, seed/bootstrap, P1 scope, the roles table, authorisation style, session management, frontend architecture, database, deletion behaviour, cancellation and order history, page inventory, image requirements, and the MySQL constraint/configuration specification were all resolved. FR-1.8 (email reset) and FR-4.8 (admin reset) retired; FR-6.10 absorbs admin password reset. |
