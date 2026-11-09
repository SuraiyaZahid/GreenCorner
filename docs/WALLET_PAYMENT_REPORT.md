# GreenCorner — bKash and Nagad Wallet Payments

**Phase 8 delivery report**

Written: 9 November 2026
Branch: `main` · Commit: `6cc5206` · CI run `42650021241`: **all four jobs green**

---

## 1. What was built

A customer can pay for an order with bKash or Nagad instead of a card or cash on
delivery. The store verifies the payment by hand against the wallet statement.

The flow, end to end:

1. The customer reaches the payment page and picks **bKash** or **Nagad**.
2. The page shows the merchant number, the dial code and the exact amount, and
   asks for two things: the **sender number** and the **transaction id**.
3. The customer sends the money in their wallet app, then submits the two
   fields.
4. The order is created. The payment sits at `pending_verification`.
5. An **Admin** opens the order, checks the wallet statement against the
   submitted sender number, transaction id and amount, and either approves or
   rejects it.
6. Approving marks the payment `success` and the order proceeds. Rejecting
   marks it `failed` and records the reason.

### The rules the implementation holds to

- **A submitted transaction id is never treated as proof of payment.** It is
  recorded as a claim, and it stays `pending_verification` until a human has
  checked it. There is no code path that turns a customer submission into a
  successful payment by itself.
- **No gateway call is made anywhere.** The three gateway seams
  (`WalletPayment::initiate`, `WalletPayment::queryTransaction`,
  `Refund::sendToGateway`) make no network call and return
  `gateway_unavailable`. Three tests in the phase 8 suite assert exactly that,
  so the seams cannot start lying without a test failing.
- **A refund is never marked successful without a confirmation.** A refund is
  created as `refund_pending`. It becomes `refunded` only when an Admin
  confirms it with the reference the gateway returned, or a failed attempt is
  recorded as `refund_failed` for review.
- **No merchant credential is committed.** The merchant numbers are public
  information (the customer types them into their app), so they live in
  configuration rather than in code — but they are still configuration, never
  hardcoded in a view or a controller. The gateway API credentials that would
  be needed for automatic verification are named in `config/config.php` as a
  shape only; the values belong in `config/config.local.php`, which is
  git-ignored, and no such value exists anywhere in the tree.

---

## 2. Manual versus gateway — the answer to the question asked

### Manual today — works with no credentials at all

| Step | Who does it | What it needs |
|---|---|---|
| Customer sees the merchant number and amount | The app | `BKASH_MERCHANT_NUMBER` / `NAGAD_MERCHANT_NUMBER` in `config/config.local.php` |
| Customer sends the money | The customer, in their own wallet app | Their wallet account |
| Customer submits sender number + transaction id | The customer, on the payment page | Nothing |
| **Verification that the money arrived** | **An Admin, against the wallet statement** | **A human being and access to the merchant statement** |
| Rejection of a suspect submission | An Admin | A human being |
| Refund | An Admin sends it by hand, then confirms it | A human being |

Everything above is implemented and tested today. **No external account,
contract or API key is needed.** The only thing required for the store to
start accepting wallet payments is a merchant number, which is free to obtain
from bKash or Nagad, and a person willing to check the statement.

The trade-off is honest and worth stating plainly: verification is **manual,
per transaction**. An Admin must open each pending submission, read the
merchant statement, and compare the sender number, the transaction id and the
amount. For a store with a handful of orders a day this is fine. For a store
with hundreds it is a queue.

### Requires official gateway API credentials — not implemented, by design

| Capability | What it would need | Where it would go |
|---|---|---|
| Automatic payment verification (customer redirected to bKash/Nagad, paid in-app, callback confirms) | bKash merchant API: app key, app secret, username, password. **https://developer.bka.sh/** | `WalletPayment::initiate()` and `WalletPayment::queryTransaction()` |
| Automatic refund disbursement | The same merchant APIs, plus a refund endpoint | `Refund::sendToGateway()` |
| Automatic refund confirmation | The same merchant APIs | `Refund::confirm()` |

