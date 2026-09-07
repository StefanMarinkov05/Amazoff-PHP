# How to run a live storefront demo

A click-through script for presenting the store to a non-technical audience
— exact pages, exact accounts, exact SKUs, and why each stop earns its
place. Pairs with a short slide deck (open/close only); the demo itself is
the working app, not slides describing it.

This is not a tutorial and not project documentation for a maintainer — it
is presenter's notes for a one-time live walkthrough. See
`explanation/documentation-design.md` for why that distinction matters and
why this still lives in `how-to/`: running a demo is a specific job with
steps, which is what `how-to/` is for.

## Before presenting

1. Fresh, known-good data:
   ```bash
   docker compose exec app php artisan demo:seed --fresh
   ```
   `reference/schema/demo-data.md` is the full inventory this produces;
   this script only pulls the pieces worth narrating live.
2. Confirm the app is reachable at whatever host you'll present from — do
   this before the audience is watching, not during.
3. Log in as `customer@example.com` / `password` in one browser tab, and
   as an admin (`admin@example.com` / see `reference/local-access.md`) in
   a second — switching tabs beats logging in and out live.
4. Have `ORD-000141` through `ORD-000154` written down (see below) —
   re-seeding renumbers them, so pull the actual serials right before
   presenting, not from this file.

## The walkthrough

### 1. Catalogue, as a shopper (no login)

Land on `/catalogue`. This is deliberately the first thing anyone sees —
no login wall on browsing, only on checkout.

- Open a product with variations (any T-shirt) — show size/colour picker,
  point out the price updates live per variation.
- Apply a category filter and a price filter together — the point being
  the filters combine, not replace each other.
- **Skip explaining the URL.** Filter state lives in the query string on
  purpose (`?category=...&price_max=...`) so a filtered view is
  shareable/bookmarkable — worth one sentence if asked, not part of the
  scripted path.

### 2. The curated tour (staff only — switch to the admin tab)

This is the strongest single stop in the demo: 13 products, hand-picked,
each demonstrating one specific thing the catalogue schema can do, in a
fixed order. `reference/demo-showcase-order.md` is the full record of why
each one was picked.

On `/catalogue`, signed in as staff, open the sort dropdown and pick
**Demo order**. Walk a subset live rather than all 13 — pick 4-5 depending
on time:

| Pick | Product | Say this |
|---|---|---|
| `CLM-0001` Classic Crew Neck T-Shirt | 5 variations, real gaps (no White+L, no Black+XL) | "Not every colour comes in every size — the catalogue models that honestly instead of pretending a full grid exists." |
| `PWR-0010` 18V Cordless Combo Kit | Category is a parent node that also holds products directly | "Categories aren't just leaves — a parent can hold its own products alongside its children's." |
| `CLM-0004` Merino Wool Crew Sweater | Active discount window right now | Show the struck-through price live. |
| `ELC-0015` Portable Bluetooth Speaker | Listed but zero stock everywhere | "Being listed and being buyable are two different flags — this is deliberately still visible, just not purchasable." |
| `BTY-0001` Hydrating Face Moisturiser | No images at all | Show the graceful fallback, not a broken-image icon. |

Switch back out of demo mode before continuing (reselect the default
sort) — the audience doesn't need to see you reset it, just don't leave
the store looking artificially curated for the next stop.

### 3. A full order lifecycle, one login

Switch to the `customer@example.com` tab. This account owns **all eleven
`OrderStatus` cases**, so the entire order lifecycle is one login away —
`/account/orders` shows the whole list already.

Pull up 3-4 in order to narrate the story of one order type end to end
(exact serials shift on re-seed — these are this run's, confirm before
presenting):

1. A `Delivered` Stripe order with tracking events — open it, scroll the
   tracking history, show the courier integration is real, not a static
   label.
2. The `PartiallyRefunded` payment order — show the payment section
   reflecting a partial amount back, not a full reversal.
3. A `Confirmed` cash-on-delivery order — point out COD skips
   `AwaitingPayment`/`Paid` entirely; nothing was collected yet.
4. If there's time: the `Returned` order carrying a coupon — checkout,
   discount, and the return path all in one record.

### 4. Guest checkout + public order tracking

Open a private/incognito window — no login anywhere in this stop.

- Add something to cart, checkout as a guest (COD is the faster path,
  fewer external calls to wait on live).
- After confirmation, go to the public order-tracking page and look the
  order up by **serial number and email together** — say the pairing
  explicitly: "this needs both, on purpose — a serial alone would let
  anyone page through every order in the store."

### 5. Close: the admin side, briefly

Back to the admin tab, `/admin`. Don't tour every resource — one useful
stop: open the order just placed in step 4 and change its status through
the **Change status** menu, so the audience sees the same order from the
side that manages it, and that changing status is a controlled action
(a menu of legal next states), not a free-text field.

## If something breaks live

- A blank cart/wrong state → the seed didn't run fresh; don't debug live,
  move to a pre-screenshotted fallback (see the slide deck's closing
  slide) and mention it's a seed-data artifact, not a bug.
- Checkout hangs on Stripe → COD was the safer path for exactly this
  reason; switch to it rather than waiting out a live payment call.
- Anything else → narrate past it ("that's one for the backlog") and keep
  moving. A recovered stumble reads better than a long silent pause.
