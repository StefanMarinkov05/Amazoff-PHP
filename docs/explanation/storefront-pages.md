# How a storefront page is put together

What each file in a storefront slice does, and where the Actions plug in. The
worked example is the catalogue, which is the only slice built so far.

The counterpart for the admin side is `filament-resources.md`. Why a read does
*not* go through an Action, and why filter state lives in the URL, is
ADR-0014; what each Action does is `reference/actions.md`.

## The files

```
online-store/
├── routes/web.php                                    a route per page
├── app/Livewire/Catalogue/ProductList.php            state + queries
└── resources/
    ├── views/livewire/catalogue/product-list.blade.php   the markup
    ├── views/components/layouts/app.blade.php            the shell
    ├── views/components/site/{header,footer}.blade.php   the chrome
    └── css/app.css                                       tokens + sources
```

A full-page Livewire component is routed directly — `Route::get('/catalogue',
ProductList::class)`. There is no controller, because there is nothing for one
to do: the component holds its own state and builds its own query. Controllers
come back when a page is not a Livewire component (a webhook, a PDF, a
redirect).

The component and its view are paired by name, not by configuration.
`App\Livewire\Catalogue\ProductList` renders
`resources/views/livewire/catalogue/product-list.blade.php`. Rename one and
you rename the other.

`components/layouts/app.blade.php` is the default layout every full-page
component is wrapped in — Livewire looks for exactly that path. It owns
`<head>`, the skip link, `<x-site.header />`, `<main>`, and
`<x-site.footer />`. A page never renders chrome itself.

## Where the layers split

The component holds **state and queries**. The Blade view holds **markup and
nothing else** — no query, no business rule, no computed price. Anything the
template needs that is not a plain property is a method on the component
(`discountPercent($product)`) or a `#[Computed]` property (`categories()`).

`#[Computed]` is memoised for the life of one request, which is why the two
facet lists are computed properties and the paginated product list is not: the
product query is used once and passed through `render()`, while
`categories()` is touched by both the sidebar and the mobile drawer.

## The six steps

The method that produced `ProductList`, in the order the steps actually
happen. It is worth following literally for the next page rather than starting
from the markup.

1. **Write the behaviours as sentences.** "A customer can search by name." "A
   customer can narrow to one brand." Anything you cannot state in a sentence
   is not yet a feature.
2. **Give each sentence its state.** One public property per thing that can
   change. Filters get `#[Url]`; anything reachable from the URL needs an
   allow-list before it touches a query (ADR-0014).
3. **Give each sentence its trigger.** Either `wire:model.live` on a control,
   or a public method the template calls (`setSortOrder`, `clearFilter`).
4. **Make `render()` use every property.** A property nothing reads is state
   that will drift out of sync with what is on screen.
5. **Write the template with one control per property.** Two controls bound to
   one property is fine; one control bound to nothing is a bug.
6. **Verify the round trip in a browser.** Change the control, watch the URL
   change, reload the URL, confirm the control comes back set.

## Things that bite

**`wire:key` in every loop.** Livewire diffs the DOM between renders and
without a stable key it reuses the wrong element — a filter change leaves the
previous product's image under the new product's name. `wire:key` on the
outermost element inside any `@foreach`, keyed by the model's id.

**One root element per component view.** Livewire's diffing needs a single
node to anchor to. Two siblings at the top level fails at runtime, not at
compile time.

**Reset the page on any filter change.** `updated()` calls `resetPage()` for
every property except `page`. Without it, narrowing a filter while on page 4
of a now-single-page result set renders an empty grid, which reads to the
customer as "no results".

**Eager load whatever the card touches.** The component is responsible for its
own N+1 protection — nothing warns. `ProductList` loads `productImages`,
`brand`, and `productVariations.inventory` because the card reads all three.
A template change that reaches a fourth relation silently issues a query per
row.

**Writes still go through Actions.** Reading is the component's own business;
the moment a page changes state it calls an Action, exactly as a Filament
resource does. The cart page is the first storefront slice where this
matters — `AddToCart` gets its first real caller there.

## Styling

Tailwind 4 is configured in CSS, not JavaScript. There is no
`tailwind.config.js`; `resources/css/app.css` holds the `@theme` tokens, the
`@source` lines, and the two `@utility` animations.

The palette is one committed accent (`marine`) against a neutral ramp tinted
toward it (`ink`) rather than pure grey, two radii, one easing curve. New
values go in `@theme` and get used through the generated utility — a literal
hex in a template is the thing that makes a design drift.

The `@source` lines are load-bearing and non-obvious: Tailwind's automatic
source detection anchors at the git root, `.git` sits one level above
`online-store/`, and the container mounts only `online-store/`, so detection
finds nothing and only the explicit globs apply. If a utility class appears
not to work, read the entry in `how-to/troubleshooting.md` before editing the
template — the class is usually fine and simply was never compiled.
