# §37 acceptance criteria — mapped to the tests that prove them

Part of the [testing reference](README.md). §37 of
[specification.md](../specification.md) is the graded contract: twenty
criteria, all mandatory. Criterion 20 — "critical payment, order, and
authorization workflows have automated tests" — is itself unprovable by
pointing at one file, because the suite that proves it is real is scattered
across ~150 test files by aggregate, not by criterion. This page is that
missing index: each of the twenty rows below names the actual test file(s)
and case(s) that exercise it today, not a restatement of the criterion.

No new test code was written for this page. Every case named here already
existed, already passes, and was already earning its place under
`coding-conventions.md`'s "a test earns its place by proving something
ours" rule — this page only adds the traceability from graded criterion to
proof, which was the actual gap.

**How to read "Proof":** a specific test file plus representative case
names — not exhaustive, but enough that opening the file and running
`pest --filter` on the name shown confirms the claim first-hand rather than
trusting this table. **"Confirmed live" or "documented, not automated"**
marks the few criteria whose strongest evidence is not a currently-running
Pest case — named explicitly rather than folded into the same column as
the rest, so this page cannot be mistaken for claiming more automation than
exists.

---

## 1. An administrator can create and manage products

**Proof:** `tests/Feature/Actions/Catalogue/CreateProductTest.php` —
`'creates a product with its variation and stock row'`,
`'refuses a product with no variation and writes nothing'`,
`'allows an actor holding both catalogue permissions'`. Also
`tests/Feature/Filament/ProductResourceTest.php` (the panel's own form) and
`AddProductVariationTest.php`, `SetProductAttributeValuesTest.php`,
`ProductImageTest.php` for the rest of "manage."

## 2. Customers can browse and search products

**Proof:** `tests/Feature/Livewire/ProductListSearchTest.php` —
`'matches a product by its own name'`,
`'matches a product by a descriptive attribute value'`,
`'matches a product by a variation-only axis value'`. Storefront reachability
is also covered by `tests/Browser/SmokeTest.php` and the click-through in
`ui-testing/phase-1-storefront-clickthrough.md`.

## 3. Customers can filter and sort products

**Proof:** `tests/Feature/Livewire/ProductListCategoryFilterTest.php`,
`ProductListBrandFilterTest.php`, `ProductListAttributeFilterTest.php`, and
`ProductListPriceAndRatingFilterTest.php` — `'filters products within a
price range'`, `'excludes a product whose average rating is below the
selected tier'`. Includes adversarial cases in the same files: a
SQL-injection-shaped or XSS-shaped filter value, a value too large for PHP
to represent as an int — filtering degrades to "no match," never a crash or
a reflected value.

## 4. Customers can add products to a shopping cart

**Proof:** `tests/Feature/Actions/Cart/AddToCartTest.php` — `'adds a line
at the requested quantity'`, `'sums into the existing line rather than
adding a second row'`, `'keeps two variations of one product on separate
lines'`.

## 5. Cart quantities are validated correctly

**Proof:** same file — `'rejects a quantity of zero'`, `'rejects a
negative quantity'`, `'refuses a quantity below the product minimum'`,
`'refuses more than is available'`, `'counts reserved stock as
unavailable'`, `'refuses a line past the distinct-line cap'`.
`UpdateCartItemQuantityTest.php` covers the same rules on an existing line.

## 6. A guest can complete checkout

**Proof:** `tests/Feature/Payment/CheckoutTest.php` — `'is reachable by a
guest and by a signed-in customer'`, `'lets a guest place a
cash-on-delivery order'`, `'shows the confirmation to the guest who just
placed the order'`, `'404s for a stranger guessing an order id'` (the
session-claim ownership check a guest relies on instead of an account).

## 7. A registered customer can complete checkout

**Proof:** same file — `'prefills a signed-in customer without locking the
fields'`, `'records the user on an order placed by a signed-in
customer'`, `'shows a registered customer their own order without a
session claim'`, `'404s for a signed-in customer looking at someone else's
order'`.

## 8. The final total is calculated on the server

**Proof:** same file, directly — `'charges the server-computed total, not
anything the browser could send'`, `'has no price property a client could
set, and refuses one that is invented'`, `'locks orderId and clientSecret
against client tampering'`. `tests/Feature/Actions/Order/CreateOrderTest.php`
— `'recomputes the total from the cart rather than trusting any input'`.
`tests/Feature/Support/CalculateCartTotalsTest.php` is the arithmetic
itself, isolated from the HTTP layer.

## 9. Stripe test payments work

**Proof:** `tests/Feature/Payment/StripePaymentTest.php` — `'sends the
amount from the payment row, in minor units, and stores the intent id'`,
`'refuses an intent for a cash-on-delivery payment'`. `CheckoutTest.php` —
`'creates a PaymentIntent and hands the browser a client secret for a card
order'`. `tests/Browser/ThreeDSecureTest.php` drives a real 3DS challenge in
an actual browser against Stripe's own test mode —
[stripe-testing.md](stripe-testing.md) has the full account and what it
does and does not cover.

## 10. Stripe webhooks update payment statuses

**Proof:** `StripePaymentTest.php` — `'marks a payment paid on
payment_intent.succeeded'`, `'marks a payment failed on
payment_intent.payment_failed'`, `'cancels an awaiting-payment order and
releases its stock on payment_intent.canceled'`, `'advances an
awaiting-payment order to Paid on payment_intent.succeeded'`. The transport
and authentication side (not "did the status change," but "can anything
else reach this code path") is `tests/Feature/Payment/
StripeWebhookSecurityTest.php`, 21 adversarial cases — see
[security-model.md](../../explanation/security-model.md), "The Stripe
webhook."

## 11. Duplicate Stripe events are not processed twice

**Proof:** `StripePaymentTest.php` — `'applies the same event id only
once'`. `StripeWebhookSecurityTest.php` — `'applies a genuinely signed
event exactly once, however many times it is replayed'`, which is the
stronger version: the same case sent three times over real HTTP, not a
single call asserted once.
[security-model.md](../../explanation/security-model.md)'s "Freshness is
not enough on its own" section records which half of the defence
(`payment_events.stripe_event_id` UNIQUE, or the catch around it) actually
does the work, established by removing each in turn.

## 12. Customers can select Econt or Speedy

**Proof:** `CheckoutTest.php` — `'requires a carrier to be selected'`,
`'refuses an inactive carrier even if its id is submitted directly'`,
`'places an office delivery only once an office is picked through
selectOffice()'`.

## 13. At least one courier works with a real test environment

**Documented, not a currently-running automated test.** §37's own
"External APIs are mocked" line (the paragraph above the criteria list)
means the *automated suite* — `EcontGatewayTest.php`, `SpeedyGatewayTest.php`
— asserts against canned responses, correctly: a courier's uptime is not
this repository's to guarantee on every CI run. The criterion is proven
instead by a dated, one-time live verification against Speedy's real
sandbox account, recorded in
[couriers.md](../../explanation/couriers.md), "Speedy's price quote, fixed
and confirmed live — 2026-09-11": three field-name mismatches Speedy's real
API rejected that no mocked test could have caught, then a real quote
returned. The mocked test suite encodes what that verification found —
`SpeedyGatewayTest.php`'s `'sends cash-on-delivery on
service.additionalServices.cod, not payment.cod'` is the regression test
for exactly that bug — but the live proof itself is a documented event, not
a repeatable Pest case. Re-verify against the real sandbox before claiming
this criterion still holds if `SpeedyGateway`'s request shape changes.

## 14. Both couriers use the shared courier interface

**Proof:** structural, checked by Larastan and by both gateway test files
sharing one contract's assertions. `tests/Feature/Support/Courier/
CachedCourierGatewayTest.php` exercises the interface's decorator, which by
construction cannot compile against a courier that does not implement
`App\Contracts\CourierGateway`. ADR-0001 has the decision.

## 15. A shipment can be created from an order

**Proof:** `tests/Feature/Actions/Shipment/CreateShipmentTest.php` —
`'ships a prepaid order with no cod_amount'`, `'ships an unpaid
cash-on-delivery order and carries the total to the courier'`, `'refuses a
second shipment for one order'`. `tests/Concurrency/
CreateShipmentConcurrencyTest.php` covers the race two staff members
double-clicking would create.

## 16. An employee can update order statuses

**Proof:** `tests/Feature/Actions/Order/TransitionOrderStatusTest.php` —
`'completes every legal transition'` (a dataset over the whole matrix),
`'allows an actor holding updateStatus_order for a routine advance'`,
`"holds warehouse_employee's real, seeded permission boundary"`. The panel
wiring: `tests/Feature/Filament/OrderStatusActionTest.php`.
`tests/Concurrency/TransitionOrderStatusConcurrencyTest.php` proves the
lock actually does something, by deletion (removing it changes the
outcome).

## 17. A content editor can publish articles

**Proof:** `tests/Feature/Actions/Content/PublishArticleTest.php` —
`'refuses an illegal transition regardless of who asks (case A)'`,
`'refuses a legal transition from an actor without publish_article (case
B)'`, `'accepts a legal transition from an actor with publish_article — the
control (case C)'`. `tests/Feature/Livewire/ArticleDetailsVisibilityTest.php`
covers what an unpublished article looks like from the outside (SEC-001).

## 18. User roles cannot access prohibited features

**Proof:** `tests/Feature/RolePermissionTest.php` — the 19-resource ×
4-role denial matrix CLAUDE.md's own working-style section refers to:
`'grants an administrator every ability without attaching any permission'`,
`'denies a customer every resource in the panel'`, `'gives content_editor
content resources and nothing else'`, `'gives warehouse_employee operations
resources and read-only carriers'`, `'keeps order and payment creation out
of the panel entirely'`. `ui-testing/phase-4-admin-panel-clickthrough.md`
is the same matrix driven live in a browser, not only asserted at the
kernel.

## 19. The website works on desktop and mobile

**Proof:** `tests/Browser/ResponsiveTest.php` — the one criterion this
suite cannot prove by any other means, since responsiveness is a rendering
property no HTTP-level Feature test observes.
[browser-testing.md](browser-testing.md) has the honest gap list this
criterion's proof stops short of: one browser, no real devices, no text
zoom — read it before treating this criterion as covered beyond what a
single headless Chromium viewport sweep can show.

## 20. Critical payment, order, and authorization workflows have automated tests

**Proof: this page.** Rows 6–11 (checkout, server-side totals, Stripe
payments, webhooks, idempotency) are the payment/order half; rows 1, 16–18
are the authorization half — `RolePermissionTest.php`'s matrix,
`TransitionOrderStatusTest.php`'s permission-gated transitions, and
`StripeWebhookSecurityTest.php`'s 21 cases proving the one unauthenticated,
money-moving route in the application rejects everything that is not a
genuinely signed Stripe event. Each is a real, currently-passing Pest case
in the files named above — this page is the trace from "the criterion says
so" to "here is the exact assertion," which is what was missing, not the
tests themselves.

---

## What this page is not

Not a coverage percentage, and not a claim that every line behind these
criteria is tested — [coverage.md](coverage.md) has PCOV's actual numbers
for whoever wants that instead. Not a substitute for
[stripe-testing.md](stripe-testing.md)'s or
[security-testing.md](security-testing.md)'s own honest gap lists — read
those before trusting the payment or authorization path beyond what is
named here. And not evidence for criteria 13 and 19 in the same sense as
the other eighteen: 13's strongest proof is a dated live verification, not
a repeatable automated test, and 19's proof is real but carries
`browser-testing.md`'s recorded gaps. Naming that distinction plainly here
is the point — overclaiming which criteria are "automated-tested" would
make this page less trustworthy than the scattered files it replaces.
