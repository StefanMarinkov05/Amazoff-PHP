# ADR-0014: How a storefront page reads

Status: Accepted
Date: 2026-08-23 · Deciders: Aleksandar Stanchev

## Context

ADR-0007 settled how a *write* reaches the database: an Action per command,
called identically by Filament and by the storefront, so two developers cannot
build two versions of one business rule. It says nothing about reads, because
until now the only consumer was Filament, which builds its own queries from a
resource's table definition and never asks the question.

The catalogue page is the first storefront slice, and it is almost entirely
read. It also sets the shape every remaining page copies — product detail,
cart, checkout, order tracking, the article list — so the conventions it
establishes are worth deciding once rather than drifting five times.

Four questions had to be answered before the page could be written, and none
of them has an answer in an existing ADR: whether a read goes through an
Action, where filter state lives, how a page that filters *and* counts avoids
holding two definitions of "filtered", and what a URL-bound property is
allowed to reach.

## Decision

**Reads query Eloquent directly from the Livewire component. Writes go
through Actions, unchanged.** ADR-0007's rule is a rule about writes and stays
one. A component may build any query it likes; the moment it changes state it
calls an Action, exactly as a Filament resource does.

**Components live at `app/Livewire/{Area}/{Noun}.php`**, mirroring
`app/Actions/{Area}/`, with the Blade view at the matching path under
`resources/views/livewire/`. `Catalogue/ProductList` is the first; the area is
the customer-facing surface, not the model.

**Every filter is `#[Url]`.** Filter state lives in the query string, never in
the session.

**A page that filters and counts holds one definition of "filtered."**
`ProductList::applyFilters(Builder $query, ?string $skip = null)` is called by
the product query and by both facet counts, with `$skip` letting a facet
exclude its own dimension.

**Anything a URL-bound property reaches into a query is allow-listed.**
`ProductList::SORTS` is the allowed sort columns, `safeSortBy()` and
`safeSortDir()` fall back rather than trust, and `setSortOrder()` refuses a
column not in the list.

**Availability shown to a customer is `current_quantity - reserved_quantity`.**
Both in the `inStockOnly` filter and in the per-card figure.

### Why reads do not go through Actions

An Action exists to own an invariant. A read has none to own: it cannot leave
the database inconsistent, so there is nothing for a second developer to
implement differently in a way that matters. ADR-0007's own carve-out makes
the same point from the other side — plain lookup tables keep Filament's
default CRUD "because wrapping a single-table save in an Action buys nothing
and costs a class." A read is that argument at full strength.

The practical objection to routing reads through Actions is that catalogue
queries are not reusable. This page needs review averages, eager-loaded
inventory, and facet counts against a partial filter set; the product detail
page needs a variation grid and none of that. A shared `ListProducts` would
grow a parameter per caller and be read by none of them.

What *is* shared gets shared as a query scope or a small support class when
the second caller appears — not pre-emptively, and not as an Action.

### Why filter state goes in the URL rather than the session

A filtered catalogue is something customers send each other, bookmark, and
reach through the back button. Session-held filters break all three, and they
break them in the way that generates support tickets rather than bug reports:
two people open "the same" link and see different pages.

The cost is that every public property with `#[Url]` is attacker-controlled
input, which is precisely why the allow-list decision above is not optional.
Livewire will happily hydrate `?sortBy=` with anything, and it lands in
`orderBy()`. `#[Url]` and the allow-list are one decision in two parts.

Pagination is the exception that proves the shape: `updated()` resets the page
on any property change except `page` itself, because a filter change makes the
current page number meaningless — page 4 of a result set that now has one page
is an empty page, which reads as "no results" and is the single most common
filter bug in a catalogue.

### Why one filter definition rather than two queries

The facet counts and the listing must agree, and the failure when they do not
is silent: a category reading "Speakers (12)" that produces an empty grid. Two
query builders drift the first time a filter is added to one and not the
other, and nothing fails — the numbers are just wrong.

Counting against *every* filter including the facet's own is the version that
looks correct and is not: selecting a category would make every other category
read zero, because no product is in two. Hence `$skip`. Each facet counts
against every filter except its own dimension, and `having('products_count',
'>', 0)` drops options that would produce an empty page rather than showing a
zero.

### Why reserved stock is subtracted on the read path

`Inventory` carries `current_quantity` and `reserved_quantity`, and a unit
reserved for someone mid-checkout is not one this customer can buy. Showing
the raw `current_quantity` makes the catalogue promise stock that
`ReserveStock` then refuses — the failure surfaces at the last step of
checkout, which is the most expensive place a shop can put it.

This is a display decision, not an authority: the catalogue figure is read
outside a transaction and is stale the moment it renders. `ReserveStock`
remains the only thing that decides whether stock exists, under the lock
ADR-0008 specifies. The read path's job is to be *approximately honest*, not
authoritative.

## Consequences

- Storefront read queries are not covered by the Action test suite, because
  they are not Actions. They need feature tests against the component
  (`Livewire::test(...)`), which is a different test shape than the one this
  project has been writing so far.
- N+1 protection is now a per-component responsibility. `ProductList` eager
  loads `productImages`, `brand`, and `productVariations.inventory` because
  the card template touches all three; a template change that reaches a fourth
  relation will silently issue a query per row.
- `ProductList::discountIsActive()` duplicates the price-window logic in
  `ResolveVariationPrice::windowActive()`. This is real duplication of a real
  rule and the one place this ADR's reasoning is uncomfortable — the window is
  a business rule, and a second implementation can disagree with the first.
  It is accepted only until the product detail page needs the same answer,
  at which point the shared definition is extracted rather than copied a third
  time. Two callers is the trigger; this is a debt with a due date, not a
  pattern to follow.
- `#[Url]` names appear in shareable links, so they are effectively public
  API. Renaming `categoryId` breaks every bookmark that used it.
- Facet counts cost two extra aggregate queries per render. Acceptable at this
  catalogue's size; the thing to reach for if it stops being acceptable is
  caching the counts per filter combination, not dropping them.

## Alternatives rejected

- **A `ListProducts` Action or query-object layer.** Grows a parameter per
  caller, is read by none of them, and protects an invariant that does not
  exist. Covered above.
- **Filter state in the session.** Breaks sharing, bookmarking, and the back
  button. Covered above.
- **A repository or presenter layer between component and model.** Ruled out
  already by CLAUDE.md — "No repository pattern. Eloquent is the repository."
  Noted here only because a read-heavy page is where the temptation appears.
- **Counting facets against the unfiltered catalogue.** Cheaper, and wrong in
  the direction that misleads: a count that does not respond to the other
  filters promises results the grid will not show.
- **Full-text search over `LIKE '%term%'`.** The `LIKE` is a known placeholder
  — unindexable, and it matches mid-word. §37 criterion 3 asks for search, not
  for relevance ranking, and MySQL full-text or Scout is a decision with its
  own trade-offs that should be made when search quality is the thing being
  worked on, not smuggled in underneath the first catalogue page.
