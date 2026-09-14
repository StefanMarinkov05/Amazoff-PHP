# How to write a storefront page

Every way UI is written in this project, and what breaks each one.
`explanation/storefront-pages.md` is the shape of a page and why;
ADR-0014 is why a read skips an Action; this is the working reference for
Blade, Livewire, Alpine and Tailwind as they are actually used here.

Read it before adding a view. Most of the entries below are failures that
render a *plausible-looking page* rather than an error, which is why they
are worth writing down.

## The four kinds of view, and how to choose

| Kind | Lives in | Use when | Example |
|---|---|---|---|
| Layout | `views/components/layouts/` | Wraps every page | `app.blade.php` |
| Livewire view | `views/livewire/{area}/` | The page has state or a query | `journal/article-list` |
| Blade component | `views/components/{area}/` | Reused markup, no state | `journal/cover` |
| Plain view | `views/pages/` | No state, no query | `pages/about` |

**A page earns a Livewire component by having something that changes.**
`/about` is `Route::view('/about', 'pages.about')` because nothing on it
moves. Reaching for a component there is the pattern applied out of habit.

**A Blade component is not a Livewire component.** `<x-journal.cover>` is
markup and `@props`; it cannot hold state, cannot have `wire:click`, and
re-renders with its parent. That is the right tool for anything presentational.

## Blade

### Escaping — the rule with no exceptions

`{{ }}` escapes. `{!! !!}` does not.

**There is currently no `{!! !!}` anywhere in `resources/views`.** Keep it
that way. The one place raw HTML is rendered — an article body — goes
through `Article::$safe_content`, an accessor returning an `HtmlString`, so
Blade renders the HTML from `{{ }}` and no template writes the unescaping
syntax at all. ADR-0015.

Anything an author or a customer typed is untrusted: article bodies, review
bodies, contact messages. If you need raw HTML, add an accessor that
sanitizes, do not reach for `{!! !!}` in the view.

### `@php` blocks at the top

Views open with one `@php` block computing what the template reuses:

```blade
@php
    $price = $this->price;
    $gallery = $this->gallery;
    $main = $gallery[$this->imageIndex] ?? $gallery->first();
@endphp
```

This is deliberate — each `$this->x` is a computed property, and reading it
five times inside the markup is five lookups and a much noisier template.
Compute once at the top, use the local below.

Keep it to assignments. Business logic belongs in the component.

### Directives in use

`@if` / `@unless`, `@foreach`, `@forelse`…`@empty`…`@endforelse`,
`@error`…`@enderror`, `@auth` / `@guest`, `@csrf`, `@props`, `@vite`,
`@disabled`.

`@forelse` is preferred over `@foreach` wherever an empty state exists —
it puts the empty case next to the loop instead of in a separate `@if`.

`@disabled($condition)` renders the attribute only when true. Do not write
`disabled="{{ $x }}"` — `disabled="false"` is still disabled.

## Livewire

### Accessing computed properties

`#[Computed]` methods are read **as properties**: `$this->articles`, never
`$this->articles()`. With parentheses it is a plain method call that
**bypasses memoisation** and re-runs the query on every read — no error, no
warning, just a page issuing the same query four times.

Larastan cannot see through `__get()`, so every component needs a
`@property-read` block in its class docblock or the analyser reports every
access as an undefined property.

### `wire:key` — mandatory in every loop

```blade
@foreach ($this->articles as $article)
    <li wire:key="article-{{ $article->id }}">
```

Livewire diffs the DOM between renders. Without a stable key it reuses the
wrong element, and the symptom is subtle: a filter change leaves the
previous item's image under the new item's title. Key by the model id, never
by `$loop->index`.

It is needed on `<img>` too when the source changes with state —
`product-details` keys the main image by image id, because otherwise the
browser keeps painting the old bitmap and the click looks ignored.

### One root element

A Livewire view must have exactly one root element. Two siblings at the top
level fails at runtime, not at compile time.

### `wire:model` variants, and which to use

| Variant | Sends on | Used for |
|---|---|---|
| `wire:model` | form submit | Login, register — nothing needs to react per keystroke |
| `wire:model.blur` | leaving the field | Contact form validation |
| `wire:model.live` | every change | Selects and toggles, where a filter must apply at once |
| `wire:model.live.debounce.400ms` | change, delayed | Text inputs that drive a query |

