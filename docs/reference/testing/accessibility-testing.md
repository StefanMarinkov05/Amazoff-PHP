# Accessibility testing — axe-core against the storefront critical path

Part of the [testing reference](README.md). `browser-testing.md`'s own
"Layout only" gap named this explicitly: colour contrast and screen-reader
output were not audited there. This page closes the colour-contrast half —
screen-reader walkthroughs and keyboard-only navigation are still open, see
"What this page is not" below.

**Date of record:** 2026-09-14. Scope: the storefront's critical purchase
path — `/catalogue`, a product detail page, `/cart`, `/checkout`, and the
order confirmation page reached by actually placing a cash-on-delivery
order — not the admin panel and not the full storefront (journal, account
pages, legal pages are unscanned). Method: `axe-core` 4.10.2, injected via
Playwright's `addScriptTag()` directly into the page's execution context
rather than a `<script src>` tag — `SetSecurityHeaders`'s CSP correctly
blocks loading a CDN script on the real page (`script-src` has no
`cdnjs.cloudflare.com` entry, deliberately), and that block firing is
itself a small confirmation the CSP does what it claims; the same
enforcement point is what a real attacker's injected script would hit.

## Finding 1 — a stray nested `<main>` landmark on `/catalogue` — fixed live

**What axe found.** Three violations, one root cause:
`landmark-main-is-top-level`, `landmark-no-duplicate-main`,
`landmark-unique` — all pointing at the same two elements. The storefront
layout already declares `<main id="main-content">`
(`resources/views/components/layouts/app.blade.php`), and
`resources/views/livewire/catalogue/product-list.blade.php:295` opened a
second, bare `<main>` around the facets-and-grid column, nested inside the
first. A screen-reader user navigating by landmark ("go to main content")
gets two identically-unlabelled targets, with no way to tell which holds
the actual page content.

**Fixed.** `<main>` → `<div>` at both the opening (line 295) and closing
tag — this region was never a second top-level content area, just a
two-column layout's right-hand column sitting inside the real landmark
already provided one level up. No visual change; `<div>` carries no
default styling `<main>` didn't already lack here. Verified live: reloaded
`/catalogue`, re-ran axe, all three landmark violations gone, only
`color-contrast` (Finding 2) remains. Present only on `/catalogue` — the
product/cart/checkout/confirmation scans below never showed a landmark
violation, confirming this was local to that one template, not the shared
layout.

## Finding 2 — systemic colour-contrast failure, not fixed

**What axe found**, exact colour pairs and ratios, `/catalogue` (post-Finding-1-fix):

| Foreground | Background | Ratio measured | WCAG AA needs | Occurrences |
|---|---|---|---|---|
| `#798898` (`--color-ink-400`, `oklch(0.62 0.03 250)`) | `#ffffff` | **3.62:1** | 4.5:1 | 38 |
| `#798898` (`--color-ink-400`) | `#f1f6fa` | **3.33:1** | 4.5:1 | 1 |
| `#556577` (`--color-ink-500`, `oklch(0.5 0.035 252)`) | `#010515` | **3.39:1** | 4.5:1 | 2 |
| `#fd9f07` (brand orange) | `#f1f6fa` | **1.9:1** | 4.5:1 | 1 (the "Amazoff" wordmark) |

**Per-page occurrence counts**, same failure family, across the critical
path (`resources/css/app.css`'s design tokens, not a per-page bug — the
same colours recur everywhere `text-ink-400`/`text-ink-500` is used):

| Page | `color-contrast` nodes |
|---|---|
| `/catalogue` | 42 |
| Product detail | 17 |
| `/cart` | 16 |
| `/checkout` | 6 |
| Order confirmation | 4 |

**Not a one-token fix, checked live rather than assumed.** Swapping
`--color-ink-400` to `ink-500`'s own darker value
(`oklch(0.5 0.035 252)` → `#556577`) via a live CSS-variable override and
re-scanning dropped the catalogue's count from 42 to 18 — real
improvement, not a full fix. The remaining 17 are `text-ink-500` used
directly (a *darker* shade than `ink-400`, still short of 4.5:1 at the
smaller font sizes those particular elements use — WCAG's ratio threshold
tightens as text gets smaller within the "normal text" band), and 1 is the
unrelated orange wordmark. Trying `ink-600` next (`oklch(0.4 0.045 254)`)
held at 18 — confirming the remaining failures are not `ink-400`-sourced
at all and a global token swap cannot reach them. **The real fix needs a
deliberate pass through `resources/css/app.css`'s `--color-ink-*` scale
checking every shade actually used for text against every background it
appears on**, not a single value edit — recorded here as scoped, not
attempted as a guess.

**Why this was left unfixed, unlike Finding 1.** Finding 1 was a one-tag
change with zero visual consequence. This one touches the visible design
of every page in the storefront simultaneously, is a real design decision
(which shade replaces which, and whether the palette's overall feel
changes), and was explicitly scoped as "record, don't fix" before this
pass started.

## What this page is not

Not a full WCAG audit — `axe-core`'s automated ruleset catches roughly a
third of WCAG 2.1 success criteria by Deque's own account; missing
form-field associations, ARIA misuse, and most keyboard-interaction
requirements need a human pass axe cannot substitute for. Specifically not
covered here, both explicitly deferred alongside chaos/failure-injection
testing in `misc/todo.md`:

- **Screen-reader walkthroughs.** Whether the page actually *reads*
  sensibly with VoiceOver/NVDA, not just whether its markup passes
  automated landmark/label checks.
- **Keyboard-only navigation.** Tab order, focus visibility, whether every
  interactive element (including the variation pickers and quantity
  stepper on a product page) is reachable and operable with no mouse.

Not the admin panel — `browser-testing.md`'s own scoping note ("§37 #19 is
about the storefront") applies here too; Filament's own accessibility is
upstream's to audit. Not every storefront page — journal/article pages,
account pages, and the legal pages were not scanned this pass.
