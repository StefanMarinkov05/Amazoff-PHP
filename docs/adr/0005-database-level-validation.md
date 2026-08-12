# ADR-0005: Validation in the database, not only in PHP

Status: Accepted
Date: 2026-08-11 · Deciders: team

## Context

The implementation standards issued after the numbered specification require
that PHP validation is not relied on alone and that matching constraints exist
in the database. §28 already required server-side price calculation and
transactional order operations; this extends the same reasoning down a layer.

The schema as generated carried almost none of it. Six pivot tables were bare
foreign-key pairs with no primary key, so the same attribute could be attached
to a product twice. Nothing prevented a negative stock level, a discount price
above the price it discounted, a refund larger than the payment, a review rated
eight out of five, or a coupon window that ended before it began.

Form Requests were going to check these. The question was whether that is
enough.

## Decision

Every invariant that is true of a single row in isolation is enforced by the
database as well as by the application.

### What went in

Composite primary keys on all six pivot tables. A primary key rather than a
unique index: it expresses the same guarantee, InnoDB clusters the rows by it,
and these tables are always read by one side of the pair and never by an id of
their own.

Forty-five `CHECK` constraints across eleven tables, covering money that cannot
be negative, quantities that cannot be negative, discounts below the price they
reduce, VAT rates that are percentages, reservations not exceeding stock on
hand, refunds not exceeding the payment, date windows that end after they
start, and review ratings inside the five-star scale.

### Why both, rather than one

A Form Request and a constraint answer different questions. The Form Request
produces a readable message for the person filling in a form. The constraint
holds regardless of which code path wrote the row — a seeder, a queued job, a
fixture import, a console command, or an Action whose author forgot to
validate. Under concurrency the application check is advisory and the constraint
is not: two orders reserving the last item both read the same available
quantity, and only the constraint stops the loser writing a negative one.

### What the database cannot do

Recorded so it is not assumed:

- Arithmetic agreement between columns, such as an order total equalling
  subtotal minus discount plus shipping plus VAT. Expressible, but the rounding
  is bcmath's and MySQL would disagree at the half-cent. §11 puts the
  calculation on the server and it stays there.
- Uniqueness spanning two tables, such as a SKU unique across products and
  variations together.
- Minimum cardinality, such as every product having at least one variation.
- Set equality across rows, such as two variations of one product carrying the
  same attribute values.
- Status transitions, which compare a row against the row it replaced. ADR-0004
  owns those.

These stay application invariants, enforced in the Actions that write them.

### Relationship to the fixture validator

ADR-0003 specifies a three-layer validator for demo fixtures: document shape,
column constraints, and cross-document invariants. The constraints added here
cover the second layer completely, which changes how the validator is built —
it derives those rules from `information_schema` rather than restating them.

It does not replace the validator. The database never sees the JSON, so shape
is outside its reach; the cross-document layer is the same set of things listed
above as inexpressible; and a constraint reports one violation at a time,
mid-transaction, after preceding rows are written, where the validator reports
every violation before anything is written. For an unattended fixture run that
difference is the point.

## Consequences

+ Invalid rows cannot be stored by any code path, including ones written later
  by someone who has not read the Form Request.
+ The demo and stress seeders inherit the guarantee without their own checks.
+ Requirement 15 of the implementation standards is satisfied and verifiable.

− Constraints and Form Requests can drift apart, stating different rules for
  the same column. The Form Request is the readable half and the constraint is
  the binding one; when they disagree the constraint wins and the user sees a
  500 rather than a validation message.
− Adding a constraint to a table with existing data fails until the data is
  fixed, which is a deployment step rather than a migration detail.
− MySQL has no transactional DDL. A migration that adds constraints and fails
  partway leaves the earlier ones applied, and rerunning it then fails on
  duplicate names. The migration groups its statements per table partly to
  narrow that window.

## What this forced

Ten of the thirty-two factories generated data that violated the new
constraints, and the dev database held twenty-two rows that did — eleven of
nineteen products had a discount price at or above the regular price. None of
it had ever failed a check, because Pint, Larastan, and Pest all pass on data
that only the database rejects.

The factories were rewritten so money is derived rather than drawn
independently: a line total is unit price times quantity, VAT is extracted from
the gross total rather than invented, and an order total is its parts summed.
This is the same class of correction the `char(2)` country bug produced, and it
is why `tests/Feature/FactoryTest.php` exists.

## Alternatives rejected

- **Form Requests alone.** The standards forbid it, and it leaves every
  non-HTTP write path unguarded — which is most of them in a project with
  seeders, queued jobs, and a fixture importer.
- **Constraints alone.** A database error surfaces to the user as a 500 with no
  field attribution. Validation still belongs at the boundary.
- **Application-level invariant checks in a base model.** Runs in the same
  process as the code it distrusts, and does not survive a raw query builder
  call or a bulk insert.
- **Triggers instead of CHECK.** More expressive, and invisible: a business
  rule in a trigger is a rule no test reads and no reviewer sees in the diff.
