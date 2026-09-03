# How to test a form or filter for input crashes

What to actually try against a Livewire component's public properties before
calling it safe, and why each case matters. `docs/reference/tested-inputs.md`
is the running log of what has been checked where — read that first to see
if the thing you're about to test is already covered; add to it once you've
checked something new.

This is about crashes and information leaks reachable through **input a
customer controls**, not about `#[Url]`-bound values specifically — though
every `#[Url]` property is exactly this by ADR-0014's own reasoning
("Livewire will happily hydrate `?sortBy=` with anything"), the same is true
of any `wire:model`-bound property reachable through DevTools or a crafted
request, `#[Url]` or not.

## Why the browser's own constraints do not apply

An `<input type="number" min="1" max="99">`'s bounds, a `maxlength`
attribute, a `<select>`'s fixed option list — none of these are enforced by
anything but the browser rendering the form. Three ways around all of them
at once, in increasing order of realism for an actual attacker:

1. **DevTools (F12), Elements panel.** Edit the attribute or the element
   directly, submit through the normal UI.
2. **The URL, for any `#[Url]`-bound property.** `?category=<anything>` — no
   browser involved at all.
3. **`curl`, or a Livewire component test.** Bypasses the browser and its
   rendered HTML entirely; `wire:model` sends whatever the client sends,
   and Livewire hydrates the raw request value onto the property before any
   of the component's own code runs. This is the realistic path — an
   attacker is not clicking spinner arrows, they are sending a request.

`Livewire::test(Component::class)->set('property', $anything)` is the same
class of check as `curl`, faster to run and easier to turn into a permanent
test — prefer it once you know what value to try; use `curl` first when
you are still working out whether the app is even reachable in the state
you expect (see `docs/reference/local-access.md` for running the app
locally).

## The value playbook

Try each of these against every property a form or filter exposes,
regardless of whether the property looks numeric, textual, or an enum-like
string. Not every value applies to every property type — a `string`
property cannot overflow the way an `int` one can — but check anyway rather
than assuming, since the property's *declared* type is what determines which
of these actually bite, and getting that wrong once already shipped a bug
this session (below).

| Value | What it catches |
|---|---|
| A number too large for PHP to represent as an `int` (`99999999999999999999999999999999` — 34 digits, past `PHP_INT_MAX`) | Crashes a strictly `int`/`float`-typed property at Livewire's own hydration step, **before** any validation or `updated()` hook runs. `string`-typed properties are structurally immune — PHP accepts any string assignment to a `string` property regardless of content. |
| A number within PHP's range but past the column's own range (MySQL `int`: ±2.147 billion) | Should be caught by a domain check (stock/limit comparison) before ever reaching an `INSERT`/`UPDATE`; a raw `QueryException` here means the domain check is missing or is on the wrong side of a boundary. |
| A malformed numeric-shaped string (`100..0`, `1e400`, `+-5`, a value `is_numeric()` itself would reject) | Whatever accepts "numeric-looking" input has to reject cleanly, not attempt a cast that produces garbage or throws. |
| A negative number where none makes domain sense (quantity, price, rating tier) | Silent misuse of an unsigned-in-practice value — a negative price filter, a negative quantity — has to be refused, not partially applied. |
| Decimal where the domain expects a whole number (rating tier `4.5`, quantity `3.5`) | Same shape as negative: reject, do not floor/round silently unless that is the *documented* behaviour. |
| Empty string, `null` | The declared "no value" state — confirm it is treated as "filter not applied" / "field is empty," not as `0` or a string `"null"`. |
| `<script>alert(1)</script>` (XSS-shaped) | Confirms Blade's `{{ }}` escaping actually runs on every path the value reaches — a value embedded in an HTML *attribute* (a `tooltip()`, a `value="..."`) is expected to appear escaped in the raw response; only unescaped appearance in a text node is the real finding. |
| `' OR '1'='1`, `'; DROP TABLE products;--` (SQLi-shaped) | Structural, not a filter to add: every query in this codebase goes through Eloquent's parameter binding, so this should have zero effect on the query — same result count as not sending it. If it changes behaviour at all, that is the finding, not the payload itself. |
| A string thousands of characters long | A `varchar(N)` column throws `QueryException: Data too long for column` if nothing validates length first — check the migration's own column length against the `max:` rule (or its equivalent) protecting it, do not assume they agree. |
| A value not in a fixed set (`minRating=5` when the allow-list is `[4,3,2,1]`, a sort column not in `SORTS`) | ADR-0014's allow-list rule: anything reaching `orderBy()`, `whereIn()`, or a similar structural use has to be checked against a fixed list, not merely validated as "a string" or "an int." |

## What actually broke this session, and why

**`ProductDetails::$quantity`, originally `public int $quantity = 1`.**
Typing into the quantity stepper a number too large for PHP to represent as
an `int` produced an uncaught `TypeError` — a raw 500, before
`updatedQuantity()`'s own clamp ever ran. The number input's `min`/`max`
HTML attributes did nothing to stop it; `wire:model` sent the raw string
regardless.