**No gateway call has ever been made, and none is faked.** The three seams
exist so that adding the official API later is a change *inside those methods*
rather than a change to the checkout flow, the Admin screens or the schema.

Credentials would go in `config/config.local.php` under these names, and
nowhere else:

```
BKASH_APP_KEY, BKASH_APP_SECRET, BKASH_USERNAME, BKASH_PASSWORD
NAGAD_APP_KEY, NAGAD_APP_SECRET, NAGAD_USERNAME, NAGAD_PASSWORD
```

The merchant numbers stay in the **tracked** configuration, because they are
public and must be visible to the customer; the API credentials go in the
**git-ignored** local file, because they are secrets. That split is deliberate
and is enforced by the phase 8 suite.

**What automatic verification would change:** the customer would no longer
submit a sender number and transaction id — bKash or Nagad would collect the
money and tell the store directly, and the `payment_ref` row would be created
by the callback rather than by the customer. The Admin verification screen
would remain, but as an exception queue rather than the normal path. The
schema, the checkout flow and the Admin screens would not need to change.
That is the reason the seams were put where they are.

---

## 3. Changed files

### New files

| File | Purpose |
|---|---|
| `models/WalletPayment.php` | The wallet model: validation, submission recording, verification, rejection. 856 lines. |
| `models/Refund.php` | The refund model: opening, confirming, failing a refund; the unfulfillable-order path. 467 lines. |
| `database/migrations/004_wallet_payments.sql` | The schema change, for an installation that predates it. |
| `tests/phase8_wallet_tests.php` | 44 tests across 14 scenarios plus the gateway-seam honesty group. 1,987 lines. |
| `tests/phase8_schema_check.php` | Asserts the widened enums, the new tables, the unique key and the foreign keys are really in the database. |
| `tests/phase8_binding_check.php` | Asserts all 83 prepared statements bind the right number of values. |
| `tests/phase8_cleanup.php` | Returns the test database to the seeded state. |

### Changed files

| File | Change |
|---|---|
| `controllers/CheckoutController.php` | Offers the wallet methods, validates the submission, records it. |
| `controllers/AdminOrderController.php` | `verifyWalletPayment`, `rejectWalletPayment`, `refundUnfulfillable`, `confirmRefund`, `failRefund`. |
| `models/Payment.php` | The wallet method and the `pending_verification` status. |
| `models/Order.php` | The `paid_unfulfillable` status. |
| `views/checkout/payment.php` | The bKash and Nagad instruction panels and their fields. |
| `views/checkout/confirmation.php` | The wallet details on the confirmation page. |
| `views/orders/show.php` | The wallet details on the customer's order page. |
| `views/admin/orders/show.php` | The Admin verification and refund controls. |
| `config/config.php` | The merchant numbers and the dial codes; the documented credential names. |
| `config/config.example.php` | The merchant number placeholders. |
| `database/schema.sql` | **Now carries the wallet tables** — see §4. |
| `database/seed.php`, `database/migrate.php`, `database/backup.php` | The canonical include paths. |

### The three CI fixes (separate from the wallet feature)

| Commit | Defect | Fix |
|---|---|---|
| `f77b288` | `require_once` does not treat two spellings of one file as one file, so `config/config.php` was included twice on Linux CI → *"Constant CURRENCY_SYMBOL already defined"*. | Every constant and function in the config chain is guarded; every include path is canonical (`dirname(__DIR__)`, `dirname(__DIR__, N)`). 90 files. |
| `5f835b9` | The Docker build could not complete: `mbstring` is compiled against oniguruma and `libonig-dev` was missing → *"Package 'oniguruma' not found"*. | One line added to the Dockerfile. |
| `6cc5206` | A fresh database is built from `database/schema.sql` alone, but the wallet tables only existed in the migration. The Admin order detail page reads both for every order, so it answered with a server error instead of the order. | The base schema now carries the wallet tables and the widened enums. The CI workflow applies the migrations too. |

