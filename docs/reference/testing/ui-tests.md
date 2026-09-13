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
`product-category.md`, `cart.md`, `coupon.md`) — those state the *contract* a
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

### `ProductPriorPriceDisplayTest`

The Omnibus / ЗЗП чл. 6б prior-price line (ADR-0021), shown wherever a reduced
price is announced.

- **Product page** shows "Lowest price in the last 30 days: €X" for a
  discounted product with price history — X is the lowest observation in the
  30 days before `discount_starts_at`.
- **Not shown** for a product that is not on sale, even when history exists.
- **Catalogue card** shows "Lowest in 30 days: €X" for a discounted product.

The Action / resolver logic — which price gets recorded, how the window and
the fallback resolve — is `RecordPriceObservationTest`, `ResolvePriorPriceTest`
and `SnapshotProductPricesTest`; this file covers only the rendered line.

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

### `ProductDetailsLockedIdsTest`

SEC-014. `$productId` and `$imageIndex` were `?int`/`int`, not `#[Locked]`,
despite both being set only server-side (`mount()`, and
`setImage()`/`nextImage()`/`previousImage()` respectively — confirmed no
blade `$set` targets either). A client `$set(..., <34-digit>)` on either
threw an uncaught `TypeError` at hydration, confirmed live before the fix.
Same incident class as `ProductDetailsVariationIdTest`'s own `$variationId`
case, a different pair of properties, closed with `#[Locked]` instead of
widening — since nothing legitimately client-sets either one.

- `productId` is locked against client tampering (`$set` throws
  `CannotUpdateLockedPropertyException`).
- `productId` does not crash — throws the lock exception, not a `TypeError`
  — on an oversized client-set value.
- `imageIndex` is locked against client tampering.
- `imageIndex` does not crash on an oversized client-set value.

### `ProductDetailsReviewRatingHydrationTest`

SEC-014. `$reviewRating` was `public int`, and the star-rating widget
legitimately drives it via `wire:click="$set('reviewRating', N)"` — the one
property in this sweep that cannot be `#[Locked]` (that throws on any
client set, breaking the feature) but is still hydrated from the raw client
value before any component code runs. Widened to `mixed`;
`submitReview()`'s `integer|min:1|max:5` rule still guards what is
persisted.

- Setting `reviewRating` to a number too large for PHP to represent as an
  int does not crash.

### `ProductDetailsReviewSubmissionTest`

`CreateProductReview` (§24, verified-purchase reviews) existed and was
tested at the Action layer, but nothing on the storefront called it — this
is that missing wiring. `canReview()` is a read-only mirror of
`OrderItem::reviewableBy()`, the Action's own eligibility scope (delivered,
returned, or cancelled after commitment — see `write-rules/product.md`), so
the form can hide itself instead of only failing on submit; these tests
cover both that gate and the real submission path through the Action, not a
duplicate of the Action's own coverage.

- The form is hidden from a guest.
- The form is hidden from a signed-in customer with no reviewable order for
  the product.
- The form shows for a customer with a delivered order.
- The form shows for a customer whose order was cancelled after they
  committed to it (from `Confirmed`, not `AwaitingPayment`).
- The form is hidden for a customer whose order was cancelled while still
  `AwaitingPayment` — the expiry-sweep/failed-webhook/self-cancel shape.
- The form is hidden again once that customer has already reviewed it.
- A submission reaches `CreateProductReview` for real (not a modal that
  only renders): the resulting row's `user_id`, `order_item_id`, `rating`,
  and `approved` (always `false` on creation) are asserted, not just a
  success message.
- A review body under the minimum length is refused by Livewire validation
  before the Action is even called.
- If eligibility changes between this render and the click — a second
  review lands in between — `canReview()`'s read-only check cannot catch
  that race, only the Action's own `UNIQUE`-constraint check can; asserted
  by seeding a second review after the component mounts and confirming the
  submission is refused rather than silently succeeding.

### `CartPageTest`

What `CartPage` contributes on top of the Actions and Support classes it
wires up, not their own rules — those are `AddToCartTest`,
`UpdateCartItemQuantityTest`, and `CalculateCouponDiscountTest`'s job. See
`write-rules/cart.md` and `write-rules/coupon.md` for the contract itself.

- The ownership gate: an id for a line in someone else's cart is a no-op
  for `increment`/`decrement`/`remove`, not an error and not a crash.
- A typed quantity below 1, or past available stock, surfaces as a
  line-scoped form error and snaps the input back to the row's real
  quantity rather than leaving a value that was never saved.
- A non-numeric typed quantity (mid-edit, backspacing) resets silently,
  with no error — `AddToCart`'s own docblock names this as deliberate.
- `TouchCartExpiry` actually fires on a successful quantity change — the
  one thing distinguishing this from the many storefront writes that don't
  touch it yet (see "Known gaps" below).
- `applyCoupon`/`removeCoupon` map an unrecognised code and the Action's
  own refusal (e.g. below the minimum) to distinct, correctly-scoped form
  errors, and a successful apply clears the input field.
- `discount()`'s bridge into `CalculateCouponDiscount`: with no coupon
  applied it shows the plain undiscounted total and VAT; with a coupon
  scoped to only part of the cart, it shows the discount, the payable
  total, and VAT computed across the *whole* cart (discounted matched
  lines plus untouched unmatched lines) — the regression test for the bug
  `CalculateCouponDiscount::vatAfterDiscount()` had until 2026-09-03, where
  an unmatched line's VAT was dropped from the total entirely rather than
  kept at its original value. See `write-rules/coupon.md`, "Known gaps".
