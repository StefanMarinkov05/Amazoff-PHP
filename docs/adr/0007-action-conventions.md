# ADR-0007: How Actions are written

Status: Accepted
Date: 2026-08-13 · Deciders: Stefan Marinkov

## Context

§33–36 asks for "service classes"; CLAUDE.md reads that as one command per
class in `app/Actions/*`. ADR-0004 already places the enforcement of a status
change in an Action and describes what `TransitionOrderStatus` does.

Neither says how an Action is written. Roughly thirty-five are needed across
cart, checkout, payment, shipping, inventory, and content, and they will be
written by two people over several weeks. The questions below are the ones
that get answered differently by whoever writes the next one, and the
divergence is invisible in review because each individual answer looks
reasonable.

Four are load-bearing:

1. **Who authorizes?** ADR-0004 puts the policy check inside the Action. But a
   Stripe webhook, the scheduler, and a queued job have no authenticated user,
   and `Gate::allows()` with no user denies everything.
2. **When do events fire?** ADR-0004 has the Action dispatching events inside
   `DB::transaction`. §28 requires the resulting emails to be queued.
3. **Does every Filament write go through an Action?** CLAUDE.md said yes. The
   seven merged resources say no — they use Filament's default CRUD, which
   writes Eloquent itself.
4. **What owns the transaction when Actions compose?** `CancelOrder` is
   `TransitionOrderStatus` plus `ReleaseStock`, and both open transactions.

## Decision

### Shape

One public `handle()`. Dependencies by constructor injection, including other
Actions. Parameters are models and scalars, never a `Request` — that is what
lets a Filament page and a Livewire component call the same class. Input shape
is validated by a Form Request before the Action is reached; the Action
validates domain state, not payloads.

Failure is a domain exception (`InsufficientStockException`,
`CouponExpiredException`), never a `false` or a null return. An exception
cannot be accidentally ignored at a call site, and the message that reaches
the customer is the caller's decision rather than the Action's.

Grouped by area: `app/Actions/Orders/CreateOrder.php`.

### The actor is a nullable parameter, and null means the system

```php
public function handle(Order $order, OrderStatus $to, ?User $actor = null): void
```

A null actor is the application acting on its own behalf — a webhook, the
scheduler, a queued job — and skips the policy check. A non-null actor is
authorized against the policy before anything is written.

This keeps ADR-0004's guarantee that the check cannot be bypassed by a caller
who forgets it, while leaving the webhook path able to run at all. The risk it
introduces is a controller that passes null and silently skips authorization;
that is a review question, and the reason `$actor` is the last parameter
rather than the first is that omitting it has to look like an omission.

Actions with no non-human caller do not take the parameter.

### Events dispatch after commit, always

An event dispatched inside the transaction is delivered even when the
transaction rolls back. §28 queues the order-confirmation email, so the
observable failure is a customer receiving confirmation of an order that does
not exist.

Every event an Action dispatches implements `ShouldDispatchAfterCommit`. This
is a property of the event class rather than of the dispatch site, so it holds
no matter which Action fires it or how deeply nested the call is.

### An Action is required where a rule exists, not everywhere

A rule exists when a write spans more than one table, or enforces an invariant
the schema cannot: a product needs at least one variation and each variation
an inventory row; an order needs items and addresses; a status change needs a
history row.

Plain lookup tables — `Brand`, `Tag`, `Attribute`, `AttributeValue`,
`ProductCategory`, `ArticleCategory`, `Carrier` — keep Filament's default
CRUD. Wrapping a single-table save in an Action buys no consistency, because
there is no second writer and no second table to keep in step.

Where an Action is required, the Filament resource calls it from
`handleRecordCreation()` and `handleRecordUpdate()` rather than letting the
page write the model.

### Composition nests, and the outermost boundary wins

Actions call other Actions directly. Each wraps its own critical section in
`DB::transaction`; Laravel implements nesting with savepoints, so an inner
transaction joining an outer one is safe and the outermost boundary is what
commits.

An Action therefore never needs to know whether it is the outermost caller,
which is the property that makes composition possible at all.

## Consequences

+ A rule has one implementation, reachable identically from the storefront,
  the panel, a queued job, and a test.
+ Actions are testable without HTTP: construct, call `handle()`, assert. The
  concurrency test for `ReserveStock` is possible only because no request is
  involved.
+ `main` stops contradicting CLAUDE.md, which said every Filament write goes
  through an Action while seven merged resources did not.

− Around thirty-five classes where a service-per-aggregate would have had
  five. The directory is large and shallow by design.
− A null `$actor` skips authorization. It is the one place in the codebase
  where forgetting a parameter weakens a security check rather than raising an
  error, and only review catches it.
− The boundary between "has a rule" and "plain lookup" is a judgement call.
  `Coupon` is the awkward case: single-table today, but usage limits make it
  contested state the moment redemption is built.

## Alternatives rejected

- **A service class per aggregate** (`OrderService`, `CartService`). Fewer
  files, and the constructor of each becomes the union of every method's
  dependencies, so a test for one method constructs all of them. The classes
  grow monotonically because there is always a plausible place to add a
  method.
- **Authorization in the caller only.** Simpler Actions, and every new call
  site becomes a place the check can be forgotten with no safety net.
  Contradicts ADR-0004.
- **A separate `SystemUser` model** instead of a null actor. Removes the
  nullable parameter, at the cost of a row in `users` that can authenticate,
  hold roles, and appear in any query scoped to users.
- **Events dispatched by model observers** rather than by Actions. Fires on
  every write including seeders and factories, and gives no access to the
  actor or the reason a change was made — both of which §19 requires recorded.
