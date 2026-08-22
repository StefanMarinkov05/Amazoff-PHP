# Product category writes — expected behaviour

What happens deleting a category, alone and under concurrency. Everything
else about `ProductCategory` — create, update, `parent_id` reassignment — is
default Filament CRUD, per CLAUDE.md's plain-lookup-table exemption, and has
no rules of its own to record here.

Facts as of 2026-08-22, measured against the running stack rather than read
off the code.

## What enforces any of this

`App\Actions\Catalogue\DeleteProductCategory`, and nothing else. Direct
Eloquent, a factory, a seeder, or a raw query builder all bypass the refusal
below — though `parent_id` and `product_category_id`'s own foreign keys
(both `constrained()`, no cascade) still refuse the write at the database
regardless of which path reaches them, just without a message naming why.

## One actor at a time

| Refused when | Exception |
|---|---|
| The category has one or more subcategories | `ProductCategoryCannotBeDeletedException::hasChildren()` |
| The category has one or more products | `ProductCategoryCannotBeDeletedException::hasProducts()` |

Children checked before products — no significance to the order beyond
matching the tree structure's own logical precedence. Both checks run
inside one transaction, under `lockForUpdate()` on the category row, so a
subcategory or product attached in the same instant is either already
visible to the count or blocks behind the lock until it resolves.

Before this Action existed, the equivalent check lived in
`ProductCategoriesTable` as a `visible()`/`disabled()` Filament closure,
read once when the row rendered rather than when the click landed, and its
`children()->count()` half was commented out — `ProductCategory` had no
`children()` relation to call. `ProductCategoryPolicy::delete()`'s own
docblock had already named this as belonging in an Action rather than a
render-time closure.

## What gets written

Nothing beyond the row itself: `DeleteProductCategory` either deletes
`product_categories` in full or writes nothing at all. `ProductCategory`
does not soft-delete.

## Two actors at once

| Race | Outcome | Evidence |
|---|---|---|
| A category deleted while a subcategory is created underneath it | exactly one side succeeds; the loser fails cleanly — `ProductCategoryCannotBeDeletedException` if the delete lost, a raw `QueryException` if the create lost, since nothing wraps a plain category insert | `DeleteProductCategoryConcurrencyTest` |

The two sides are not symmetric, and the test says so rather than assuming
otherwise. The "create" side is plain Eloquent, matching what Filament's
default create form does for a lookup table — no Action, no domain
exception to lose to, only `parent_id`'s foreign key directly. Measured
during construction: without a tight rendezvous on top of the usual
wall-clock barrier, the create side won every single time — boot jitter
alone was deciding it, the same trap `AddToCartVsMergeGuestCartConcurrencyTest`
names for a different pairing. With the rendezvous both sides win a real
share, so both failure shapes above are genuinely exercised, not merely
theoretical.

## Known gaps

**1. Deletion-proofing could not force the one case that would prove the
lock's own necessity.** Removing `lockForUpdate()` from `DeleteProductCategory`
and re-running the race — fourteen attempts, with the rendezvous — never
once produced the failure that would demonstrate it: the delete side losing
via a raw `QueryException` instead of the clean domain exception. That
specific window (a concurrent insert committing strictly between the
delete's own unlocked read and its `DELETE` statement) is narrower than
this harness can reliably hit — the same conclusion reached for the
deadlock-prevention sort in `write-rules/order.md`'s known gaps. The lock
stays regardless, for the same reason `ReserveStock`'s does: it decides how
a loser fails, not whether the database stays coherent, and the foreign key
alone only guarantees the latter.

**2. No cycle prevention on `parent_id` reassignment.** Nothing stops a
category being set as its own ancestor — sequentially, by one administrator,
with no concurrency required at all. MySQL cannot express "this
self-referencing foreign key must not cycle." Not closed here: this page
covers deletion, which is what has an Action; reassignment is still default
Filament CRUD, and closing this needs either a validation rule or an Action
of its own, whichever is decided first.
