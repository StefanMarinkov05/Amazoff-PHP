# Cart writes — expected behaviour

What happens for every write against a cart and its lines, alone, after the
catalogue changes underneath a line, and under concurrency. Facts as of
2026-08-17, measured against the running stack rather than read off the code.

Why the mechanisms differ is `explanation/concurrency-and-locking.md`. This
page is the outcomes, in the same shape as its sibling, `reference/write-rules/product.md`.

## What enforces any of this

The Actions in `app/Actions/Cart`, and nothing else. Direct Eloquent, a
factory, a seeder, or a raw query builder all bypass every rule below.

**No storefront controller or Livewire component calls these Actions yet.**
They are exercised only by tests.

## One actor at a time

| Action | Refused when | Exception |
|---|---|---|
| `AddToCart` | variation or its product is gone (soft-deleted, or never existed) | `RemovedFromCatalogueException` |
| | product or variation is deactivated (`is_available = false`) | `RemovedFromCatalogueException` |
| | requested quantity is below 1 | `InvalidCartQuantityException` |
| | resulting quantity (existing line + requested) is below `min_order_quantity` | `InvalidCartQuantityException` |
| | resulting quantity exceeds `available()` | `InsufficientStockException` |
| `UpdateCartItemQuantity` | same five checks as `AddToCart`, against the requested quantity directly rather than a sum | same four exception types |
| `MergeGuestCart` | — never refuses; see "A line after the catalogue changes underneath it" | — |
| `RemoveFromCart` | — never refuses | — |

`UpdateCartItemQuantity` reads its variation `withTrashed()`, but there is no
reachable path where the row is actually gone: `ForceDeleteProductVariation`
deletes every `cart_items` row pointing at a variation before erasing it, so
a live `CartItem` can point at a soft-deleted variation but never at a
missing one. `firstOrFail()` on the variation lookup is therefore dead code
for `ModelNotFoundException`, not a gap.

Neither Action authorizes anything — a customer editing their own cart holds
no permission to check.

## A line after the catalogue changes underneath it

`AddToCart` and `UpdateCartItemQuantity` validate only the line they are
about to write. Nothing scans a cart when a product changes, and nothing
purges a line on a schedule — the check happens on the next write to that
specific line, or never, if the customer does not touch it.

| Change | An untouched existing line | The next write to that line | Evidence |
|---|---|---|---|
| Product or variation deactivated | left alone, still priced and totaled | refused (`RemovedFromCatalogueException`), whatever value is submitted | `AddToCartTest`, `UpdateCartItemQuantityTest` |
| Variation soft-deleted | left alone in `cart_items`; excluded from `CalculateCartTotals`'s subtotal rather than crashing | refused (`RemovedFromCatalogueException`) | `UpdateCartItemQuantityTest`, `CalculateCartTotalsTest` |
| Product soft-deleted (variation untouched) | same as above | refused (`RemovedFromCatalogueException`) | `UpdateCartItemQuantityTest`, `CalculateCartTotalsTest` |
| Stock drops below the line's quantity | left alone, still priced at the full quantity | refused if raised or resubmitted (`InsufficientStockException`); **succeeds if lowered** to at or below the new `available()` | `UpdateCartItemQuantityTest` |
| `min_order_quantity` rises above the line's quantity | left alone | refused if resubmitted (`InvalidCartQuantityException`); **succeeds if raised** to meet the new minimum | `UpdateCartItemQuantityTest` |
| Price or discount window changes | left alone — there is nothing to invalidate, since no price is stored on the line | not applicable — the next render of `CalculateCartTotals` prices at the current value automatically | `CalculateCartTotalsTest` |

`RemoveFromCart` is the only way to clear a line in the first four rows;
`UpdateCartItemQuantity` is what fixes the last two, by moving the quantity
to the side of the new threshold that is legal again.

`MergeGuestCart` shares the "left alone" half of this table but not the
"refused on write" half — it revalidates nothing at all. A guest line that
exceeds stock, falls below the minimum, or points at a soft-deleted variation
merges exactly as stored; `CreateOrder` is what raises it, at checkout, not
this Action. Measured in `MergeGuestCartTest`: "merges a quantity that
exceeds available stock", "merges a quantity below the product minimum",
"merges lines whose variation has since been soft-deleted".

## When a merge happens, and the ordering it depends on

`MergeGuestCart` is called from exactly two places — `Login::login()` and
`Register::register()` — through `App\Support\MergeCartOnAuthentication`,
which exists to make one ordering explicit at both call sites.