- A coupon that was valid when applied but has since become inapplicable
  (e.g. a price drop takes the cart below the minimum) shows no discount
  on the next render, without the customer ever removing it explicitly.

### `CartBadgeTest`

The header's cart count — `CartBadge`'s own job over `ResolveCurrentCart`
and `CartItem`.

- Uses `ResolveCurrentCart::existing()`, not `forVisitor()`: rendering the
  badge for a visitor with no cart yet creates no `carts` row — the
  component's own docblock names this as the reason the two resolvers
  differ (a `forVisitor()` badge would write a row for every crawler that
  ever loaded the site).
- Sums `quantity` across every line rather than counting rows.
- The label caps at `9+` past `DISPLAY_CAP`, while the underlying count
  stays exact (used for the `aria-label` and by other components).
- Refreshes on the `cart-updated` event `CartPage` and `ProductDetails`
  both dispatch — the badge reads no request input of its own, so without
  the listener it would show whatever count was true at mount and never
  again.

### `AuthSessionInvalidationTest`

What each of this file's ten cases proves, the incidents that caused two of
them to exist, and what is deliberately not covered, all moved to
`reference/write-rules/auth.md` — the outcomes page for this area, in the
same shape as `write-rules/cart.md` for cart writes. This page names the
test; that page owns the behaviour it proves, so the two do not drift out
of step by being maintained in both places. New auth test cases get their
behaviour documented there, not here.

### `CartMergeOnAuthenticationTest`

Eight cases over the guest→user cart merge at `Login` and `Register`. The
Action (`MergeGuestCart`) was already covered by `MergeGuestCartTest` and two
concurrency tests; this file pins the **caller**, which did not exist until
2026-09-04.

The case carrying the most weight is the ordering. A guest cart is keyed on
`session_id`, and both call sites regenerate the session immediately after
authenticating — so the capture must happen *before* regeneration or it
matches nothing and loses the basket silently. `MergeCartOnAuthentication`
splits into `capture()`/`apply()` for exactly this, and the test suite is
what holds it in place.

Covered: a guest basket surviving sign-in; the same through registration;
quantities summing when both carts hold the same variation; both lines
surviving when they hold different ones; the guest expiry being cleared from
the surviving cart (or `ExpireCarts` deletes a customer's basket a day
later); a normal sign-in with no guest cart; a login still succeeding when
the captured cart has since been deleted; and another session's cart being
left alone.

Verified by moving the capture back after `session()->regenerate()`: **4 of 8
failed** — precisely the four involving a guest basket. The four that held
are the no-cart sign-in, the deleted-cart tolerance, the other-session case,
and registration (which was not reverted) — the split that shows each case
targets what it claims.

### `TrackOrderTest`

Nine cases over `/orders/track`, the one order route deliberately open to
anyone. The rules it pins are CLAUDE.md's own, and each case goes red when
its mechanism is removed:

- **Both fields required.** A real serial with the wrong email is refused,
  and so is a real email with the wrong serial. Removing the `email` half of
  the query turns the first red.
- **The refusal is not an oracle.** "Wrong email" and "no such order"
  produce the *same* message, asserted by comparing the two directly — a
  difference would confirm which serials exist to an attacker walking the
  sequential range.
- **Throttled** at 5 attempts, and the counter **clears on success**, so a
  customer who mistypes twice is not locked out of their own order.
- **No disclosure.** An order with a delivery address is tracked, and the
  test asserts the street, phone and name are absent from the response.
  Email possession unlocks status, dates and a total — not the contents.
- **The entitlement property is `#[Locked]`.** A client write to
  `foundOrderId` throws, the SEC-001/SEC-002 shape.

The success case is the control: without it a component that refused
*everything* would pass every denial case above.

Verified by deleting the email half of the lookup: **2 of 9 failed** — the
wrong-email refusal, and the disclosure case, which starts leaking once a
wrong email succeeds. The other 7 correctly held, since they do not depend
on that half.

**Prefill (4 cases).** `mount()` fills fields as a convenience, never
bypassing `track()`'s single-query check:

- A signed-in customer's own email is prefilled (retyping the address they
  are logged in with is friction with no security value — their session,
  not the email, is the secret for them).
- Arriving from `?order=<serial>` on **one of the customer's own orders**
  prefills both fields.
- `?order=<serial>` for an order the visitor does **not** own prefills only
  the serial (their own URL input, reflected) — the email stays their own,
  never the order owner's, so this is not an enumeration oracle.
- A guest gets the serial from the URL and nothing identifying.

### `OrderDetailsTest`

Six cases over `/account/orders/{order}` (`OrderDetails`) — one order in
full. Like `OrderHistory`, a scoped read, so the scoping is most of what
matters, plus that the page shows what the earlier "link goes to the
confirmation page" gap was missing.

- Shows the customer's own order with a **product link** (`/products/{slug}`)
  and **currency** (`€42.00`) — the control, and the two things
  `checkout.confirmation` did not carry.