**Never `wire:model.live` on a bare text input that filters a query** — it
issues a request per keystroke. Debounce it.

### `wire:loading` and `wire:target`

Any action that opens a transaction needs feedback:

```blade
<button wire:click="addToCart" wire:loading.attr="disabled" wire:target="addToCart">
    <svg wire:loading wire:target="addToCart" class="animate-spin">…</svg>
    <span wire:loading.remove wire:target="addToCart">Add to cart</span>
</button>
```

**`wire:target` is not optional when the page has more than one action.**
Without it every `wire:loading` on the page fires for every request, so
clicking a filter spins the add-to-cart button.

`wire:loading.class="opacity-40"` on a results container is the house
pattern for "this list is refreshing".

### `#[Url]` is attacker-controlled

Any property with `#[Url]` arrives from the query string and can be
anything. Two rules:

1. **Allow-list anything that reaches a query.** `ProductList::SORTS` is
   the allowed sort columns; `safeSortBy()` falls back rather than trusting.
   Without it `?sortBy=` lands in `orderBy()`.
2. **Validate ids by lookup, not by type.** `ProductDetails::variation()`
   resolves `?v=` only if the id is in `variations()`, which is already
   scoped to this product and to `is_available`. An id from another product
   returns null instead of leaking its price.

Do not put `#[Url]` on something that is not a user choice. A detail page's
`$articleId` comes from route-model binding — marking it `#[Url]` would let
someone point it elsewhere after `mount()` validated the slug.

`#[Url]` names appear in shared links, so renaming one breaks every bookmark.

### `updated()` resets pagination

```blade
public function updated(string $property): void
{
    if ($property !== 'page') {
        $this->resetPage();
    }
}
```

Without it, narrowing a filter while on page 4 of a now-single-page result
renders an empty grid, which reads to the customer as "no results".

### Busting the computed cache

`#[Computed]` memoises for the request. When an action changes the thing a
computed derives from, `unset()` every affected one:

```blade
$this->variationId = $match->getKey();
$this->imageIndex = 0;
unset($this->variation, $this->price, $this->stock, $this->gallery);
```

Forget one and the page renders a stale value with no error.

### Eager loading is per-component

Nothing warns about N+1 — `preventLazyLoading()` is deliberately off
(ADR-0012). Every component eager-loads what its own template touches, and
a template change that reaches a new relation silently adds a query per row.

`$model->relation` reads loaded data; `$model->relation()` returns a query
builder and **throws away the eager load**. That distinction has caused a
real bug here.

## Alpine

Alpine ships with Livewire — no separate install. In use: `x-data`,
`x-init`, `x-show`, `x-cloak`, `x-collapse`, `x-transition`, `x-bind`,
`x-on`.

**Use Alpine only for state the server does not need**: a mobile menu
toggle, a reading-progress bar, a dropdown. Anything the server must know
about is Livewire.

`x-cloak` on anything hidden by default, or it flashes visible for one frame
before Alpine boots. The `[x-cloak] { display: none !important }` rule is
already in `app.css`.

Passive listeners on scroll:

```blade
@scroll.window.passive="track()"
```

Without `.passive` the browser cannot optimise scrolling.

Animate `transform`, not layout. The reading-progress bar scales on the GPU
rather than writing a `width` per frame.

## Tailwind

### The trap that has cost the most time

**Tailwind 4 anchors source detection at the git root**, `.git` is one level
above `src/`, and the container mounts only `src/`. So
detection finds nothing and only the explicit `@source` globs in
`resources/css/app.css` apply.

The consequences:

- A class that "does not work" has usually **never been compiled**. Check
  the stylesheet before editing the template.
- **A new Blade file may not be picked up until Vite restarts.** This has
  happened repeatedly. `docker compose restart vite`.
- Verify with a substring count, not a line count — in dev, Vite serves CSS
  wrapped in a JS module, so the whole sheet is one line:

```bash
curl -s http://localhost:5173/resources/css/app.css | grep -c 'md...grid-cols-2'
```

Note the escaping: `md:grid-cols-2` appears as `md\\:grid-cols-2`, so match
the colon with `...` rather than a literal `:`.

`how-to/troubleshooting/assets-vite-frontend.md` has the full entry.

### Version syntax

Tailwind 4, configured in CSS — **there is no `tailwind.config.js`**.
Tokens live in `@theme` in `resources/css/app.css`.

