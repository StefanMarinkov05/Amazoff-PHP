# ADR-0004: Where state transitions live

Status: Accepted
Date: 2026-08-10 · Deciders: Stefan Marinkov

## Context

Four columns in the schema describe a lifecycle rather than a category: order
status (§18, 11 values), payment status (§13, 7), shipment status (§15, 6), and
article status (§22, 4).

"Can this status change happen" is three questions wearing one coat:

1. **Is the move legal at all?** `Delivered => New` is nonsense no matter who
   asks for it.
2. **May this actor perform it?** §18 is explicit that not every employee may
   select every status — a warehouse employee moves an order to preparing, an
   administrator cancels or refunds.
3. **Was it recorded?** §19 requires every change to write the order, previous
   status, new status, the user who made it, timestamp, reason, and internal
   note, and requires that history not be editable by regular users.

Answering all three in one place produces either an enum that knows about roles
or a controller that knows about state machines. §28 adds that critical order
operations must run inside database transactions, which rules out answering
them in three unrelated places either.

The actors differ per column, and that matters. An order status is chosen by a
person looking at a dropdown. A payment status is set by a Stripe webhook, and
Stripe does not order delivery — a late `payment_intent.succeeded` can arrive
after `charge.refunded`. A shipment status comes from courier tracking, mapped
onto shared cases from two different courier vocabularies. The external actors
are less trustworthy than the human one, not more.

## Decision

Each question is answered in exactly one place.

### Legality lives on the enum

`allowedTransitions()` returns the statuses reachable from a given case;
`canTransitionTo()` is a lookup into it. The list is the single expression of
the rule and the boolean derives from it.

These methods are deliberately role-blind. No role name appears in `App\Enums`.

A matrix goes on an enum only when illegal moves exist **and** some actor can
attempt them. That is four of the twelve enums. The other eight classify rather
than change — an inventory movement type labels a ledger row, it does not
become a different type later — except `NewsletterStatus`, which is a lifecycle
whose every move is legal, so a matrix would permit everything and assert
nothing.

The four are not equally strict. `ArticleStatus` is permissive, every state
reachable from every other, because a content editor picking wrong is visible
immediately and cheap to undo. Orders and payments are neither, so those
matrices are narrow and their terminal states are genuinely terminal.

### Authorization lives in the policy

Whether *this user* may perform a legal move is a policy question, checked
against roles and permissions from `spatie/laravel-permission`.

This keeps §3.5 satisfiable: permissions are editable at runtime, so any
role-to-transition mapping compiled into PHP would go stale the moment an
administrator edits a role.

### Enforcement lives in an Action

`TransitionOrderStatus` checks the enum, checks the policy, writes the status,
records the §19 history row, and dispatches the resulting events — all inside
`DB::transaction`. Payment and shipment transitions get their own equivalents.

Order status is never assigned directly. A bare `$order->status = ...` bypasses
history, events, and the role gate at once, and none of the three failures are
visible at the call site.

## Consequences

+ Each of the three questions has one home, so a change to one does not risk
  the others.
+ The matrix is plain data. It is testable without a database, a request, or a
  user, and the tests read as a table of legal and illegal pairs.
+ Runtime permission edits (§3.5) need no code change, because the enum never
  learned about roles.
+ The webhook and courier paths get the same integrity guard as the human one,
  which is where it is most needed — the `stripe_event_id` UNIQUE constraint
  prevents an event being processed twice but says nothing about events
  arriving out of order.

− Three places to read before understanding one rule.
− The enum cannot enforce anything on its own; it has no access to the record
  or the user. Code that skips the Action bypasses every check. Only convention
  and review prevent that, which is why CLAUDE.md states it as a rule rather
  than leaving it to be inferred.
− The matrices exist and nothing calls them yet. No Action, policy, or history
  writer has been built, so the split above is a design that has not been run.

## Alternatives rejected

- **A state machine package** (`spatie/laravel-model-states` or similar). Turns
  each state into a class — 28 classes across the four lifecycles — and still
  needs a backed enum for the column cast, so the value list ends up expressed
  twice. The transitions here are a static table, not behaviour that differs
  per state.
- **A guard in a model mutator** (`setStatusAttribute`). Hides a business rule
  where nobody looks for one, and is bypassed by mass assignment, by query
  builder updates, and by Filament writing the attribute itself.
- **Role names inside the enum's matrix.** Would answer questions 1 and 2 in
  one call, at the cost of a compiled-in role map that contradicts §3.5's
  runtime-editable permissions.
- **Database check constraints.** MySQL can constrain a column to a set of
  values, which the `enum` column already does. Expressing "from this value to
  that value" needs a trigger, which puts a business rule somewhere no test
  will read it.
- **No guard, relying on the UI to offer only valid options.** A hidden button
  is not security, and it does nothing at all for the webhook and courier paths
  where there is no UI.
- **One matrix covering all four lifecycles.** They share a shape but nothing
  else; a single table would need a discriminator column and would couple an
  article's lifecycle to a payment's.
