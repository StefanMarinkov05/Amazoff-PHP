# UI tests — what each one proves

Every test under `tests/Feature/Livewire/` (storefront) and
`tests/Feature/Filament/` (admin panel) — not by file existence, but grouped
by the component or resource under test, with what each one actually proves
and, where the test exists because of a real incident, what that incident
was. "A test earns its place by proving something ours, not the framework's"
(`CLAUDE.md`) is the filter every row below was written against — where a
test's own header comment already states the reasoning, this page repeats
it rather than re-deriving it.

This page does not replace the write-rules pages
(`docs/reference/write-rules/catalogue-filters.md`,
`product-attribute-values.md`, `product-variation-attribute-values.md`,
`product-category.md`, `coupon.md`) — those state the *contract* a
component or Action honours; this page states which test file proves which
piece of that contract, so a change to behaviour has a known list of tests
to check rather than a full-suite guess.

## Storefront (`tests/Feature/Livewire/`)

### `ProductListCategoryFilterTest`

The category filter reads `?category=<slug>`, not an id — a catalogue link
is meant to be shareable and readable — and a selected parent has to
include every descendant's products, not only its own (the real seeded
tree: Garden holds 3 products directly, on top of Mowers' and Watering's).

- Leaf category shows only its own products.
- A parent category includes its direct children's products, and its own.
- A parent reaches a *grandchild's* products too — the walk is recursive,
  not one level deep.
- A slug matching nothing falls back to the unfiltered catalogue rather
  than a 404 or an error — a stale bookmark or hand-edited URL degrades
  gracefully.
- A SQL-injection-shaped slug (`' OR '1'='1`) is treated as no match, not
  as SQL — `categorySlug` only ever reaches a parameterised `where()`.
- An XSS-shaped slug never reflects unescaped anywhere on the page, and
  does not crash the filter.
- A slug far longer than the column allows does not crash.

### `ProductListAttributeFilterTest`

The largest file here — covers `SetProductAttributeValues` and
`SetVariationAttributeValues`'s filtering surface: `ProductList::$attributeValueIds`,
`attributeFacets()`, and the OR-within/AND-across-attribute semantics.
Full contract: `docs/reference/write-rules/product-attribute-values.md`.

- Selecting one value narrows to products carrying it.
- Two values of the *same* attribute are OR'd ("Black or White") — reported
  live as a bug when both were AND'd and returned zero results, since no
  variation can ever be two colours at once.
- Two values of *different* attributes are AND'd ("that colour AND that
  size") — the two rules coexist and are each tested against the other so
  neither regresses in isolation.
- A value on a non-`is_filterable` attribute is ignored.
- An oversized, non-numeric, nested, or scalar-instead-of-array
  `attributeValueIds` payload does not crash — the URL is
  attacker-controlled input under ADR-0014's allow-list rule.
- A facet is only offered for a value some *visible* product actually
  carries — no facet with a stale zero.
- No attribute facets render until a category is picked (Colour/Size mean
  nothing across a catalogue that also holds power tools).
- A category's own allowed attributes appear once it's picked, and are
  inherited down to a deep subcategory (`ResolveAllowedAttributes`).
