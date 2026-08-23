# Open schema and modelling questions

Decisions deferred rather than made, each with what is true today, what the
alternative costs, and what would trigger revisiting. Raised 2026-08-23 while
reviewing the schema before authoring demo fixtures.

Items resolved on 2026-08-23 have been removed rather than marked done — the
decision now lives in the code and in `CHANGELOG.md`. Removed: currency
modelling, dimensions, weight units, cart TTL, review eligibility,
`products.is_available`, and the superseded `dimensions`/`weight` columns
(dropped once every reader was confirmed migrated to `*_mm`/`weight_g`).

Nothing here is a defect in the sense of producing wrong output now. Each
says which feature would turn it into one.

## 1. Multi-language has installed packages and no storage shape

**Today.** `astrotomic/laravel-translatable` is in `composer.json` and unused
— `tech-stack-overview.md`'s "Installed, not wired in" section says so. No
table has a locale column, and no translation table exists. The changelog's
`Open` section has carried "content translation storage shape and default
locale" as undecided since the schema was drawn.

**The decision that is actually open** is not "should we support it" but
which of two shapes:

- *Translation tables* (`product_translations` keyed by
  `(product_id, locale)`), which is what `laravel-translatable` expects.
  Clean queries, one join, straightforward fallback.
- *JSON columns* (`name` as `{"en": "...", "bg": "..."}`), which Laravel
  supports natively and which needs no join, but makes an index on a
  translated field awkward and a `LIKE` search worse.

Either is a large migration touching `products`, `product_categories`,
`brands`, `attributes`, `attribute_values`, `articles`, and
`article_categories`.

**Recommendation: leave it, and do not half-adopt it.** Adding a `locale`
column somewhere "to be ready" produces a schema that is neither
single-language nor translatable. §38 lists multiple languages as optional
and out of scope.

**Trigger.** A decision to bring §38's translation item into scope. It is an
ADR, not a migration, first.

## 2. Catalogue prices carry no currency

**Resolved in part, still open in part.** `App\Enums\Currency` now exists and
`orders.currency`/`payments.currency` snapshot what an order was placed in.
What remains open is the catalogue side: `products.regular_price`,
`discount_price`, `product_variations.price`, coupon amounts and
`shipments.cod_amount` are bare decimals with no currency.

That is coherent while the catalogue is single-currency, and adding a
`currency` column to `products` would be the *wrong* shape for real
multi-currency — a price is not one number in several currencies, it is
several numbers, each independently set by a merchandiser and independently
rounded. That is a `product_prices` table keyed by `(product_id, currency)`,
plus the same for variations, plus a decision about whether an unpriced
currency falls back to a converted rate or hides the product.

**Recommendation: leave it.** `Currency` exists so the vocabulary is stated;
the storage shape waits for a real second currency.

**Trigger.** §38 lists multiple currencies as optional and out of scope.
Revisit only if that changes.

## 3. Reviews have no guest path

**Today.** `product_reviews.user_id` is nullable, but
`CreateProductReview` requires a user, because §24's verified-purchase rule
has nothing to check against without one and `UNIQUE(user_id, product_id)`
does not constrain NULLs in MySQL — a guest could review the same product
indefinitely.

**Trigger.** Only if guest reviews are wanted. Needs its own rule and ADR.

## 4. Payment and shipment integration columns are unfilled

**Today.** `payments.stripe_payment_intent_id`,
`stripe_checkout_session_id`, `shipments.tracking_number`, `label_path`,
`courier_tracking_url`, `shipment_number` are all nullable and nothing
writes them. That is deliberate — it is what let the domain Actions
(`RecordPayment`, `CreateShipment`, and both transition Actions) be built
without Stripe or a courier API.

**Trigger.** Slices 6 and 8. The columns are already the right shape.

## 5. `carts.session_id` has no writer

**Today.** `TouchCartExpiry` now sets `expires_at` from `user_id`, and
`carts:expire` deletes what has passed — the TTL half is done. But nothing
writes `carts.session_id`, so a guest cart cannot actually be *found* again
on a later request.

This is not a schema question; the column and its index are right. It is
storefront work: the session id is written when a guest cart is created,
which is code that does not exist because no public route does.

**Trigger.** When the storefront cart is built. Until then a guest cart is
only reachable by primary key, which is fine for seeding and tests.