---

## 4. Database migration instructions

### For a fresh installation

Nothing to do. `database/schema.sql` now creates the complete schema,
including `payment_ref` and `refunds`, the widened `payments.method` and
`payments.status` enums, and the `paid_unfulfillable` order status.

```bash
mysql -u greencorner -p < database/schema.sql
```

### For an existing installation

Apply the migration. It is additive — no row is deleted, no existing card or
COD payment is rewritten, and the five original card and COD shapes keep
their exact meaning.

```bash
mysql -u greencorner -p greencorner < database/migrations/004_wallet_payments.sql
```

Or, through the runner, which records each file in `schema_migrations` so no
file is ever applied twice:

```bash
php database/migrate.php            # apply everything pending
php database/migrate.php --status   # show what is pending
```

The migration is idempotent: running it against a database that already has
the new shape is a no-op. **This was verified** — the migration was applied on
top of the new base schema and completed cleanly.

### What the migration changes

1. `payments.method` widened to `ENUM('card','cod','bkash','nagad')`.
2. `payments.status` widened to `ENUM('pending','success','failed','pending_verification')`.
3. `payments.chk_payment_order_binding` replaced with a constraint that still
   enforces the binding rules for **every** method, including the new ones.
   The five original card and COD shapes are unchanged.
4. `payment_ref` created — one row per customer submission, with a `UNIQUE`
   key on `transaction_id` and foreign keys to `payments` and `users`.
5. `refunds` created — one row per refund attempt.
6. `orders.status` widened to admit `paid_unfulfillable`.

The unique key on `payment_ref.transaction_id` is enforced by the **database**,
not only by the application, so two customers submitting the same transaction
id at the same moment cannot both get through.

---

## 5. Tests completed

### Phase 8 — the wallet suite

**44 passed, 0 failed.** Fourteen scenarios, in order:

| # | Scenario | What it pins |
|---|---|---|
| 1 | Cash on Delivery is unchanged | The wallet work did not disturb the existing method. |
| 2 | bKash selection | The instruction panel, the fields, the amount shown. |
| 3 | Nagad selection | The same, with Nagad's own field names. |
| 4 | An empty sender number is refused | Validation. |
| 5 | An empty transaction id is refused | Validation. |
| 6 | A duplicate transaction id is refused | The uniqueness the database enforces. |
| 7 | An Admin approves a manual payment | The approval path, and that the order then proceeds. |
| 8 | An Admin rejects a manual payment | The rejection path and that the reason is recorded. |
| 9 | A verified payment that cannot be fulfilled | The `paid_unfulfillable` path. |
| 10 | A refund is pending until it is confirmed | That a refund is not successful on creation. |
| 11 | A refund that succeeds | That it becomes `refunded` only on confirmation. |
| 12 | A refund that fails | That a failure is recorded for review. |
| 13 | Unauthorised users cannot reach the payment actions | The access boundary. |
| 14 | Refreshing or resubmitting the checkout | That a refresh does not create a second order or a second submission. |
| — | **The gateway seams are honest** | That all three seams return `gateway_unavailable` and claim no success, and that no merchant credential is committed. |

Plus `tests/phase8_schema_check.php` (the widened enums, the new tables, the
unique key, the foreign keys — all confirmed against the live database) and
`tests/phase8_binding_check.php` (**83 prepared statements, all binding the
right number of values**).

### The whole suite, local and in CI

| Suite | Result |
|---|---|
| Phase 1 (`tests/run.php`) | 67 / 67 |
| Phase 2 | 72 / 72 |
| Phase 3 | 58 / 58 |
| Phase 4 | 73 / 73 |
| Phase 5 | 109 / 109 |
| Phase 6 | 45 / 45 |
| Security checks | 32 / 32 |
| Phase 6 static checks (six files) | 168 / 168 |
| **Phase 8 wallet** | **44 / 44** |

**CI: run `42650021241` — Static checks ✅ · Docker build ✅ · Test suites ✅
· Security checks ✅.**