- A facet's values are ordered by their own `sort_order`, not
  alphabetically — reported live as Size reading "L, M, S, XL, XS" instead
  of XS through XXL, traced to `Collection::sortBy()` silently accepting a
  bare array of closures as if it were the multi-column form (it isn't).
- An attribute scoped to an unrelated category branch is never offered.
- Dismissing one chip drops only that value, not the whole set.
- A facet's own count narrows against every *other* selected attribute
  (picking Denim narrows Colour/Size to what Denim actually has) but never
  against its own selection (checking Black doesn't make Black itself read
  as zero) — reported live as the first of these two failing.
- A variation-only attribute (Colour, Size — `is_variation_only`) is
  offered as a facet when a variation carries it, and narrows the catalogue
  correctly, even though it can never appear as a descriptive value.
- A product matches a filter through *either* pivot — descriptively, or via
  any of its own variations' axis values — since a shopper filtering
  "Cotton" doesn't know or care which table answers it.
- `toggleAttributeValue()` adds a value on first click and removes it on a
  second click against the same id.
- Dismissing an individual chip drops just that value, keeping the rest.
- `clearFilters()` empties the selection entirely.

### `ProductListPriceAndRatingFilterTest`

- Price filters `regular_price` within `[min, max]`.
- Non-numeric or negative price input resets to "no bound" rather than
  applying a nonsensical value.
- Setting min above the current max pushes max up to match — the field the
  customer just touched wins.
- A price value too large for PHP to represent as an int does not crash.
- Rating excludes a product only when it *has* approved reviews and their
  average is below the selected tier.
- A product with zero approved reviews is always shown, at every tier,
  including the strictest — an unapproved review does not count as "having
  one" either.
- A rating value outside `RATING_TIERS` (the allow-list) is ignored.
- An oversized rating value does not crash.
- A catalogue card shows a buyable sibling variation's discount when the
  *default* variation is out of stock — `ResolveCardVariation` — rather
  than always showing the product's own sticker price. Reported live: a
  product with a buyable, discounted sibling one click away still showed
  "Out of stock" and no discount.

### `ProductListSearchTest`

Search matches an attribute value's own text ("Linen", "Red"), not only a
product's `name`/`short_description` — a shopper typing a material or
colour they remember has no reason to know which table the fact lives in.

- Matches by the product's own name.
- Matches by a descriptive attribute value.
- Matches by a variation-only axis value (Colour/Size can only ever reach a
  product through a variation, never descriptively).
- Does *not* match a product carrying an unrelated attribute value — the
  match is on the term, not "has any attribute value at all."

### `ProductListDemoOrderTest`

The staff-only "Demo order" sort walks a curated sequence of products, each
showcasing one distinguishable catalogue case
(`docs/reference/demo-showcase-order.md`). `isDemoModeAvailable()` is
checked in two places — where the sort button renders, and where
`setSortOrder()`/the query actually apply it — so these tests prove that
*pairing* holds, not just that sorting works.

- The curated sequence shows only those products, in order, excluding
  everything else.
- Once demo mode is on, every other active filter is ignored.
- A guest cannot trigger the demo sort even by calling `setSortOrder()`
  directly, bypassing the UI.
- The case-order badge (`#3 Out of stock`, etc.) shows only under the demo
  sort, never under a normal one.
- A guest never sees the badge even with `demo_case_order` forced into
  component state — the gate is on the viewer, not just on the sort being
  active.

### `ProductDetailsQuantityTest`

`$quantity` reaching the component is user input in the same sense
`#[Url]` properties already are under ADR-0014: Livewire assigns whatever
`wire:model` sends before any of the component's own validation runs.
Originally `int`-typed; a numeric string too large for PHP to represent as
an int threw an uncaught `TypeError` before `updatedQuantity()`'s own clamp
ever ran.

- An oversized numeric quantity does not crash.
- Non-numeric input resets to the minimum order quantity.
- A decimal quantity is rejected back to the minimum.
- A quantity within PHP's int range but past available stock clamps to the
  stock ceiling.
- A quantity below the minimum order quantity floors up to it.
- A normal, valid quantity adds to cart successfully.
- The "add to cart" button warns and disables itself when stock is below
  the minimum order quantity — reported live: a product with
  `min_order_quantity=5` and 2 in stock showed a clickable button
  pre-filled with a guaranteed-to-fail quantity, with nothing on the page
  explaining why.
- No warning shows when stock meets or exceeds the minimum.
- The add is still refused cleanly server-side even if the disabled button
  is bypassed — the disabled attribute is a UX affordance, not the actual
  guard; `AddToCart`'s own refusal is what's load-bearing.

### `ProductDetailsVariationIdTest`

Same incident class as the quantity test above, a different property: `?v=`
(`variationId`, `#[Url]`-bound) was `?int`-typed. A number too large for
PHP to represent cleanly decodes to a `float` during hydration, which
`?int` refuses the same way it refused the oversized quantity string —
confirmed live via `curl` against a real product page before the fix, not
assumed from the type alone.

- An oversized variation id does not crash.
- A non-numeric variation id does not crash.
- An id that matches nothing real falls back to the default variation
  rather than erroring.

### `AuthSessionInvalidationTest`

What each of this file's ten cases proves, the incidents that caused two of
them to exist, and what is deliberately not covered, all moved to
`reference/write-rules/auth.md` — the outcomes page for this area, in the
same shape as `write-rules/cart.md` for cart writes. This page names the
test; that page owns the behaviour it proves, so the two do not drift out
of step by being maintained in both places. New auth test cases get their
behaviour documented there, not here.

### `CheckoutTest` (`tests/Feature/Payment/`)

The full cycle — cart → checkout → order → payment → intent →
confirmation. §37 criteria 6, 7 and 8. Stripe is faked here; the Action's
own arithmetic is `StripePaymentTest` and the endpoint is
`StripeWebhookSecurityTest`. What these prove is the *wiring*.

- Reachable by a guest and by a signed-in customer, who is prefilled but
  not locked — whatever is submitted is what gets snapshotted.
- A guest order carries no `user_id`; a signed-in customer's does.
- The order is priced at the server-computed total, and the payment copies
  the order's figure rather than the caller's.
- **There is no price property to tamper with**, and Livewire throws
  `PublicPropertyNotFoundException` on an invented one — so §37 #8 rests on
  the component having no price field at all, not on validating one away.
- A card order gets a PaymentIntent and a client secret, and stays
  `Pending`: only the webhook marks it Paid, because a browser redirect is
  not proof of payment.
- Stock is reserved at order time, not payment time — otherwise two
  customers could both reach the card step for the last unit.
- An empty cart, an out-of-stock line, and a missing street/office are
  refused on the form rather than as a 500, and a failed order leaves no
  orphaned payment row.
- The confirmation page 404s for a stranger guessing an id and for a
  signed-in customer looking at someone else's order — sequential serial
  numbers would otherwise enumerate every customer's address. 404 and not
  403, since a 403 confirms the order exists.

**A trap worth knowing.** `ResolveCurrentCart` finds a guest's cart by
`Session::getId()` and a customer's by `user_id`. A cart created any other
way is invisible to the component, which then quietly opens a second empty
one — every assertion then passes or fails against the wrong cart. The
helpers here bind the cart to whoever the test acts as, and the cart-link
test uses an owned cart because a plain `$this->get()` gets its own session.

### `StripePaymentTest` and `StripeWebhookSecurityTest` (`tests/Feature/Payment/`)

Covered in full by `explanation/security-model.md`, "The Stripe webhook" —
including which guard does what, established by removing each in turn.
Short version: `StripeWebhookSecurityTest` is seventeen adversarial cases
against the endpoint (including signing-secret rotation), validated by
swapping in a deliberately vulnerable middleware and, separately, reverting
to a single secret; `StripePaymentTest` covers intent creation, event
handling, dispute handling, and refund arithmetic, including the
cumulative-versus-delta trap and the amount-match guard that refuses to mark
a payment paid for the wrong sum.

## Admin panel (`tests/Feature/Filament/`)

### `ProductResourceTest`

The widest admin-panel file — proves the panel's write paths reach the same
Actions and guards the storefront does, per ADR-0007's "Filament calls the
same Actions wherever a rule exists" rule. Where a test exercises Filament's
own form/table machinery (`Livewire::test()->fillForm()`), what it actually
*proves* is the domain rule behind the field, not the machinery — see
`CLAUDE.md`'s own worked example on this file's `'refuses a product with no
variations'` case.

- Weight and dimensions persist on create, and survive an unrelated field
  edit on update without being erased — a real bug where
  `->dehydrated(false)` on those fields silently discarded them on every
  save, form and Larastan both green throughout since the form definition
  looked correct in isolation.
- Creating a product through the panel produces a stock row.
- Creating a product wires each variation's own attribute-value combination
  through `SetVariationAttributeValues` — not just names, but the actual
  axis assignment.
- A product with no variations is refused at the form, surfaced as a
  `ProductRequiresVariationException`-driven form error rather than a
  silent save or a 500.
- A variation's stock can be adjusted through the relation manager
  (`AdjustStock`), and a reduction below reserved stock is refused,
  leaving current stock untouched.
- Adding a variation through the relation manager creates its stock row
  too.
- A variation's attribute values can be set through the relation manager,
  and editing pre-fills the form with the *current* combination rather than
  a blank picker.
- A duplicate attribute value for one axis (two Colour values on one
  variation) surfaces as a Filament notification, not an uncaught 500 —
  `ReportsDomainFailuresTest` is the trait this behaviour rides on.
- A negative discount price on a variation is rejected as a form
  validation error, not a raw `QueryException` reaching the browser —
  reported live: the check constraint was always correct, but no form
  field enforced `minValue(0)` before the write reached the database.
- Publishing a product is refused once its last variation is gone.
- Deleting a product through the panel deletes its variations too.

### `ProductCategoryResourceTest`

The panel reaching `UpdateProductCategory`/`DeleteProductCategory` — proven
through Livewire rather than the Action directly, because the *wiring* is
what's under test (the Actions themselves are proven by their own
`tests/Feature/Actions/` files).