- **404 for another customer's order**, and **404 for a guest order** —
  `order()` starts from `auth()->user()->orders()` and `abort_if(null, 404)`,
  so an id that is not the customer's is indistinguishable from one that
  does not exist (SEC-002, the same non-disclosure `OrderHistory` keeps).
- The route requires authentication.
- The **delivery address** renders; with no shipment row, a "not shipped
  yet" line rather than a blank panel.
- With a shipment row but **no tracking number** (the open courier slice —
  `CreateShipment` does not call the courier), a placeholder line says a
  number appears once the parcel is collected, rather than showing nothing.

Verified live: the page renders with product links resolving, another
customer's id 404s in the browser, and the `?order=` prefill from the
"Open the tracking page" link fills both fields for an owned order.

### `Account/RequestReturnTest`

Four cases over `/account/orders/{order}/return` (`RequestReturn`, ADR-0020) —
the 14-day right of withdrawal, scoped exactly like `OrderDetails`.

- The owner of a **delivered, in-window** order submits a line + reason and a
  `returns` row (status `Requested`) plus its `return_items` are written; the
  page shows "received".
- **404 for another customer's order** — `order()` starts from
  `auth()->user()->orders()`.
- An order **outside the window** (delivered 30 days ago) renders the "not
  eligible" message, not the form.
- An Action refusal (`ReturnNotAllowedException`, forced by the order flipping
  status between render and submit) surfaces as a **form error, not a 500**,
  and writes nothing.
- **SEC-016.** A garbage-shaped `quantities` value (a nested array where a
  quantity is expected) is refused with a form error rather than silently
  cast to `1` and written as a real return — PHP's `(int)` of any non-empty
  array is always `1`, which previously produced a legitimate-looking
  return the customer never requested. Confirmed no `OrderReturn` row is
  created for the refused case.

### `OrderHistoryTest`

Five cases over `/account/orders`. The component is a scoped read, so the
scoping is the whole of what is worth testing:

- Lists the signed-in customer's own orders (the control).
- **Never shows another customer's orders** — two users, one order each.
- Does not show **guest orders** (`user_id` null) to anyone's account list.
- The empty state is asserted *while another user's order exists*, so it
  proves scoping rather than an empty table.
- The route requires authentication.

The list groups the current page into "In progress" and "Completed" via
`OrderStatus::isConcluded()` (Delivered/Cancelled/Returned/Refunded), and
each row links to `account.orders.show` (`OrderDetails`), not
`checkout.confirmation`. Row markup is `<x-account.order-row>`, shared with
nothing else yet but written as a component so the two groups cannot
diverge.

Verified by replacing `$user->orders()` with `Order::query()`: **3 of 5
failed.** The 2 that held are the success control and the auth redirect,
neither of which touches the scoping — which is what shows each case targets
what it claims.

### `EditProfileTest`

`/account/profile` — the missing piece against §4–5's account pages. No
Action: one UPDATE with no invariant the schema cannot express, same test
`Register`/`ContactForm` already pass.

- Redirects a guest to `/login`.
- Prefills every field from the signed-in user.
- Saves a name, email, and phone change.
- Clearing the phone field nulls it rather than rejecting an empty string —
  `phone` is nullable in the schema.
- An email already used by another account is refused (`Rule::unique`).
- Saving *without* changing the email does not trip on the row's own
  address — `->ignore($user->getKey())` is what this proves; without it,
  every save of an unrelated field would falsely fail on "email taken."
- A first name under the minimum length is refused.

### `ManageAddressesTest`

`/account/addresses` — the customer's saved-address book, closing another
piece of §4–5's missing account pages. **Deliberately not wired into
checkout** — `CheckoutPage` still collects address fields inline on every
order; this page has no consumer yet, and giving it one is a separate
change (see the class's own docblock).

- The route requires authentication.
- Lists only the signed-in customer's own addresses — scoping, same
  reasoning `OrderHistoryTest` gives for testing it explicitly.
- Adds a new address, scoped to the signed-in customer via
  `$user->addresses()->create()`.
- An incomplete submission is refused and writes nothing.
- Edits an existing address.
- **Cannot edit or delete another customer's address by guessing its id** —
  both `startEditing()` and `delete()` scope through
  `$user->addresses()->findOrFail()`, never `Address::findOrFail()`;
  asserted by confirming the scoped lookup throws
  `ModelNotFoundException` for a real address that belongs to someone
  else, not by trusting the method name.
- Marking a new address as the default billing address clears the
  previous default — "at most one default per kind" is enforced in
  application code (`clearOtherDefaults()`), not a schema constraint.
- Confirms billing and shipping defaults are independent: setting a new
  default billing address does not touch an existing default *shipping*
  address.

### `ManageAddressesEditingIdTest`

SEC-014. `$editingId` was `?int`, not `#[Locked]`, despite being set only
server-side (`startAdding()`/`startEditing()`/`cancelEditing()`/`save()`/
`delete()`) — confirmed no blade `$set` targets it. A client
`$set('editingId', <34-digit>)` threw an uncaught `TypeError` at hydration,
confirmed live before the fix. `save()`/`delete()` already owner-scope the
lookup through `$this->user()->addresses()`, so this closed a crash, not an
IDOR — the same distinction `OrderConfirmationOrderIdTest` draws for
`$orderId`.

- `editingId` is locked against client tampering.
- `editingId` does not crash — throws the lock exception, not a
  `TypeError` — on an oversized client-set value.

### `WishlistTest`, `ProductDetailsWishlistToggleTest`, `ProductListWishlistToggleTest`

`/wishlist` and the add/remove toggle on both `ProductDetails` and
`ProductList` — closing §38's missing wishlist UI. `WishlistItem` (the
model) already existed with a full schema and was already load-bearing
(`ForceDeleteProduct` already refuses to erase a wishlisted product) but
had zero readers or writers before this.