The fix, and the pattern every numeric `#[Url]`/`wire:model` property in
this codebase now follows (`ProductList::$minPrice`, `$maxPrice`,
`$minRating`): declare the property `mixed`, not `int`/`float`, so Livewire
can never throw at hydration regardless of what arrives, and do all
sanitisation explicitly in an `updated{Property}()` hook — reject what does
not parse, clamp what is out of domain range, never partially apply a
malformed value.

**Why this was missed the first time even though `ProductList` already had
`categorySlug` reviewed for the same class of thing**: `categorySlug` is a
`string` property, so the overflow case does not apply to it at all — the
review that checked it correctly found nothing, and that "nothing found"
result did not transfer to `$quantity`, a differently-typed property on a
different component, checked in a later, separate pass. **The lesson: this
playbook is per property, not per component or per session** — a component
already reviewed once can still ship a new property later that needs the
same pass run against it specifically.

**Confirmed a second time, same session, same lesson**: writing this doc
prompted checking `ProductDetails::$variationId` (`?int`, `#[Url(as: 'v')]`)
specifically because `$quantity` on the *same component* had just been
fixed — and it crashed too, live, `?v=99999999999999999999999999999999`
against a real product page: `TypeError: Cannot assign float to property
... of type ?int` (an oversized numeric string decodes to a `float` during
`#[Url]` hydration, a different intermediate type than `$quantity`'s
`string`-that-can't-cast-to-`int` case, but the same root cause — a
strictly numeric-typed property assigned before any of the component's own
code runs). `$quantity` being fixed did not mean `$variationId` was; two
properties on one component, both numeric-typed, needed the pass run
against each individually. Fixed the same way: widened to `mixed`,
normalised explicitly (in `mount()`, since that is where this property's own
"fall back to a valid default" logic already lived).

**A third instance, a different session (2026-09-03), a different
component**: `ProductList::$brandId` (`?int`, `#[Url]`) — the property
sitting one field below `$categorySlug` in the same class, on the same
component that had already been reviewed for this exact class of bug.
`?brandId=99999999999999999999999999999999` produced the identical
`TypeError: Cannot assign float to property ... of type ?int`, confirmed
live before the fix. This is the strongest confirmation yet of the doc's own
thesis: `ProductList` was not an unreviewed component, `$categorySlug` had
been checked and correctly found clean (it is a `string`, structurally
immune), and that clean result on one property said nothing about `$brandId`
two lines below it. Fixed the same way — widened to `mixed`, normalised via
a `safeBrandId()` method (this component has no `mount()`, so normalisation
lives at the two read sites instead) — and proven by
`tests/Feature/Livewire/ProductListBrandFilterTest.php`, verified red
without the fix by reverting the type and watching the same `TypeError`
reproduce at the same `data_set()` line.

**If a fourth instance turns up, treat it as the signal to stop fixing these
one at a time and instead grep every `#[Url]`/`wire:model` property in the
codebase for a strict numeric type in one pass** — three separate discoveries
of the same root cause is a pattern a systematic sweep would have caught in
one sitting, at the cost of one property being reviewed slightly before its
own bug was found "by accident."

## The second class: not a crash, a silently wrong answer

`ProductList::$attributeValueIds` (2026-09-03) found a different failure
mode, and it is worth its own heading because **the playbook above would
not have caught it and neither did the test that already existed for this
exact input.**

The property was already `mixed`, so nothing crashed. The bug was that
`intval()` of a *non-empty array* is `1`, not `0` — so a nested array
(`?attributeValueIds[0][0]=1&attributeValueIds[0][1]=2`) did not degrade
into a harmless non-matching id, it collapsed onto the real, filterable id
`1` and applied that filter, chip and all. 164 products became 37 under a
"Black" filter the visitor never selected.

A test for this input already existed — `'does not crash on a non-numeric
or nested attribute value id'` — and it passed throughout, because it
asserted `assertOk()` and nothing more. The input was right, the assertion
was too weak: **a wrong answer returns 200.**

Two rules follow:

- **For any value that reaches a query, assert the resulting set, not the
  status code.** `assertOk()` proves the request survived; only
  `viewData('products')` (or an equivalent) proves it survived *correctly*.
  Every "does not crash on X" test is worth a sibling "and X changes
  nothing" test.
- **Casting is a per-reader decision, so put it in one place.** Four
  readers of this property each cast for themselves and diverged: only
  `updatedAttributeValueIds()` filtered on `is_numeric` first. That gap is
  reachable precisely because `updated*` hooks never fire on a first page
  load from `#[Url]` hydration — the same timing that makes the crash class
  above possible. One shared `safeX()` method, called by every reader, is
  the shape that closes both.

## What already came back clean

Storefront auth (`Login`, `Register`, `ChangePassword`) and contact
(`ContactForm`, `NewsletterSignup`) — every property on all five is
`string`-typed, so the hydration-crash case does not apply structurally, and
every property is covered by a `rules()`/`validate()` call whose `max:`
length matches its column exactly (verified against the migrations, not
assumed). XSS- and SQLi-shaped input against all five returns a validation
error, never a reflection or a query change. Full detail in
`docs/reference/tested-inputs.md`.