- Deleting a category through the panel actually calls the Action.
- Deleting a category that still has a subcategory reports as a
  notification, not a 500.
- A category's full ancestry shows on its edit page
  (`category-ancestry.blade.php`).
- A cycle (reparenting a category under its own descendant) is refused at
  the form, before the Action is even reached — the parent `<select>`
  excludes the category and its own descendants from the option list.
- A cycle is refused in the Action too, whatever the form offered — the
  form-level exclusion is a UX nicety, not the actual guard.
- A category cannot be set as its own parent, checked directly against the
  Action.
- A legal reparent (moving a category under an unrelated branch) succeeds
  through the panel.
- Editing an unrelated field does not reset a category's parent as a side
  effect.

### `CouponResourceTest`

Covers the `databaseTransactions()` fix in `AdminPanelProvider` —
`docs/reference/actions.md`'s "Panel-level transactions" section and
`docs/reference/write-rules/coupon.md` have the full contract this closes.

- The admin panel has database transactions enabled at all
  (`Filament::getPanel('admin')->hasDatabaseTransactions()`).
- Editing a coupon and syncing its product-eligibility pivot happen inside
  one transaction — a failure partway through cannot leave the coupon row
  and its eligible-products pivot disagreeing.
- The pivot sync still commits correctly at the `RefreshDatabase` test
  baseline when the panel-level transaction is deliberately off, so the
  transaction test above isn't merely passing because `RefreshDatabase`
  itself wraps every test in one.

