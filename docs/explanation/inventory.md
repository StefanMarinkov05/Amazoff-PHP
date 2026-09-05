# How stock works

§20 of the specification. What the numbers mean, why one of them is not
stored, and what has been built so far.

The locking that protects these rows is described in
`concurrency-and-locking.md`; this page is the domain rather than the
mechanism.

## One row per variation

Stock hangs off `product_variations`, not `products`. The sellable thing is
"Red, size M", not "T-shirt", so a count on the product would have no single
answer. `inventories` carries `UNIQUE(product_variation_id)` to make the
one-to-one binding structural rather than conventional — ADR-0002 records why
every sellable product has at least 1 variation even when there is nothing
to vary.

## Five counters and one derivation

| Column | Meaning |
|---|---|
| `current_quantity` | Physically present now |
| `reserved_quantity` | Physically present, promised to orders that have not completed |
| `sold_quantity` | Lifetime total shipped |
| `returned_quantity` | Lifetime total returned by customers |
| `damaged_quantity` | Lifetime total written off |

```
available = current_quantity − reserved_quantity
```

`available` is what the shop may sell, and it is a method on the model rather
than a column. §20 requires it derived: a stored copy is a second source of
truth, and it goes wrong the first time a reservation is written by code that
does not know to update it. The cost is that availability cannot be filtered
on directly in SQL — a query for in-stock products compares the two columns
instead.

The first two counters describe the present and move in both directions. The
last three are lifetime totals and only ever grow; they are history, not state.

## Reserved is not sold

A reservation makes stock unsellable without removing it. Ten on the shelf
with three reserved is seven available and still ten physically present,
because the three are promised rather than gone. They leave `current` when the
order ships, at which point they arrive in `sold`.

§20 lists when a reservation ends:

- the order is paid and fulfilled — the stock is sold
- the payment fails
- the payment session expires
- the order is cancelled, by the customer or by an administrator

The last three release the reservation, returning the quantity to available
without it ever having been sold.

## Movements are the ledger

`inventories` holds a balance. `inventory_movements` holds how it got there,
append-only, one row per change:

```
+15  initial_stock        seeded, no actor
 −4  completed_sale       webhook, no actor
 −1  damaged_product      Wesley, "dropped in transit"
 +1  order_reservation    checkout, no actor
```

§20 forbids changing a quantity without recording a movement, which is why
`RecordInventoryMovement` opens no transaction of its own — a movement without
the quantity change it describes, or the reverse, is a lie, so the caller owns
the boundary and the movement joins it.

Quantities are signed. A release records `−n` rather than a positive
`reservation_release`, because the ledger records the direction of a change
rather than the size of an event; a column where every row is positive cannot
be summed to reconstruct anything.

`created_by_id` is nullable, and null means the application acted on its own
behalf — a webhook, the scheduler, a queued job. ADR-0007 covers why that
nullability runs all the way up through the Actions.

The eight movement types are fixed by §20 and live in
`App\Enums\InventoryMovementType`. They classify rather than transition, so
the enum carries no transition matrix; ADR-0004 explains which of the 12
enums do.

## What exists

`Inventory::available()`, `RecordInventoryMovement`, `ReserveStock`,
`ReleaseStock`, `CompleteSale`, `RestockReturn`, and `RecordDamage` — six of
§20's eight movement types, missing only manual correction. All six
quantity-writing Actions are covered by feature tests; the first four also by
a concurrency suite.

`ReserveStock` is called by `CreateOrder`, at order creation, for every
payment method — COD included, per CLAUDE.md's "reserve stock on
confirmation" read as a conservative superset of "reserve at creation is
never later." `ReleaseStock`, `CompleteSale`, and `RestockReturn` are called
by `TransitionOrderStatus`, keyed by the order's target status:
`=> Cancelled` releases, `=> Shipped` completes the sale (`reserved_quantity`
decremented before `current_quantity` — the one ordering that matters and
that no static check catches, since decrementing `current` first can violate
`chk_inventories_reserved_not_above_current` mid-transaction when a sale
empties fully-reserved stock), `=> Returned` restocks. `RestockReturn`
assumes the return is resellable and credits `current_quantity` directly.

`RecordDamage` is general-purpose rather than composed by
`TransitionOrderStatus` — a warehouse employee marking N shelf units damaged
is independent of any specific order, the same shape as `ReserveStock`/
`ReleaseStock`. It moves `current_quantity` to `damaged_quantity` and guards
`available()` (current minus reserved) rather than `current_quantity` alone:
damaging reserved stock would push `reserved_quantity` above
`current_quantity`, the same `CHECK` constraint `CompleteSale`'s ordering
respects, and silently allowing it would leave a reservation pointing at
stock that no longer exists. A damaged *return* is `RestockReturn` followed
by a separate `RecordDamage` call once inspection finds it unsellable — two
ledger rows, not a branch inside 1 Action (ADR-0011). `RecordDamage` throws
`InsufficientStockToDamageException` rather than `InvalidArgumentException`
for exceeding `available()` — the one guard among this file's Actions
reached directly from a quantity a warehouse employee types into the panel,
where exceeding available stock is a mistake to correct rather than a caller
bug (ADR-0007); its three siblings above only ever receive a quantity
computed by another Action, so their own equivalent guard stays
`InvalidArgumentException`. Both `RecordDamage` and the manual-correction
movement (`AdjustStock`) have admin surfaces: `RecordDamage` only in
`ViewInventory`'s "Record damage" action; `AdjustStock` in both
`ViewInventory` and `ProductVariationsRelationManager`.