`WishlistTest` (`/wishlist`, the listing page):

- The route requires authentication.
- Lists only the signed-in customer's own wishlist — scoping, same
  reasoning `OrderHistoryTest`/`ManageAddressesTest` give for testing it
  explicitly.
- Removes an item.
- **Cannot remove another customer's wishlist item by guessing its id** —
  `remove()` scopes through `$user->wishlistItems()->findOrFail()`, never
  `WishlistItem::findOrFail()`; asserted the same way
  `ManageAddressesTest` asserts its own cross-customer refusal, by
  confirming the scoped lookup throws rather than trusting the method
  name.

`ProductDetailsWishlistToggleTest` and `ProductListWishlistToggleTest`
(the two add/remove entry points):

- A guest sees "not wishlisted" rather than a crash.
- The first toggle adds the product; a second toggle on the same product
  removes it — proven against the real `wishlist_items` row, not just the
  component's own boolean/array state.
- A guest attempting to toggle is redirected to `/login` rather than
  crashing or silently writing a guest-owned row — `WishlistItem` has no
  guest path, same reasoning `CreateProductReview`'s own docblock gives
  for requiring a real user.
- Idempotency is a caught `UNIQUE(user_id, product_id)` violation, not a
  check-then-act read, matching `CreateProductReview`'s own discipline —
  though unlike that Action's duplicate-review case, a concurrent
  duplicate wishlist click resolves silently (nothing to report to the
  customer) rather than as a refusal, since "already wishlisted" is not a
  meaningful error the way "already reviewed" is.

The heart's *optimistic flip* (Alpine, client-side, before the round-trip)
is not covered by these — it is presentation with no server state behind
it, and `write-a-storefront-page.md`'s rule is that Blade/Alpine glue is
tested only where it proves something ours. What is ours here is the
server toggle, which these cover; the flip was verified live (measured
~8ms to fill, `wire:key` re-sync confirmed on both entry points, no
console errors).

Deliberately **not wired into checkout** or discount logic — a wishlist is
purely a saved-for-later list here, same "give it a page, not a feature
that doesn't exist yet" scoping `ManageAddresses` uses for its own
checkout non-integration.

### `HomeTest`

`/` — §4's home page, replacing the old `Route::redirect('/', '/catalogue')`.
Five independent sections, each its own `#[Computed]` property with its own
query and its own test — proving each section's *filter*, not
`ResolveProductPrice`'s or `ArticleStatus`'s own logic, which have their
own coverage elsewhere.

- **Featured** shows only `is_featured` products that are also
  `is_available` — the administrator-controlled content §4 asks for.
  `is_featured` already existed on `Product`, already editable in
  `ProductForm`'s `Toggle::make`, already rendered in the admin table and
  infolist — nothing on the storefront ever read it before this page.
- **On sale** shows only products whose discount window is actually
  active right now (not merely `discount_price` set) — same
  `whereNotNull`/`discount_starts_at`/`discount_ends_at` check
  `ProductList`'s own "On sale" filter uses, tested here independently
  since it is a second, separate query.
- **New arrivals** excludes an unavailable product.
- **Popular** shows only a product with at least one *approved* review
  (not a pending one, not zero), ordered by approved-review count
  descending — "popular" here is review volume, not a sales figure
  nothing in this schema tracks per product directly.
- **Latest articles** reuses `Article::visible()`, the same scope
  `ArticleList` uses — a home page must not leak a draft or a
  future-dated article any earlier than the journal listing itself would.
- Renders with nothing in any section, without crashing — the empty-state
  case every section's own `@if ($section->isNotEmpty())` guard exists
  for.

Each product-filter test asserts against the section's own computed
property directly, not `assertSee()`/`assertDontSee()` on the rendered
page — the page renders all five sections in one response, so a control
product correctly excluded from one section can still legitimately appear
in another, and a page-wide text assertion cannot tell those two things
apart.

### `RequestPasswordResetTest`, `ConfirmPasswordResetTest`

`/password/reset` and `/password/reset/{token}` — the last of §4–5's
missing account pages, and the most security-sensitive: no existing model
to wire up, unlike the other four. Both delegate entirely to Laravel's own
`Password` broker (`password_reset_tokens`, in the schema from the starter
kit, never previously used) rather than hand-rolled token logic.

`RequestPasswordResetTest` (step one — request a link):

- **Sends a real notification for a known email** — `Notification::fake()`
  plus `assertSentTo($user, ResetPassword::class)`, proving the actual
  broker call, not a stand-in.
- **Shows the identical success state for an email with no account, and
  sends nothing** — the account-enumeration rule `Login`'s own docblock
  states (one message for wrong-email vs. wrong-password) applies here
  too: the response can't tell a visitor whether an email exists.
- A malformed email is refused by validation before the broker is ever
  called.