### `DashboardTest`

Every widget on the admin dashboard renders against real data rather than
being trusted to work because it compiles.

- Revenue overview renders against real paid and refunded payments.
- The revenue trend chart renders.
- Orders-by-status renders against every current `OrderStatus` case, not
  just whichever happen to exist in the test's own fixtures.
- Top-selling products excludes orders that never became a completed sale.
- Returns-and-damage ranks by loss *rate*, not raw count — a product with 1
  return out of 2 sold is worse than one with 5 out of 500.
- Reviews overview counts pending and approved separately, not combined.
- The rating-distribution chart renders.
- The whole panel is gated by `canAccessPanel()`, not by any individual
  widget failing to render for an unauthorized user — the gate is at the
  panel boundary, not assembled from each widget behaving.
- `/admin` boots with every widget registered and none crashing panel
  discovery — a widget that throws during Filament's own registration pass
  takes down the entire panel, not just itself.

### `ProductViewTest`

Both the Product and Coupon resources had no View page at all before this
change — editing was the only way to read a full record, which meant
opening a form to look, and the table's own truncated columns were the only
"view."

- A product's full text shows on its View page, not the table's
  capped/truncated version.
- Variation image thumbnails render from the eager-loaded pivot without
  triggering an N+1 — the eager-load is asserted, not just the visual
  result.
- A coupon's full description shows on its View page, not the table's
  capped version.
- The product name is still capped on the table (deliberately, for row
  height) while remaining fully reachable via the View page.

### `CarrierResourceExtrasTest`

- The Carriers list explains what "COD" means under its own title, rather
  than assuming the abbreviation is self-evident to a new admin.
- The admin user menu offers a way back to the storefront — an admin
  session is not a dead end.

### `ReportsDomainFailuresTest`

Every domain exception in `App\Exceptions` extends either
`RuntimeException` (seven of eight) or `InvalidArgumentException`
(`InvalidCartQuantityException`, deliberately — see its own docblock). The
trait backing `ReportsDomainFailuresTest`'s namesake behaviour has to catch
both base classes, or the one exception on the `InvalidArgumentException`
side reaches a Filament page as an uncaught exception instead of a
notification — the exact failure this trait exists to prevent for every
other domain exception.

- A `RuntimeException`-based domain exception is caught and reported as a
  notification.
- An `InvalidArgumentException`-based domain exception is caught too — the
  one exception type that would silently fall through if the trait only
  checked for `RuntimeException`.
- A non-domain `RuntimeException` is *not* caught — an unrelated framework
  or PHP-internal `RuntimeException` should still surface as a real error,
  not be swallowed into a generic notification.
- A non-domain `InvalidArgumentException` is not caught, for the same
  reason.
- A `QueryException` is not caught — a database-level failure is not a
  domain refusal and must not be presented to staff as if it were one.
