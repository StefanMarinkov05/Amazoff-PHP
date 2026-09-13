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
| `productId` | was `?int`, now `#[Locked]` | overflow (34-digit) via direct `$set()` (not URL/route-bound — set once in `mount()`, never client-set) | **Crashed live** — `TypeError: Cannot assign string to property ... of type ?int`. SEC-014. No blade `$set`/`wire:model` targets it, so `#[Locked]` closes the crash with no feature loss. Confirmed red (bare `set()` succeeded instead of throwing `CannotUpdateLockedPropertyException`) without the fix, green with it — `tests/Feature/Livewire/ProductDetailsLockedIdsTest.php` |
| `imageIndex` | was `int`, now `#[Locked]` | overflow (34-digit) via direct `$set()` (set only by `setImage()`/`nextImage()`/`previousImage()`) | **Crashed live**, same shape as `productId`. SEC-014. Fixed and proven the same way, same test file |
| `reviewRating` | was `int`, now `mixed` | overflow (34-digit) via `$set()` (legitimately client-set: `wire:click="$set('reviewRating', N)"`) | **Crashed live** — `TypeError: Cannot assign string to property ... of type int`. SEC-014. Cannot be `#[Locked]` (the star-rating click needs to set it). Fixed: widened to `mixed`; `submitReview()`'s `integer\|min:1\|max:5` rule still guards what is persisted. Confirmed red without the fix, green with it — `tests/Feature/Livewire/ProductDetailsReviewRatingHydrationTest.php` |

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

## `App\Livewire\Checkout\OrderConfirmation`

A different binding path from every property above — `{order}` is a plain
route segment consumed by `mount(int $order)`, not a `#[Url]` query-string
property — but the same root shape: something types-coerces a request
value before the component's own code runs.