v3 arbitrary-value syntax silently does not compile. `bg-[length:0%_2px]`
is v3; v4 wants `bg-size-*`. If an arbitrary value produces no rule, suspect
the syntax before the build.

### Tokens, not literals

| Token | Where |
|---|---|
| `ink-50…950` | Neutral ramp, tinted toward the accent |
| `marine-50…900` | Shop accent |
| `ember-50…900` | Journal accent — deliberately not used on the catalogue |
| `radius-card`, `radius-control` | The only two radii |
| `font-sans`, `font-display` | Instrument Sans; Instrument Serif for the journal |

A literal hex in a template is how a palette drifts. Add to `@theme`.

### `@utility` for anything a class cannot express

Keyframe animations, the article body's typography, the underline sweep.
`@utility` makes it a real Tailwind class that variants can modify.

`article-body` exists because **`@tailwindcss/typography` is not
installed** and Tailwind's reset strips heading and list styling — without
it, sanitized article HTML renders as one undifferentiated block.

### Positioned elements paint above static ones

A `relative` element renders above a non-positioned sibling **regardless of
DOM order**. The journal's lead story hit this: the cover is `relative` for
its gradient layers, so it covered the text that came after it in the markup
until the text got `relative z-10`.

If something overlaps that should not, check `position` before `order`.

## Accessibility — the baseline that is already met

Do not regress these; they are present across every view.

- **`focus-visible:ring`** on every interactive element (61 uses). Never
  remove a focus ring without replacing it.
- **`aria-hidden="true"`** on every decorative SVG (54 uses) — otherwise a
  screen reader announces them.
- **`aria-label`** on icon-only buttons (gallery arrows, thumbnails).
- **`sr-only`** for text that must exist for assistive tech but not on
  screen — the skip link, star-rating values, colour-swatch names.
- **`aria-current`** on the active nav item, filter and thumbnail.
- **`aria-invalid` + `aria-describedby`** wired to `@error` on every form
  field, with `role="alert"` on the message.
- **`prefers-reduced-motion`** — `app.css` disables all animation under it.
  A new animation is covered automatically; do not add inline animation
  that escapes it.
- Semantic elements: `<nav aria-label>`, `<ol>` for a breadcrumb and the
  journal index, `<article>`, `<time datetime>`, `<dl>` for metadata.
- **`type="button"`** on every button that is not submitting a form — the
  default is `submit`, which reloads the page inside a `<form>`.

## Images

- Model-owned files: `Storage::url($image->path)` on the `public` disk.
  The `public/storage` symlink must exist or every image 404s.
- Shipped assets: `asset('images/logo.png')`.
- Fonts: `Vite::fonts()` in the layout head — **separate from `@vite`**. It
  is easy to omit, and the failure is silent: the manifest is generated and
  nothing injects it.
- **Generated artwork** is a real fallback when no file exists yet.
  `<x-journal.cover>` prefers a real photo via `ResolveArticleImage::urlOrNull()`
  when `main_image_path` points at one; otherwise it draws from
  `crc32($article->slug)` — deterministic, so the artwork survives a
  re-seed, and it cannot 404. Same reasoning as `App\Support\PlaceholderImage`.

Before rendering a stored path, know whether the file exists — `Storage::disk($disk)->exists($path)`,
not an assumption from the row having a non-null column. `demo:fetch-article-images`
fills in real photos for the demo catalogue (`how-to/seed-the-database.md`,
"Article images"); until it is run, or an editor uploads one through the
panel, every article still falls back to generated art.

## Layout that survives editing

Use flex/grid with `gap`, not margins or whitespace between siblings. Group
buttons, chips and cards with `display: flex` + `gap`, so deleting or
reordering one does not leave orphaned spacing.

## Before you commit

```bash
docker compose exec app ./vendor/bin/pint
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app ./vendor/bin/pest --parallel --processes=4 --testsuite=Feature
```

Then **look at the page**. Larastan proves types, Pest proves the paths it
covers; neither renders anything. Every UI bug in this project's history —
uncompiled classes, the empty `setImage()`, the paint-order overlap, missing
image files — passed both gates.

Check a phone width too. `md:` is 48rem, and Windows at 125–150% display
scaling reports a CSS viewport well under 1024px on an ordinary 1080p
screen, so a `lg:`-only layout is single-column on a lot of real laptops.