- Throttled per-IP after repeated requests — keyed on IP, not the
  submitted email, same reasoning `Register`'s own throttle uses (keying
  an enumeration defence on the value being enumerated gives an attacker N
  attempts *each*).

`ConfirmPasswordResetTest` (step two — set a new password), tokens
generated via `Password::createToken()` — a real token through the same
broker `submit()` validates against, not a stand-in:

- A valid token sets the new password, **signs the visitor in**
  (`Auth::login()`, since there is no existing session to already be
  in), and **consumes the token** — a second attempt with the same token
  is proven refused via `Password::tokenExists()`.
- An invalid token is refused and changes nothing.
- A valid token submitted against the *wrong* email is refused — the
  broker matches token to email together, not the token alone.
- A mismatched password confirmation is refused by validation before ever
  reaching the broker, and **does not consume the token** — proven via
  `Password::tokenExists()` still being `true` after the refusal, since a
  validation failure must not burn the one-time link.
- **Every other session for the account is invalidated** once the reset
  succeeds — `Auth::logoutOtherDevices()`, the same response
  `ChangePassword` gives to "someone else may know the old password,"
  reached from a signed-out state instead of a signed-in one.
- The email is pre-filled from the reset link's own query string — tested
  via a real `$this->get()` route hit rather than `Livewire::test()`
  directly, since `mount()` reads `request()->query()` and
  `Livewire::test()` does not route the component through the actual
  HTTP query-string cycle a real visited URL goes through.

### `CheckoutTest` (`tests/Feature/Payment/`)

**[Added 2026-09-14]** Two courier-office-lookup rate-limit cases. The
lookup reaches a live Econt/Speedy API on every distinct city typed —
`CachedCourierGateway` only saves repeat calls for the *same* city — so an
unauthenticated visitor cycling through city names could otherwise drive
unbounded traffic at the courier. One proves the limit actually trips at
30/min/IP and stops new calls reaching the fake gateway; the other proves
a trip with nothing cached yet reads as `courierUnavailable()`, the same
amber message a genuine courier outage shows, rather than a form error —
consistent with the existing transient-failure fallback this file already
documents below. Group B1.

**[Added 2026-09-11]** Two multi-tab cases: four component instances in one
session all reaching `placeOrder` produce one order, one payment and one
reservation, and the losers refuse with a form error rather than a 500. The
guard that fires is `isEmpty()` after the winner consumed the cart, not
`UNIQUE(orders.cart_id)` — see `write-rules/order.md`, "Two actors at once".

**[Added 2026-09-11, ADR-0022]** Five cancel cases, covering both checkout
sub-states:

- Cancel at the **details** step writes nothing — `placeOrder` is the only
  thing that creates an order and it never ran, so the cart is untouched.
- Cancel at the **payment** step cancels the order and releases the stock
  it was holding, through `TransitionOrderStatus`'s own inventory effect.
- The customer gets their **basket back**: `CreateOrder` consumed the
  original cart, so `RestoreCartFromOrder` refills the visitor's current
  unspent cart. This case is what caught the resolution bug where a third
  cart row left the customer looking at an empty basket.
- The **session claim** on the cancelled order is dropped, so its
  confirmation page is no longer reachable from that session.
- An order whose **payment already landed** is refused — cancelling a paid
  order from a customer button would be a refund (staff work) and would
  release stock that was sold.


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
- A carrier is required, and an inactive one is refused even if its id is
  submitted directly. An office delivery only succeeds once `selectOffice()`
  has picked one from `offices()`; a `courier_office_code` set to a string
  that was never resolved from that list is refused on the form, and the
  carrier's `cod_fee` is added to the resolved delivery price only for cash
  on delivery. Every test in this file swaps the whole `Courier` facade for
  `FakeCourierGateway` (`tests/Pest.php`) — checkout must never reach Econt
  or Speedy over the network. See `docs/explanation/couriers.md`.