**The guest cart must be read before `session()->regenerate()`.** A guest
cart is keyed on `session_id`; both call sites regenerate the session
immediately after authenticating, and must (the pre-login id is what a
fixation attack plants). Regeneration issues a new id with nothing carrying
the old one forward, so a lookup afterwards matches no cart, finds nothing,
and reports success — the customer silently loses their basket.

That is not hypothetical: it is what the storefront did until 2026-09-04,
when the Action had been built, tested (including two concurrency tests) and
never called. Confirmed live before the fix — an item added as a guest, a
sign-in, an empty basket, and an orphaned `carts` row.

`MergeCartOnAuthentication` therefore splits into `capture()` (before) and
`apply()` (after). `CartMergeOnAuthenticationTest` pins the ordering: moving
the capture after `session()->regenerate()` turns **4 of its 8 cases** red —
the four that involve a guest basket — while the four that do not correctly
stay green.

Two further properties it holds:

- **A failed merge never fails the login.** Someone who has proved their
  identity is signed in even if the basket cannot be folded in; the
  alternative is an account locked out by a cart bug. `apply()` re-reads the
  captured row and returns quietly if it has since been deleted — the
  reachable case being a second tab finishing its own merge first.
- **The surviving cart's guest expiry is cleared**, inside the merge
  transaction. It now belongs to a user, and `ExpireCarts` would otherwise
  delete a registered customer's basket a day later.

## Two actors at once

Every race found in this namespace has the same shape, and it is the
opposite of Catalogue's: **both attempts succeed.** Nothing here is mutual
exclusion — the contested resource is `UNIQUE(cart_id, product_variation_id)`,
and the fix is CLAUDE.md's idempotency rule (catch the violation, retry as an
update), not a lock. Contrast with `reference/write-rules/product.md`'s "exactly one
wins" shape before assuming the same pattern applies here.

| Race | Outcome | Evidence |
|---|---|---|
| Two simultaneous `AddToCart` calls, same cart and variation | both succeed; quantity sums to 2, not 1 lost | `AddToCartConcurrencyTest` |
| Two simultaneous `MergeGuestCart` calls, same guest cart | both succeed; quantity sums to 2; guest cart does not survive either | `MergeGuestCartConcurrencyTest` |
| A direct `AddToCart` racing a `MergeGuestCart` of the same variation into the same cart | both succeed; quantity sums to 2 | `AddToCartVsMergeGuestCartConcurrencyTest` — proves `AddToCart`'s retry only; see "Known gaps" |

`AddToCartVsMergeGuestCartConcurrencyTest` races the 2 Actions directly
against each other, six times per run, with a file-flag rendezvous on top of
the usual wall-clock barrier so process-boot jitter isn't deciding the
outcome. Both fold cleanly every time. What it could not do, across 24
attempts under three synchronization strategies, is make `MergeGuestCart`
lose even once — see "Known gaps" for why, and for what that does and does
not mean.

## Lock order

None. `ReserveStock`/`ReleaseStock` are the only Cart-adjacent Actions that
take a lock, and they act on `inventories`, not on anything in this
namespace. No Action here calls `lockForUpdate()`.

## Known gaps

**1. `MergeGuestCart`'s retry is unverified in the add-vs-merge pairing
specifically** — not for lack of trying. `AddToCartVsMergeGuestCartConcurrencyTest`
races the 2 Actions directly, repeatedly, with tighter synchronization
than the wall-clock barrier alone provides, and `MergeGuestCart` won every
attempt. Its `applyLine()` does no domain validation before the insert;
`AddToCart` checks `min_order_quantity` and `available()` first — a real,
measured difference in code-path length, not noise. That means a direct add
racing a merge into the same cart never actually makes `MergeGuestCart` the
loser here, only `AddToCart` (which the same test does prove, incidentally,
via the same 24 attempts). `MergeGuestCart`'s retry is still proven
independently by `MergeGuestCartConcurrencyTest` (merge racing merge, where
both sides take the identical path and either can lose) — what's missing is
proof that it *also* saves it against a faster-arriving `AddToCart`. Whether
that gap is worth chasing further with a different technique (e.g. a merge
carrying more lines, to lengthen its path to parity) is undecided.

**2. No upper bound on `quantity`.** Not exploitable today — the stock check
refuses any unrealistic value before a write, and no Form Request exposes
these Actions to HTTP input yet — but `cart_items.quantity` and
`inventories.*_quantity` are plain `integer()` (MySQL `INT`, ~2.1B max)
while PHP's `int` is 64-bit. Belongs on the future cart Form Request as a
`max:` rule, not on the Action.

**3. Nothing enforces any of this outside the Actions,** and no storefront
code calls them yet.
