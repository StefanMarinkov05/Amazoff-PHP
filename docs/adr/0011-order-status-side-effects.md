# ADR-0011: Side effects of an order status transition

Status: Accepted
Date: 2026-08-17 · Deciders: Stefan Marinkov

## Context

ADR-0004 put enforcement of an order status change in `TransitionOrderStatus`
and said what it does: check the enum, check the policy, write the status,
record the §19 history row, dispatch events — all inside `DB::transaction`.
It did not say what happens to inventory, and its own Context paragraph offers
an example in passing: "`CancelOrder` is `TransitionOrderStatus` plus
`ReleaseStock`." That sentence is illustrative, in ADR-0004's Context, not
binding — its Decision section only requires that legality, authorization, and
recording each have exactly one home. Which Action owns the inventory
consequence was left open.

Building `TransitionOrderStatus` turned up two more consequences ADR-0004
never anticipated, because at the time nothing wrote `orders.status` at all
and the question of what happens *next* had no example to reason about:

**Cancellation is not the only transition with a stock consequence.** §20
requires release on cancellation, but `inventories.sold_quantity` and
`InventoryMovementType::CompletedSale` have existed since the schema was
drawn and nothing has ever written to either. The moment an order reaches
`Shipped`, its stock is still sitting in `reserved` — there is no Action that
moves it to `sold`. `Returned` has the same shape in reverse:
`CustomerReturn` and `returned_quantity` exist, unused. Three transitions
carry an inventory effect, not one, and they are the same shape: move a
quantity from one counter to another, write a ledger row.

**A wrapper only protects a caller who remembers to use it.** If `CancelOrder`
existed as ADR-0004's example describes — `TransitionOrderStatus` plus
`ReleaseStock`, composed in a class of its own — then
`app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled,
$actor)` would still compile, still pass every check `TransitionOrderStatus`
owns, and would leak the reservation permanently. This codebase already
documents several convention-only-protected paths as known gaps (ADR-0007's
null-actor exposure among them) precisely because nothing catches them
mechanically. Introducing a fourth one where a structural alternative exists —
folding the effect into the Action that cannot be bypassed without bypassing
the status write itself — is a worse trade than the extra branching it costs.

## Decision

**An inventory effect that must be atomic with the status write lives inside
`TransitionOrderStatus`. An effect that cannot be rolled back regardless
(a courier API call, a queued email) does not, and belongs on the
`OrderStatusChanged` event instead, after commit.**

The rule generalises past inventory: side effects split by *undoability*, not
by which table they touch or how important the aggregate is. Anything that
shares the transaction's atomicity guarantee belongs with the write that
needs it protected. Anything that doesn't have that guarantee to begin with —
because it already happened the moment it ran — gains nothing from sitting
inside the transaction and loses the ability to be retried or queued
independently.

Concretely, `TransitionOrderStatus` composes `ReleaseStock`, `CompleteSale`,
and `RestockReturn` directly and picks one by the *target* status:

| Target | Effect |
|---|---|
| `Cancelled` | `ReleaseStock` — reserved → available |
| `Shipped` | `CompleteSale` (new) — reserved → sold |
| `Returned` | `RestockReturn` (new) — sold → available, counted as a return |
| everything else | none |

No `CancelOrder`, `ShipOrder`, or `ReturnOrder` wrapper exists. There is
nothing for one to compose that `TransitionOrderStatus` does not already own.

**No "was stock actually reserved?" branch, and none is added.**
`OrderStatus::allowedTransitions()` does not permit `Cancelled` from
`Shipped` or `Delivered` — cancellation is only reachable from the six
statuses where stock is reserved and not yet sold. Every reachable
cancellation releases unconditionally. A branch that checked first would be
dead code the matrix already makes unreachable, and dead code defending an
invariant the enum enforces is a second place to keep the same fact in sync,
which CLAUDE.md rules out generally.

**Damaged returns are out of scope for this Action.** `RestockReturn`
assumes a return is resellable and puts it straight into `current_quantity`.
A damaged item moves on from there to `inventories.damaged_quantity` via a
separate `RecordDamage` call — a warehouse employee's own inspection, not
something this Action or `TransitionOrderStatus` decides automatically.
`RecordDamage` and its admin surface (`ViewInventory`'s "Record damage"
header action) exist and are wired up; the decision recorded here — that a
damaged return is a distinct, later, manual step rather than a branch inside
`RestockReturn` — is what stands, not the specific gap that used to follow
from it.

## Consequences

+ There is no call path that reaches `Cancelled`, `Shipped`, or `Returned`
  without the matching inventory movement — the guarantee is structural,
  the same reasoning ADR-0007 already applies to authorization living inside
  an Action rather than at each call site.
+ Three inventory Actions instead of one `CancelOrder` wrapper, but no
  `ShipOrder`/`ReturnOrder` wrappers are needed either — the transitions that
  used to have no Action at all (`=> Shipped`, `=> Returned`) now cost nothing
  extra beyond `TransitionOrderStatus` itself.
+ `TransitionOrderStatus`'s constructor grows to three collaborators
  (`ReleaseStock`, `CompleteSale`, `RestockReturn`) instead of composing a
  `CancelOrder`. A test for the transition Action now also exercises
  inventory, which is the coupling the previous paragraph argues for rather
  than against.

− `TransitionOrderStatus` now knows about three inventory Actions, which
  reads as it doing more than "change a status" if read in isolation from
  this ADR — exactly the kind of decision CLAUDE.md asks to be written down
  rather than left implicit in a diff.
− `ReleaseStock`'s docblock still says its four §20 triggers "reach this
  through their own Action" — true, but that Action is now
  `TransitionOrderStatus` directly rather than a `CancelOrder` the docblock's
  author had in mind when it was written. Not misleading, but worth rereading
  alongside this ADR rather than on its own.
− Damaged returns remain an open gap, now written down twice — here and in
  `RestockReturn`'s own docblock — rather than closed.

## Alternatives rejected

- **`CancelOrder` as ADR-0004's Context sentence describes.** Rejected for
  the reason in Context: a wrapper is bypassed by calling the Action it
  wraps directly, and nothing stops that call from compiling.
- **A generic `ApplyOrderStatusSideEffects` dispatcher**, keyed by status,
  called from `TransitionOrderStatus` but living in its own class. Moves the
  `match` shown above into a second file for no isolation benefit —
  `TransitionOrderStatus` is already the only caller and already owns the
  transaction the effect must share, so splitting the two apart would be
  indirection without a second consumer to justify it.
- **A status-keyed strategy interface** (`OrderStatusEffect::apply()`,
  implemented once per status). Three real implementations and eight no-ops
  would need one class each, all through an interface whose only value is
  looking pluggable — no second caller is coming, and ADR-0007 already argues
  against building for hypothetical future callers.
- **Firing the inventory effect from a listener on `OrderStatusChanged`
  instead.** Listeners run after commit, by design (this is exactly why the
  event exists at all). An inventory effect run after commit cannot be rolled
  back if it fails, which turns "order shipped but stock still shows
  reserved" from an impossible state into a race between two independent
  writes — the opposite of what putting it inside the transaction achieves.