- **SEC-015.** `courier_office_name` is re-derived from the office
  `courier_office_code` resolves to, not trusted as submitted — a real,
  valid code paired with an oversized or mismatched client-supplied name
  places successfully, with the *resolved* office's name persisted, not the
  client's. Before the fix this did not crash cleanly: the resulting
  `QueryException` (the name overflowing `order_addresses`' `varchar(150)`)
  was caught by `placeOrder()`'s own domain-refusal handling and surfaced as
  a plausible-looking form error rather than a 500 — confirmed by reverting
  the fix and observing exactly that.

**A trap worth knowing.** `ResolveCurrentCart` finds a guest's cart by
`Session::getId()` and a customer's by `user_id`. A cart created any other
way is invisible to the component, which then quietly opens a second empty
one — every assertion then passes or fails against the wrong cart. The
helpers here bind the cart to whoever the test acts as, and the cart-link
test uses an owned cart because a plain `$this->get()` gets its own session.

### `OrderConfirmationOrderIdTest`

SEC-014. `$orderId` was `public int`, not `#[Locked]` — the one id-property
in the codebase that broke the "every id-property is `#[Locked]`"
convention every sibling (`CheckoutPage::$orderId`,
`ProductDetails::$productId`/`$imageIndex`, `ManageAddresses::$editingId`)
follows. A client `$set('orderId', <34-digit>)` threw an uncaught
`TypeError` at hydration, before any component code ran — confirmed live
before the fix. It is internal-only, assigned once in `mount()` and never
legitimately client-set, so `#[Locked]` closes the crash outright rather
than needing a normalising hook.

- `orderId` is locked against client tampering.
- `orderId` does not crash — throws the lock exception, not a `TypeError`
  — on an oversized client-set value.

### `CheckoutPageHydrationTest`

SEC-014. `$carrier_id` and `$selected_address_id` were `?int`, bound to
`wire:model.live` fields the client drives directly — the same incident
class as `ProductDetailsQuantityTest`'s `$quantity`: Livewire assigns the
raw client value to the typed property before any of this class's own code
runs, so a number too large for PHP to represent as an int threw an
uncaught `TypeError` at hydration, confirmed live before the fix. Widened
to `mixed`; `updated()`/`updatedSelectedAddressId()` normalise back to a
real id or `null` immediately after, and the `rules()` entry (`carrier_id`:
`integer`, `exists`) still guards what `placeOrder()` accepts.

- Setting `carrier_id` to a number too large for PHP to represent as an int
  does not crash, and normalises back to `null`.
- Setting `selected_address_id` to the same does not crash, and normalises
  back to `null`.

### `StripePaymentTest` and `StripeWebhookSecurityTest` (`tests/Feature/Payment/`)

Covered in full by `explanation/security-model.md`, "The Stripe webhook" —
including which guard does what, established by removing each in turn.
Short version: `StripeWebhookSecurityTest` is twenty-one adversarial cases
against the endpoint (including signing-secret rotation and the
`RestrictStripeWebhookIps` allow-list), validated by swapping in a
deliberately vulnerable middleware and, separately, reverting to a single
secret; `StripePaymentTest` covers intent creation, event handling, dispute
handling, and refund arithmetic, including the cumulative-versus-delta trap
and the amount-match guard that refuses to mark a payment paid for the wrong
sum.

## Admin panel (`tests/Feature/Filament/`)

### `PlainLookupMaxLengthTest`

SEC-017. Six plain-lookup resources' create forms —
`ArticleCategoryForm`, `TagForm`, `AttributeForm`, `BrandForm`,
`ProductCategoryForm`, `AttributeValueForm` — had zero `->maxLength()`
calls on any field, so a value past the underlying migration's column
length reached an `INSERT` raw and overflowed it: an uncaught
`QueryException`, confirmed live before the fix, found by the
content_editor role-scoped ZAP scan's raw access log (not its alert list —
ZAP has no rule for "this crashed the server").

- An oversized `ArticleCategory.name` (past `varchar(50)`) is refused on
  the form, not the database.
- An oversized `Tag.name` (past `varchar(30)`) is refused the same way.
- An oversized `Attribute.name` (past `varchar(50)`) is refused the same
  way.
- An oversized `Brand.name` (past `varchar(50)`) is refused the same way.
- An oversized `ProductCategory.name` (past `varchar(50)`) is refused the
  same way.
- An oversized `AttributeValue.value` (past `varchar(100)`) is refused the
  same way.
- An oversized `AttributeValue.color_hex` (past `char(7)`) is refused the
  same way.

Every case asserts `assertHasFormErrors()` and that no row was created —
`RoleForm` and `ContactMessageForm`, the only other two forms with zero
`->maxLength()` calls, were checked and are correctly exempt (every
writable field on both is either non-dehydrated or a `Textarea` over an
unbounded `text` column), so neither needed a test here.

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
- An image belonging to the variation's own product can be added to its
  gallery through `manageImages`; an image from another product is refused
  by `SetVariationImages`' `ImageNotOnProductException` rather than
  silently written — confirmed with a fresh, empty-gallery variation per
  case, since asserting against a gallery that already held the expected
  end-state would pass whether or not the refusal actually worked. The
  Select's own `options()` scoping is a UX nicety, not the guarantee: the
  refusal holds even with `options()` temporarily widened during this
  test's own development, because the write is refused server-side
  regardless of what the field would have offered.
- The gallery modal pre-fills in the pivot's stored position order, not
  ascending image id — confirmed by submitting the modal unchanged and
  checking the gallery is untouched, the same "mount with no data change"
  pattern the attribute-values edit test above uses, rather than reading
  Filament's internal Repeater state directly (its live state is keyed by
  an internal UUID per row, not by position).
- A variation can be promoted to default (`setDefault` → `SetDefaultVariation`),
  demoting whichever variation held it before.

### `ProductImagesRelationManagerTest`

New 2026-09-13. `misc/todo.md`'s Group B1 item calling this manager
"reached only indirectly through `CreateProduct`" was accurate here (unlike
`ProductVariationsRelationManager`, which already had direct coverage) —
confirmed absent before this file existed.
`ProductSpecificationsRelationManager` deliberately has no equivalent file:
it is plain default Filament CRUD with no Action and no invariant (ADR-0007),
so there is nothing "ours" to test beyond what Filament's own upstream
suite already proves.

- The single-upload form reaches `AddProductImage`, including its
  first-image-becomes-main rule.
- An image can be promoted to main (`setMain` → `SetMainProductImage`),
  demoting whichever image held it before.