| Property | Type | Playbook cases tried | Result |
|---|---|---|---|
| `$order` (route param) | was `int`, now `mixed` | non-numeric (`abc`), decimal (`3.5`), overflow (34-digit), a real order id from a session with no claim to it | **Crashed live** — `TypeError: OrderConfirmation::mount(): Argument #1 ($order) must be of type int, string given` on `/checkout/confirmation/abc`. Route-model binding on a typed model (`Order $order`) would have 404d automatically via `ModelNotFoundException`; this route deliberately binds a plain `int` instead (`$orderId`'s own docblock explains the Livewire property-name collision that forces it), which loses that automatic handling. Confirmed live under local's `APP_DEBUG=true`: a full Ignition debug page to an anonymous visitor — exception class, `app/Livewire/Checkout/OrderConfirmation.php:60`, absolute `/var/www/html/vendor/...` paths, the full container dependency-resolution stack. Gated to a bare 500 once `APP_DEBUG=false`, but still an unhandled crash either way. Fixed: widened to `mixed`, `is_numeric` check throws the same `NotFoundHttpException` `authorizedOrder()` already throws for a well-formed id nothing matches — one 404 shape for "not an order" and "not your order" alike. Confirmed red without the fix (same `TypeError`, reproduced in the test suite), green with it — proven by 3 cases in `tests/Feature/Payment/CheckoutTest.php`. The codebase-wide sweep for this exact shape (a strictly-typed scalar route or `#[Url]` parameter) found no other instance — every other `mount()` taking a route parameter binds a model, not a scalar |

**Why this is Phase 3, not Phase 2.** The four `#[Url]` crashes were found by
testing individual properties against the playbook. This one was found by
the different discipline `how-to/pentest-the-system.md`'s Phase 3 exists
for — checking what an unhandled exception actually *shows* an anonymous
visitor, with `APP_DEBUG` at its real local value rather than assumed off.
The crash and the leak are two separate facts about the same bug: fixing
the crash closes the leak as a side effect, but a pass that only checked
"does this crash" would have found it via `test-for-input-crashes.md`'s
playbook regardless — the leak's *content* is what needed `APP_DEBUG=true`
and reading the actual response body to see.

## `App\Livewire\Checkout\OrderConfirmation` — `$orderId`

| Property | Type | Playbook cases tried | Result |
|---|---|---|---|
| `orderId` | was `int`, now `#[Locked]` | overflow (34-digit) via direct `$set()` (internal-only, set once in `mount()`, never legitimately client-set) | **Crashed live** — `TypeError: Cannot assign string to property ... of type int`. SEC-014. The one id-property in the codebase that broke the "every id-property is `#[Locked]`" convention every sibling (`CheckoutPage::$orderId`, `ProductDetails::$productId`/`$imageIndex`, `ManageAddresses::$editingId`) already follows. Confirmed red without the fix, green with it — `tests/Feature/Livewire/OrderConfirmationOrderIdTest.php`. Distinct from the route-param `$order` crash recorded above (a different property, a different binding path, fixed in an earlier pass) |

## `App\Livewire\Account\ManageAddresses` — `$editingId`

| Property | Type | Playbook cases tried | Result |
|---|---|---|---|
| `editingId` | was `?int`, now `#[Locked]` | overflow (34-digit) via direct `$set()` (set only by `startAdding()`/`startEditing()`/`cancelEditing()`/`save()`/`delete()`) | **Crashed live** — `TypeError: Cannot assign string to property ... of type ?int`. SEC-014. `save()`/`delete()` already owner-scope the lookup through `$this->user()->addresses()`, so this closed a crash, not an IDOR. Confirmed red without the fix, green with it — `tests/Feature/Livewire/ManageAddressesEditingIdTest.php` |

## `App\Livewire\Checkout\CheckoutPage` — `$carrier_id` / `$selected_address_id` / `$courier_office_name`

| Property | Type | Playbook cases tried | Result |
|---|---|---|---|
| `carrier_id` | was `?int`, now `mixed` | overflow (34-digit) via `wire:model.live` on the carrier radio group | **Crashed live** — `TypeError: Cannot assign string to property ... of type ?int`. SEC-014. Fixed: widened to `mixed`; `updated()` gained a normalising branch (numeric-or-null, same shape `ProductDetails::mount()` uses for `$variationId`) since nothing previously converted the raw client value back to a real id; the `rules()` entry (`integer`, `exists`) still guards `placeOrder()`. Confirmed red without the fix, green with it — `tests/Feature/Livewire/CheckoutPageHydrationTest.php` |
| `selected_address_id` | was `?int`, now `mixed` | overflow (34-digit) via `wire:model.live` on the saved-address picker | **Crashed live**, same shape. SEC-014. Fixed: widened to `mixed`; `updatedSelectedAddressId()` already normalised the value, so no new normalisation was needed. Confirmed red without the fix, green with it — same test file |
| `courier_office_name` | `string`, no rule | 200-char string (past the `varchar(150)` column) paired with a real, resolvable `courier_office_code` | **Crashed live before the fix, but not as a raw 500** — `QueryException: Data too long for column 'courier_office_name'`, which `placeOrder()`'s own `catch (InvalidArgumentException\|RuntimeException $e)` swallowed into a plausible-looking form error rather than surfacing as a crash. SEC-015. The comment "it is never customer input" described the rendered form, not the endpoint — a `public string` property is reachable via `$set()` regardless. Fixed: `placeOrder()` re-derives the name from the office `courier_office_code` resolves to, rather than trusting the client's copy at all. Confirmed red without the fix (order silently failed to place), green with it (order places, persisted name matches the resolved office, not the client's) — `tests/Feature/Payment/CheckoutTest.php`, `'re-derives courier_office_name from the resolved office rather than trusting the client value'` |

## `App\Livewire\Account\OrderDetails` / `RequestReturn` — the id-hydration sweep, and one further check

Sweep C (`docs/how-to/pentest-the-system.md`'s authenticated-account-page
pass): both components' id-bearing properties were already `#[Locked]`
before this pass — `OrderDetails::$orderId` and `RequestReturn::$orderId`,
both `public int`, confirmed against their blade views (route-model-bound
`Order $order` in `mount()`, never client-`$set`) — so neither needed a
SEC-014-style fix. Checking further turned up a different property on
`RequestReturn` with its own bug:

| Property | Type | Playbook cases tried | Result |
|---|---|---|---|
| `RequestReturn::$quantities` | `array`, documented `array<int, int>` (inaccurately) | nested array value (`[['nested' => 'garbage']]`), a foreign `order_item_id` | **The foreign-line case was clean** — `RequestReturnAction::handle()` refuses a line not on the locked order (`ReturnNotAllowedException::lineNotOnOrder()`), matching the earlier static review's claim. **The nested-array case was not clean** — no crash, but `submit()`'s blind `(int)` cast silently turned the garbage value into `1` (PHP casts any non-empty array to `int(1)`), creating a real `OrderReturn` row the customer never actually requested. SEC-016. Not exploitable past a genuine "return 1" (a non-empty array can never cast to more than `1`, and the Action's own quantity ceiling still applies), but a wrong answer that read as a correct one — the same class `test-for-input-crashes.md` names for `ProductList::$attributeValueIds`. Fixed: `is_numeric()` before the cast, refusing with a `quantities` form error instead of proceeding; the `@var array<int, int>` PHPDoc — which Larastan was treating as a certain type — corrected to `array<int, mixed>`. Confirmed red without the fix (silent success, `quantity=1` persisted), green with it — `tests/Feature/Livewire/Account/RequestReturnTest.php`, `'does not silently treat a garbage-shaped quantity as "return 1"'` |

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
Full writeup and screenshots: `reference/testing/ui-testing/phase-1-storefront-clickthrough.md`.

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
