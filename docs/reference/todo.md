# Future plans

Committed, forward-looking roadmap items — distinct from `misc/todo.md`
(gitignored, the actual working queue for whoever picks up a session next)
and from `docs/reference/testing/` (what testing has already proven or
found broken). This page is neither: it is the set of larger, deliberately
deferred decisions and features that don't yet have a session's worth of
work queued against them, kept here so the reasoning behind "not yet" is
visible to anyone reading the reference tree rather than living only in a
gitignored file or a closed conversation.

**A note on this file's own home**: `docs/reference/README.md` describes
this folder as "just the facts... no opinions." A forward-looking plan is
closer to a wish list than a fact, and arguably belongs in
`docs/explanation/` instead. Kept here anyway, on explicit request, because
the reference tree is what a returning contributor reads first — flagged
rather than silently filed as if it were a natural fit.

## Audit logging

`spatie/laravel-activitylog` is installed, not wired to anything. The
decision, made and recorded in `misc/todo.md`, is to **wait** rather than
build speculatively — there is no forcing function yet (no compliance
requirement, no support incident that needed "who changed this and when"
answered). When it moves:

- Scope it to specific models first, not a blanket "log everything." The
  candidates that would earn their place fastest: `Order` (status changes
  already have `order_status_histories`, but activitylog would add *who*
  more generally, including non-status edits), `Shipment`, `User` (role
  changes), `Coupon` (value/limit edits after redemptions exist).
- Keep it out of the admin UI in the first pass — a browsing/filtering
  audit-log page is real, separate feature work, not a byproduct of adding
  the package's own model observers.
- Revisit `ContactMessageReceived`'s reasoning-by-analogy: several existing
  domain event/history tables (`order_status_histories`,
  `shipment_tracking_events`) already do a narrower version of what
  activitylog would generalize. Decide whether activitylog *replaces* any
  of those or sits beside them before writing the first observer — two
  audit mechanisms recording the same fact differently is worse than one.

## Localization (database-backed translations)

`astrotomic/laravel-translatable` is installed, explicitly **planning-only**
per `misc/todo.md` — the risk named when this was raised is letting "write
down the idea" turn into a schema change under time pressure. Nothing here
is a commitment to ship; it is the shape the decision would take if it
moves.

**What would need translating**: product `name`/`short_description`/
`description`, `ProductCategory`/`Brand`/`Tag` names, `Article` `title`/
`summary`/`content`, and the storefront's own static strings (currently
plain English throughout — `laravel-lang/common` is installed for the
latter and unused for the same "no forcing function yet" reason).

**Open questions, not yet answered**:

- **Which content actually needs translation vs. which is fine staying
  English-only for this project's scope** (an internship project's
  storefront, not a multi-region retailer) — deciding the boundary before
  the schema change is what keeps this from becoming "translate
  everything."
- **Translatable columns live in per-model `_translations` tables**
  (astrotomic's convention) — this is an additive migration per translated
  model, appended after the schema freeze per this project's own migration
  discipline, not a retrofit of existing tables.
- **Fallback locale behaviour** when a translation is missing — astrotomic
  supports this, but the specific fallback chain (does an untranslated
  Bulgarian product fall back to English, or show nothing?) is a product
  decision, not a technical default to accept blindly.
- **Filament's admin forms** would need per-locale input tabs on every
  translated resource — real UI work, not just a schema change, and worth
  sizing separately once the schema shape is settled.
- **Interacts with search**: `full-text search over LIKE` (below) and
  localized content are not independent decisions — a full-text index
  strategy chosen before localization lands may need revisiting once
  product names/descriptions exist in more than one language per row.

## Courier slice completion

`SyncShipmentTracking` (Action + Job + scheduler, 2026-09-14) is the
connector-*reading* half. Two pieces of the connector-*writing* half remain,
tracked in `misc/todo.md`'s Group C in full detail — summarized here as the
forward plan:

- **`DispatchShipment`** — the Action that would actually call
  `CourierManager` to create the vendor shipment and fill in
  `tracking_number`/`label_path`/`courier_tracking_url`/`shipment_number`,
  which `CreateShipment` currently leaves null. `SyncShipmentTracking`
  already refuses a shipment with no tracking number
  (`ShipmentNotTrackableException`) for exactly this reason — every
  shipment in the system today is permanently stuck at that state until
  this Action exists.
- **`MarkCodRemitted`** — COD remittance tracking, once cash-on-delivery
  orders actually ship through the above.
- **§37 #13** ("at least one courier works with a real test environment")
  is a same-day task once picked up, not build work: Econt's demo host has
  a standing published test login already verified against its OpenAPI
  spec. No live call has been made to either vendor as of this writing.

## Other deferred items with a real decision behind them

- **Full-text search over `LIKE '%term%'`.** Measured, not just assumed
  (`testing/performance-testing.md` Finding 3): a genuine full-table scan,
  ~2.6s of a ~3.9s search page load at 100k products. ADR-0014 already
  names this a placeholder; the decision (a MySQL full-text index vs. an
  external search service) is unchanged and still open, and now has real
  numbers behind it rather than a hunch.
- **S3 media disk.** ADR-0025 split seed and upload media disks and made
  the eventual object-storage move a single `MEDIA_DISK` environment
  variable — genuinely deferred, not a gap, per that ADR's own "revisit
  when `MEDIA_DISK` is set to anything non-local" trigger for the
  `servableUrl()` performance cost that switch would introduce.
- **Delivery-cost reimbursement on a full withdrawal** (Consumer Rights
  Directive Art. 13). `RefundReturn` currently refunds goods value only —
  a recorded counsel gap (`write-rules/returns.md`,
  `regulatory-compliance.md`), real bounded feature work reusing the
  existing `RefundReturn`/`RefundPayment` machinery once picked up.
- **Checkout-specific cart timeout.** A checkout sitting unfinished for 10
  minutes should revert to cart state and release its hold — whether "in
  checkout" becomes a distinct state with its own shorter TTL, or reuses
  `carts:expire`'s existing timer, is the open design question.
- **A real Forge deployment.** `how-to/deploy-and-host.md`'s Forge section
  is a pre-deploy checklist that has never been exercised against an
  actual host (Railway is the only topology that has). Add a
  post-deployment smoke-test section once one exists.
- **Production starter-taxonomy.** Whether a small set of common
  brands/categories/tags is worth seeding as production reference data —
  or whether first login stays entirely blank-slate per ADR-0003. Deciding
  this ahead of time avoids the first real admin discovering the gap by
  trial and error.
