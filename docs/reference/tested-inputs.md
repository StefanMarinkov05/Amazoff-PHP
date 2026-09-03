# Tested inputs — what has been checked, where, and the result

The running index `docs/how-to/test-for-input-crashes.md` points at. One row
per property actually tested against the value playbook there — not one row
per component, since the playbook is per property (a component checked once
can still gain an untested property later). "Not yet checked" is not the
same as "safe" — it means exactly what it says.

Facts as of 2026-09-03, each verified against the running app (Livewire
component tests, some cross-checked with live `curl` against
`http://localhost:8080`) — not read off the code, not assumed from a
property's type alone.

## `App\Livewire\Catalogue\ProductList`

Full contract, including combinations and the specific reasoning per filter,
is `docs/reference/write-rules/catalogue-filters.md`. Summary:

| Property | Type | Playbook cases tried | Result |
|---|---|---|---|
| `search` | `string` | XSS, SQLi, 5000-char string | No crash, no reflection, no query effect from the payload |
| `categorySlug` | `string` | XSS, SQLi, 5000-char string, non-matching slug | No crash, no reflection; falls back to unfiltered — parameterised `where()`, structural |
| `brandId` | was `?int`, now `mixed`, `#[Url]` | overflow (34-digit), non-numeric, XSS-shaped, SQLi-shaped, negative, decimal | **Crashed live** — `TypeError: Cannot assign float to property App\Livewire\Catalogue\ProductList::$brandId of type ?int`, the third instance of this project's numeric-`#[Url]`-hydration incident (`quantity`, `variationId`, now `brandId`). Fixed: widened to `mixed`, normalised via `safeBrandId()` at both read sites. Confirmed red without the fix (same `data_set()` line, same exception), green with it — proven by `tests/Feature/Livewire/ProductListBrandFilterTest.php` |
| `minPrice` / `maxPrice` | `mixed` | overflow (34-digit), non-numeric, negative, SQLi-shaped | No crash — resets to "no bound"; min pushed to match if it exceeds max |
| `minRating` | `mixed` | overflow, non-numeric, XSS-shaped, value outside `RATING_TIERS` | No crash — resets to `null` |
| `attributeValueIds` | `mixed`, `#[Url]`, **array-shaped** | nested array, scalar-instead-of-array, non-numeric element, overflow element, empty string element, mixed valid+garbage | **Silently applied a filter nobody chose.** No crash — the property was already `mixed`, so the `brandId` hydration-crash class never applied. The defect was one layer down and quieter: `intval()` of a *non-empty array* is `1`, not `0`, so `?attributeValueIds[0][0]=1&attributeValueIds[0][1]=2` collapsed onto the real, filterable id `1` — "Colour: Black" in the seeded catalogue. Confirmed live before the fix: 164 products → 37, with a "Black" chip rendered as though the visitor had picked it. A garbage *string* was always harmless by comparison (`intval('abc') === 0`, matching nothing); the array shape alone fabricates a plausible id. Root cause: four readers each cast for themselves and diverged — only `updatedAttributeValueIds()` filtered on `is_numeric` first, and `updated*` hooks never run on a first page load from `#[Url]` hydration, which is exactly where a crafted URL lands. Fixed with one shared `safeAttributeValueIds()` all four now use. Proven by 3 cases in `ProductListAttributeFilterTest`, each verified red without the guard |
| `sortBy` / `sortDir` | `string` | XSS, SQLi, 5000-char, non-allow-listed value, empty | No crash. `safeSortBy()`'s allow-list holds — confirmed by comparing the first product's slug across every garbage value against the `created_at desc` default: identical every time, so nothing reaches `ORDER BY` unfiltered. `safeSortDir()`'s binary fallback confirmed the same way: a garbage value produces output byte-identical to explicit `desc`, and explicit `asc` genuinely differs, proving the branch is live rather than coincidentally always taking one side |

## `App\Livewire\Catalogue\ProductDetails`

| Property | Type | Playbook cases tried | Result |
|---|---|---|---|
| `quantity` | `mixed` (was `int` — see the incident in `test-for-input-crashes.md`) | overflow (34-digit), non-numeric, decimal, negative, in-range-but-past-stock | No crash — resets to `min_order_quantity` on unparseable input, clamps to `[min_order_quantity, stock]` otherwise |
| `variationId` | was `?int`, now `mixed`, `#[Url]` | overflow (34-digit, via `?v=` on the real route), non-numeric, non-matching id | **Crashed live** — `TypeError: Cannot assign float to property ... of type ?int` (an oversized numeric string decodes to a float during `#[Url]` hydration, which `?int` then refuses). Fixed: widened to `mixed`, normalised at the top of `mount()` before the existing "fall back to the default variation" branch runs. Confirmed red without the fix, green with it |

## `App\Livewire\Journal\ArticleList`

Found by the full-codebase grep `test-for-input-crashes.md` recommends
running once a third instance of this incident class turns up — it did, and
this was the fourth, already crashing live before the sweep even finished.

