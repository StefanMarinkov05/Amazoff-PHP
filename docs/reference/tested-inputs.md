# Tested inputs — what has been checked, where, and the result

The running index `docs/how-to/test-for-input-crashes.md` points at. One row
per property actually tested against the value playbook there — not one row
per component, since the playbook is per property (a component checked once
can still gain an untested property later). "Not yet checked" is not the
same as "safe" — it means exactly what it says.

Facts as of 2026-08-29, each verified against the running app (Livewire
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
| `brandId` | `?int` | — | Not yet run against this playbook; same shape as `categorySlug` numerically, worth a pass |
| `minPrice` / `maxPrice` | `mixed` | overflow (34-digit), non-numeric, negative, SQLi-shaped | No crash — resets to "no bound"; min pushed to match if it exceeds max |
| `minRating` | `mixed` | overflow, non-numeric, XSS-shaped, value outside `RATING_TIERS` | No crash — resets to `null` |
| `sortBy` / `sortDir` | `string` | — | Protected by `safeSortBy()`/`safeSortDir()`'s allow-list (ADR-0014); not independently re-tested this pass |

## `App\Livewire\Catalogue\ProductDetails`

| Property | Type | Playbook cases tried | Result |
|---|---|---|---|
| `quantity` | `mixed` (was `int` — see the incident in `test-for-input-crashes.md`) | overflow (34-digit), non-numeric, decimal, negative, in-range-but-past-stock | No crash — resets to `min_order_quantity` on unparseable input, clamps to `[min_order_quantity, stock]` otherwise |
| `variationId` | was `?int`, now `mixed`, `#[Url]` | overflow (34-digit, via `?v=` on the real route), non-numeric, non-matching id | **Crashed live** — `TypeError: Cannot assign float to property ... of type ?int` (an oversized numeric string decodes to a float during `#[Url]` hydration, which `?int` then refuses). Fixed: widened to `mixed`, normalised at the top of `mount()` before the existing "fall back to the default variation" branch runs. Confirmed red without the fix, green with it |

## Storefront authentication (`App\Livewire\Auth\*`)

| Component | Property | Type | Cases tried | Result |
|---|---|---|---|---|
| `Login` | `email` | `string` | 5000-char, XSS, SQLi | Validation error (`email:rfc\|max:100`), no crash |
| `Login` | `password` | `string` | — | Not independently tested; `required\|string` only, no `max:` — a very long password is valid input by the rules as written, not a bug, just unbounded on purpose (password length is not meant to be capped the way a name is) |
| `Register` | `first_name` | `string` | 5000-char, XSS | Validation error (`max:50`), no crash, no reflection |
| `Register` | `email` | `string` | — | Same rule shape as `Login`'s; not independently re-run |
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

`ContactForm::$website` (the honeypot field) not independently tested —
worth a pass to confirm a non-empty value there is actually silently
discarded/ignored rather than merely unused by the current happy path.

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
- `brandId` on `ProductList` (flagged above).
