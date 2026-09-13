# How money is handled

CLAUDE.md forbids float for money outright. This page is the "how" behind
that rule: `App\Support\Money`, the only place `bc*` is called, and why raw
`bcadd`/`bccomp`/etc. calls elsewhere are a smell, not a style choice.

## Columns, casts, and the object are three different layers

- **Column**: `decimal(10,2)`, on every money-carrying table. This is what
  makes the database itself refuse a value floating-point error could
  produce.
- **Cast**: `decimal:2` on the Eloquent attribute. This is what makes a model
  attribute round-trip as a fixed-precision string instead of PHP silently
  widening it to float on read.
- **Arithmetic**: `App\Support\Money`, wrapping the string a `decimal:2` cast
  already produced. Casts and columns don't disappear — `Money` doesn't
  replace either one, it's what a caller reaches for once a value needs to be
  added, split, or compared rather than merely stored.

A caller never calls `bcadd`/`bcsub`/`bcmul`/`bcdiv`/`bccomp` directly.
`Money::of()` wraps a cast attribute or a decimal string from a fixture or
request; every operation after that is a method call.

## Why a wrapper and not disciplined `bc*` calls

Before this class, the rule was enforced by discipline alone: 30 `bc*` calls
across 6 files, each repeating the scale, each an opportunity to omit it.
`bcadd($a, $b)` without an explicit scale defaults to **0** and turns
`189.90` into `189` — a silent truncation, not an error. `Money::SCALE` is
internal so a caller can't pick the wrong one, or forget to pick one at all.

## Two methods carry the calculations whose precision isn't obvious

- **`percentageOf(string $rate)`** — VAT extraction. Prices are stored gross
  (BG B2C convention), so the VAT portion already included in a price is
  `amount * rate / (100 + rate)`, **not** `/ 100`. Composing `multiply()` and
  a plain divide at the call site gets this backwards easily and nothing
  fails loudly when it does — every line just overstates VAT.
- **`shareOf(self $pool, self $total)`** — proportional allocation, used to
  split a discount across the cart lines it matched. A line worth 30 of a
  100 matched subtotal takes 30% of the discount pool.

Both run their intermediate arithmetic at double scale (`SCALE * 2`) and
round once at the end, inside the method — rounding each step along the way
compounds the drift across a multi-line cart; see `MoneyTest`'s
"sum stays 9.99 not 10.00" case for what rounding-per-line would produce
instead.

## Two conversions at the API boundary, and one display-only calculation

- **`toMinorUnits()` / `fromMinorUnits(int $minor)`** — Stripe's API takes and
  reports amounts as an integer count of the currency's smallest unit (cents
  for EUR), never a decimal; these are the one place that conversion happens,
  in both directions, rather than a caller doing `bcmul`/`bcdiv` with a
  hand-written scale at the call site. Both assume a two-decimal currency, the
  same assumption `SCALE` makes everywhere else — a zero-decimal currency
  (JPY) or three-decimal one (KWD) would need the exponent to come from the
  currency rather than be assumed 2 (`schema/open-schema-questions.md` #2).
- **`percentBelow(self $original)`** — how many whole percent lower one
  amount is than another, for a discount label like "20% off." Returns an
  `int`, not a `Money`: unlike `percentageOf()`/`shareOf()`, which extract or
  allocate a monetary amount, this produces a display percentage from two
  amounts. Added when `ProductPrice::percentOff()`'s own raw `bcsub`/`bcmul`/
  `bcdiv`/`bccomp` chain was found during the `.semgrep.yml` rollout
  (2026-09-13) — the semgrep rule for "no raw `bc*` outside `Money`" is only
  honest if `Money` actually offers what every real call site needs, so the
  method moved here rather than the file being excluded from the rule.

## Deliberately not currency-aware

`App\Enums\Currency` exists and `orders`/`payments` snapshot it, but the
catalogue itself is single-currency, and adding a currency field to `Money`
now would imply mixed-currency arithmetic is guarded when it isn't.
`reference/schema/open-schema-questions.md` #2 has what real multi-currency
needs; the guard belongs on `Money::add()`/`subtract()` (refusing mismatched
currencies) when that arrives, not before.

## Where it's used

`CalculateCartTotals`, `CalculateCouponDiscount` (and its `CouponDiscountLine`
result), `CreateOrder`, `TransitionPaymentStatus`, and `ValidateFixtures` —
the 6 files the original 30 `bc*` call sites lived in. `ProductPrice`
(`percentOff()`) and `HandleStripeWebhookEvent` (`refundedTotalFrom()`)
joined this list 2026-09-13, moving off raw `bc*` for the same reason as the
original 6. `MoneyTest` (unit, no database) covers the object itself;
`CalculateCartTotals`'s and `CalculateCouponDiscount`'s own test suites cover
it indirectly by staying green across the migration from raw `bc*` calls.

## Enforced mechanically, not just by convention

`.semgrep.yml` (repository root) has a rule — `raw-bcmath-outside-money` —
that fails CI on any `bc*` call outside `app/Support/Money.php`, the one file
excluded. The same `.semgrep.yml` carries three other rules for unrelated
invariants (unscoped `Order::findOrFail()`, `$request->all()`, a direct
`->status =` on an `Order`), each with its own narrow exclusion where a
legitimate exception exists — `RaceWorker.php` and
`TransitionOrderStatus.php` among them, but for those other rules, not this
one. `docs/how-to/use-ci.md`'s `lint` job section has the full CI step.