| Property | Type | Playbook cases tried | Result |
|---|---|---|---|
| `categoryId` | was `?int`, now `mixed`, `#[Url]` | overflow (34-digit), non-numeric, XSS-shaped, negative, decimal, non-matching id | **Crashed live** — identical `TypeError: Cannot assign float to property App\Livewire\Journal\ArticleList::$categoryId of type ?int`, same `data_set()` line as `ProductList::$brandId`. Fixed the same way: widened to `mixed`, normalised via `safeCategoryId()` at the one read site. Confirmed red without the fix, green with it — proven by `tests/Feature/Livewire/ArticleListCategoryFilterTest.php` |
| `tag` | `?string` | XSS, SQLi, 5000-char, non-matching value, empty | No crash, no reflection (`<script>` count in response: 0). Unlike `categorySlug`, a non-matching tag does **not** fall back to unfiltered — it is a real `whereHas('tags', …)` constraint, so a garbage value correctly narrows to zero articles (`16 pieces` baseline → `0 pieces`), not "no filter applied." Worth stating explicitly since the two `?string` filter properties on this codebase's two list components behave differently on a miss, and assuming they match would be wrong |

The sweep that found this (`grep` across every `app/Livewire/*/*.php` for a
`#[Url]` immediately preceding a strictly `int`/`float`-typed property)
found nothing else — `categoryId` was the last instance of this exact shape
in the codebase as of 2026-09-03.

## Storefront authentication (`App\Livewire\Auth\*`)

| Component | Property | Type | Cases tried | Result |
|---|---|---|---|---|
| `Login` | `email` | `string` | 5000-char, XSS, SQLi | Validation error (`email:rfc\|max:100`), no crash |
| `Login` | `password` | `string` | 5000-char, XSS, SQLi, empty | No crash on any payload, including the 5000-char one — `required\|string` only, no `max:`, unbounded on purpose. Every non-empty wrong password produced the identical generic message ("These credentials do not match our records") regardless of payload shape — no enumeration signal leaks through abuse. The real admin account still logged in normally immediately afterward, confirming the attempts did not corrupt state or (at this count) trip the 5-per-minute rate limit |
| `Register` | `first_name` | `string` | 5000-char, XSS | Validation error (`max:50`), no crash, no reflection |
| `Register` | `email` | `string` | 5000-char, XSS, malformed (`not-an-email`), empty, RFC-legal-but-unusual (`admin'--@example.com`) | 5000-char, XSS-shaped, and malformed all correctly refused (`email:rfc\|max:100`). One case initially looked like a bypass — `admin'--@example.com` created a user — until checked against Laravel's own `email:rfc` validator directly: it **passes** RFC 5322, because an unquoted `'` is legal `atext` in a local-part. Not a bug; a reminder that a string merely *looking* SQLi-shaped does not make it invalid input. Confirmed separately that the users table was untouched by the attempt (parameter binding held) and cleaned up the one legitimately-created test row afterward |
| `ChangePassword` | `current_password` | `string` | 5000-char | Validation passes through to `current_password` rule, which itself rejects a wrong value — no crash |

All three are `string`-typed throughout — the hydration-overflow case
(`quantity`'s original bug) does not apply structurally to any property on
these three components.

## Contact and newsletter (`App\Livewire\Contact\*`)

| Component | Property | Type | Cases tried | Result |
|---|---|---|---|---|
| `ContactForm` | `message` | `string` | 5000-char (rule caps at 2000) | Validation error, no crash |
| `ContactForm` | `name` | `string` | XSS | No reflection — `->assertDontSee` confirmed the raw `<script>` tag never appears unescaped |
| `NewsletterSignup` | `email` | `string` | 5000-char, XSS | Validation error (`email:rfc\|max:100`), no crash |

`ContactForm::$website` (the honeypot) — **tested (2026-09-03)**, combined
with XSS in the same submission (`name`, `subject`, `message` all
`<script>alert(document.cookie)</script>`, honeypot filled). Confirmed: the
component reports `sent = true` (fake success shown) while
`ContactMessage::count()` is unchanged — a non-empty honeypot value is
genuinely discarded, not merely unused by the happy path. Isolated from
that: the same XSS payload with the honeypot empty **is** stored raw (the
literal tag in the database, correct — sanitisation is a render-time
concern here, not a write-time one) and renders as inert escaped text in
both the admin table and detail view, confirmed at the `innerHTML` level.
Full writeup and screenshots: `reference/ui-testing/phase-1-storefront-clickthrough.md`.

## `App\Filament\Widgets\*` (admin dashboard)

Not yet run. These are staff-only (behind `canAccessPanel()`), which lowers
the value of a customer-facing injection pass but does not zero it — a
compromised or careless staff account is still a real actor, and several
widgets accept no direct input, so this is mostly about confirming that
absence rather than assuming it.

## Not yet covered

- Filament resource forms (`ProductForm`, `CouponForm`, and the rest) —
  staff-only, but §37's authorization requirements apply regardless of who
  is behind the keyboard.
