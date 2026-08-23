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
the 6 files the original 30 `bc*` call sites lived in. `MoneyTest` (unit, no
database) covers the object itself; `CalculateCartTotals`'s and
`CalculateCouponDiscount`'s own test suites cover it indirectly by staying
green across the migration from raw `bc*` calls.