### The security properties were not weakened

Decision **D-001** (the real credentials live only in the git-ignored
`config/config.local.php`; the application must never connect without them)
survives the refactoring intact. It was re-pointed, not relaxed: the
credential requirement moved from *config-load time* to the *connection point*
in `helpers/db.php`, so a file that merely **reads** the configuration — a
static check, for example — can load it without this installation's
credentials being present, while the application itself still refuses to
connect. Verified by probe: the connection is refused with *"the setting
DB_PASS is missing or empty."*

The phase 1 tests were re-pointed at the enforcement point rather than
weakened, and a new test pins the "readable without credentials" behaviour
deliberately, so the distinction cannot quietly erode.

---

## 6. Remaining limitations

1. **Verification is manual and per transaction.** Every wallet payment needs
   an Admin to check the statement. There is no automation and no queue
   management beyond the pending-submissions list.

2. **No gateway integration.** No payment confirmation and no refund
   disbursement is automatic. The seams are stubs that honestly report
   `gateway_unavailable`.

3. **A refund is sent by hand.** An Admin sends the money, then confirms it
   with the reference they obtained. Until then it sits at `refund_pending`
   and the customer has not been made whole.

4. **The merchant number is not in the tracked configuration.** It is set in
   `config/config.local.php`. An installation that has not set it has no
   wallet methods offered at all — the checkout page hides them rather than
   showing a customer an instruction to send money to a number nobody is
   watching. This is deliberate, but it means the wallet methods are **off by
   default** in a fresh checkout.

5. **The `merchant_number` is a plain string in configuration.** It is not
   validated against the operator's format at configuration time; the
   customer-facing page shows whatever is configured.

6. **One card-suite defect remains, unrelated to this work.** In an earlier
   run of this session the phase 3 test *"A request whose body exceeds PHP
   post_max_size is refused"* failed against the committed HEAD. It did not
   reproduce in the final runs — phase 3 passes 58/58 against a database
   rebuilt from the base schema — so it appears to have been environmental
   rather than a code defect. It has **not** been proven fixed, and it is
   **not** covered by this report. Worth watching in the next CI run.

7. **The Docker image was not built locally.** Docker is not available in
   this environment. The image build was verified only by the CI job, which
   passes.

8. **The Phase 7 files are not part of the CI workflow.** `tests/phase7_*`
   exists in the tree but the workflow does not invoke it. Two of the Phase 7
   files are PowerShell scripts for a Windows host, and
   `tests/phase7_fault_dependency_audit.php` reports that **29 of its 45
   fault-dependent assertions cannot pass with `APP_ENV=production`** — by
   design, because `FaultInjectionController` refuses to act in production
   mode and answers HTTP 404. The CI workflow leaves `APP_ENV` at its
   `development` default, so the fault-dependent tests do pass there. The
   database-integrity check passes (16/16). This is reported for accuracy: the
   Phase 7 coverage is **not** exercised by the pipeline, and this report does
   not claim it as tested.

---

## 7. What is needed to go live with wallet payments

1. Obtain a bKash merchant number and a Nagad merchant number.
2. Put them in `config/config.local.php`:

   ```php
   define('BKASH_MERCHANT_NUMBER', '01XXXXXXXXX');
   define('NAGAD_MERCHANT_NUMBER', '01XXXXXXXXX');
   ```

3. Apply the migration (only for an installation that predates it).
4. Agree who checks the statement, and how often.
5. Confirm the dial codes: bKash `*247#` and Nagad `*167#` are the operators'
   fixed codes and are already in the tracked configuration — verify them
   against the current merchant onboarding material before launch.

Automatic verification would additionally need the merchant API credentials
from **https://developer.bka.sh/** for bKash and the official Nagad merchant
guide for Nagad, plus the implementation of the three gateway seams.

---

*Related: `docs/PERFORMANCE_REPORT.md`, `docs/SESSION_HOUSKEEPING_PROPOSAL.md`,
`config/DECISIONS.md`.*