- The bulk-upload closure-rule dimension validator refuses a too-small
  file and accepts a batch where every file meets the minimum — the
  validator exists specifically because Filament's built-in
  `Illuminate\Validation\Rules\Dimensions` on a `->multiple()` field
  validates every file through one nested `paths.*` Validator and surfaces
  only the first failing message with no filename attached ("one of these
  images is too small," on any number of files); the relation manager's
  own closure rule is what names the actual file, and that naming — not
  that dimension validation exists at all, which is Laravel's own,
  already proven upstream — is what these tests pin.
- A second bulk upload appends after the existing gallery's `sort_order`
  rather than restarting at 0 and interleaving with what is already there.

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

### `DomainDeleteBulkActionTest`

`App\Filament\Actions\DomainDeleteBulkAction` routes a resource's bulk
delete through its per-record delete Action instead of Filament's default
per-record `$record->delete()`. Wired into `Products`, `ProductCategories`,
`Brands`, `Attributes`, `ArticleCategories`, `Coupons`, `Carriers` as two
`BulkActionGroup` entries — `delete` (partial) and `deleteAtomic`
(all-or-nothing). Each test goes red if the resource is reverted to a plain
`DeleteBulkAction::make()`.

- **Bulk-deleting `Product`s cascades to their variations.** The default
  bulk path soft-deletes only the `products` row; the variations stay
  un-trashed and reservable, because `ReserveStock` checks the variation.
  This is the silent case — no error, just a skipped guard.
- **An in-use `Brand` is refused with a notification that names the
  dependency** ("Brand Acme has 1 product(s) and cannot be deleted"), from
  `BrandCannotBeDeletedException` via `DeleteBrand` — not Filament's generic
  "could not be deleted." Partial mode: the free brand in the same selection
  is still deleted.
- **All-or-nothing mode deletes nothing when one record is refused.** The
  whole selection runs in one transaction that is rolled back on the first
  refusal; both the in-use and the free brand remain.

Verified live in the panel before the automated coverage: both entries
render in the bulk-actions menu, the atomic modal carries its
"if any … cannot be deleted, none of them will be" description, and running
it against an in-use brand left every row intact with a
"No brand deleted — 1 in the selection blocked it" notification.

### `OrderStatusActionTest`

§37 criterion 16 ("an employee can update order statuses") had no panel
surface at all: `TransitionOrderStatus`, `OrderPolicy::updateStatus()`, and
ADR-0004's matrix were built and tested, but nothing in Filament called any
of them, and `ViewOrder`'s only header action was an `EditAction` pointing
at a route `OrderResource::getPages()` never registers — it rendered, and
could not resolve.

- An order advances through the panel and the write lands via
  `TransitionOrderStatus`, not a `->status` assignment.
- The §19 history row is written, carrying `previous_status`, `new_status`,
  and the reason typed into the modal.
- A warehouse employee is not offered **Cancel** on an order that offers it
  to an administrator — the same order, the same menu, two roles. This is
  ADR-0011's split reaching the UI: `OrderPolicy::updateStatus()` routes by
  *target* status, so the menu authorizes per button rather than once.
- Only transitions `OrderStatus::allowedTransitions()` permits are
  rendered — `Delivered` offers `Returned` and not `Preparing`.
- The same menu works from the table row, not only the view page.

These assert the menu. That an actor calling a transition they may not make
is refused anyway is the Action's territory, covered by
`TransitionOrderStatusTest`'s "denies cancel_order-less actor a cancellation
despite holding updateStatus_order" — a hidden button is not security
(`CLAUDE.md`), and this file does not restate the check that makes it so.

### `ShipmentResourceTest`

§37 criterion 15 ("a shipment can be created from an order").
`CreateShipment` and `TransitionShipmentStatus` were built and tested with
no panel surface at all — the same shape the inventory gap had.

- `ShipmentResource` (`admin/shipments`) is reachable by
  `warehouse_employee` and forbidden to `content_editor`.
- A shipment is created **from an order's own page**, reaching
  `CreateShipment` — the criterion's actual wording, and why creation is not
  a blank form on `ShipmentResource`: §28 refuses a shipment for a
  cancelled, unpaid, or already-shipped order, so a standalone form would
  invite picking an order the Action then refuses.
- §28's refusal reaches the user as a notification rather than a 500 —
  `ShipmentNotAllowedException` is an `App\Exceptions` `RuntimeException`,
  so `ReportsDomainFailures` converts it. The refusal *rules* are
  `CreateShipmentTest`'s; what this proves is that the panel calls the
  Action at all.
- The create action disappears once an order already has a shipment.
- A shipment advances through the panel via `TransitionShipmentStatus`, and
  only the transitions `ShipmentStatus`'s matrix allows are offered.

`ShipmentFactory` randomises `status`, `shipped_at`, and `delivered_at`, so
every case pins what it asserts against — the trap
`how-to/troubleshooting/data-and-factories.md` documents for the product
factories applies here too.

### `ReturnResourceTest`

The panel side of the 14-day right of withdrawal (`ReturnResource`,
`admin/returns`, ADR-0020).

- Reachable by `administrator`; **forbidden to `content_editor` and
  `warehouse_employee`** (`return` is administrator-only for now).
- **Approve** and **Deny** on a `Requested` return reach `ReviewReturn` and
  move it to `Approved` / `Denied`.
- **Refund** on an `Approved` return reaches `RefundReturn` — the Stripe
  refund is faked (the `getService('refunds')` mock, `StripePaymentTest`'s
  approach), and the return lands at `Refunded`.
- The three actions are offered only for the status that makes them legal —
  `Requested` shows Approve/Deny not Refund; `Refunded` shows none.

`deliveredOrderForReturn()` (in `tests/Pest.php`) builds the fixture: a
delivered order with a `Delivered` status-history row (for
`Order::deliveredAt()`), one line per variation with `inventories.sold_quantity`
set so `RestockReturn` has stock to credit back.

### `UserResourceTest`

`UserPolicy`'s docblock had named this gap and deferred it: assigning a role
is how an account gains panel access, so gating it by `update_user` would
let anyone who may edit a user promote themselves to administrator. It is
now its own ability, `assignRole_user`.

- `UserResource` (`admin/users`) is reachable by `administrator` and
  forbidden to both other staff roles.
- An administrator can assign a staff role.
- An actor holding `update_user` but **not** `assignRole_user` has a
  submitted `roles` key stripped — while the rest of their edit still
  saves.
- An administrator changing their **own** roles has it stripped too.
- No delete action: removing an account orphans its orders and §19 requires
  that history to survive. `is_active` is the reversible path.

**Why the denial cases call `mutateFormDataBeforeSave` directly** rather
than `fillForm()->call('save')`: the form *disables* the roles field for
exactly those actors, so a `fillForm` submission never carries `roles` at
all — and a test written that way passes with the server-side guard
deleted. It did, until the guard was removed to check. These call the seam a
crafted Livewire payload actually reaches, which is the half that has to
hold; a disabled control is not security (`CLAUDE.md`).

**The `Gate::before` trap, found while building this.** The self-assignment
rule was first written into `UserPolicy::assignRole()` as
`&& $model->id !== $user->id` — dead code, because `Gate::before`
short-circuits every check for an administrator, the only role holding
`assignRole_user`. The test caught it. The rule now lives in `EditUser`,
where `Gate::before` cannot reach; `permissions.md`'s "Where each check
lives" states the general form.

### `InventoryResourceTest`

Regression guard for a permission-vs-panel-access gap, not a race or a write
outcome: `warehouse_employee` held `update_inventory`, and the only UI for
`AdjustStock` (`ProductVariationsRelationManager`, nested under
`ProductResource`) was gated by `viewAny_product`, which the role's
permission set never grants — the permission was real, nothing in the panel
structure could deliver it. `reference/permissions.md`'s `warehouse_employee`
section and `changelog/CHANGELOG.md` have the finding; this is the test that
would have caught it, and now guards against it recurring.

- `InventoryResource` (`admin/inventories`) is reachable by
  `warehouse_employee` and `administrator`, and returns 403 for
  `content_editor` — the exact matrix `permissions.md` states for the
  `inventory` resource.
- Recording a delivery through the panel reaches `AdjustStock` for real —
  `current_quantity` moves and the ledger gets a `NewDelivery` row with the
  submitted note, not just a modal that renders.
- Removing more than is available through the panel is refused the same way
  the Action refuses it directly (a plain `InvalidArgumentException`,
  uncaught by `ReportsDomainFailures` since it is not `App\Exceptions\*` —
  a caller bug per the Action's own docblock, not a customer-facing
  refusal) — asserted by the quantity staying untouched.
- Recording damage through the panel reaches `RecordDamage` for real —
  `damaged_quantity` rises and `current_quantity` falls by the same amount,
  with a `DamagedProduct` ledger row.

### `ProductReviewResourceTest`

Had no test at all until 2026-09-06, found during a QA-gap audit rather than
from a specific bug report — checked for a class reference to
`ApproveProductReview`/`UnapproveProductReview` anywhere in `tests/` and
found none, despite both having a live panel row action and a bulk action on
`ProductReviewsTable`.

- `ProductReviewResource` (`admin/product-reviews`) is reachable by
  `administrator` only, and returns 403 for `content_editor` and
  `warehouse_employee` — the matrix §24 states (moderation is an
  administrator ability; `content_editor` briefly held it and had it removed
  once §3.3 was read against §24 — `permissions.md` has the account).
- Approving and unapproving a review through the panel row action reach
  `ApproveProductReview`/`UnapproveProductReview` for real, not just a modal
  closing — `approved` flips on the underlying row.
- The bulk "approve" action was checked specifically against the
  `DeleteBulkAction`-bypasses-the-single-item-guard bug class confirmed
  elsewhere in this codebase (`Products`, `ProductCategories`): it composes
  `ApproveProductReview` once per selected record rather than writing
  `approved = true` directly, so authorization and the Action's own logic
  both still apply per record. Confirmed correct, not a bug — worth
  recording precisely because the same-shaped bug existed on other
  resources and this one could easily have repeated it. Those other
  resources are now fixed too — see `DomainDeleteBulkActionTest` above.

### `ContactMessageResourceTest`

Added 2026-09-13 with `ContactMessageReceived`. The email's button lands on
`ViewContactMessage`, so this pins the shortcut that email promises.

- The view page, fetched as `administrator`, renders **Mark handled**.
- Calling `markHandled` on an outstanding message sets `handled_at` and sends
  the "Marked handled" notification.
- The action is hidden on a message that is already handled.
- A staff account granted `viewAny`/`view_contact_message` but not
  `update_contact_message` sees the page without the action, and
  `handled_at` stays null — `->authorize('update')` is what hides it, not
  the role's lack of panel access.
