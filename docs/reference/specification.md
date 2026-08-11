# Working specification

Status: draft — diverges from the issued document in the ways marked below.

The issued document is `misc/docs/reference/spec/original-spec.pdf`, which is
authoritative and is not edited. `misc/` is gitignored, so this file is the
version of the requirements that travels with the repository. Where the two
disagree, the issued document is what the project is graded against and this
file records what is actually being built.

Markers used throughout: **[Added]** — not in the issued document. **[Changed]**
— in the issued document, built differently. **[Deferred]** — in the issued
document, not planned for this delivery. **[Open]** — undecided.

§37 is the contract. §1–36 are the wish list, and §38 is optional. A separate,
unnumbered set of implementation standards was issued afterwards and is recorded
under "Implementation standards" below; those constrain how the project is built
rather than what it does.

## Deviations at a glance

| Area | Deviation | Recorded in |
|---|---|---|
| VAT | **[Added]** — absent from the issued document. Prices stored gross, per-product `vat_rate`, snapshotted onto order items | CLAUDE.md |
| Cash on delivery | **[Added]** — absent from the issued document. Skips Stripe; stock reserved on confirmation; paid on courier remittance | CLAUDE.md |
| Product wish lists | **[Added]** — §38 optional, pulled into scope; `wishlist_items` exists | Schema |
| Stock ownership | **[Changed]** — §6, §7, and §20 assign stock three owners. Only §20 is implemented: one `inventories` row per variation | ADR-0002 |
| Roles | **[Changed]** — a package with runtime-editable permissions rather than a fixed list, as §3.5 requires | ADR-0001 |
| Deployment target | **[Changed]** — §Deployment suggests Vercel or Heroku; neither runs a persistent queue worker or scheduler, which §22 and §28 require | **[Open]** |
| Multi-language content | **[Deferred]** — §38 optional. Packages installed, storage shape undecided | **[Open]** |
| Audit log | **[Open]** — §3.5 and §28 require it; shape undecided | Changelog |

## 1–2. Scope and business goal

A responsive online shop in PHP and Laravel combining e-commerce, a blog and
news platform, Stripe card payments, Econt and Speedy delivery, customer
accounts, product and inventory management, order and shipment management, and
an administration panel. Desktop, laptop, tablet, and mobile.

The business manages the full sales process from one platform: products,
categories, stock, orders, card payments, delivery pricing, courier shipments,
order and shipment status, customers, articles, promotions, and sales reports.

## 3. Roles

**Guest** — browse, search, filter, sort, read articles, use the cart, check out
without registering, register, log in.

**Registered customer** — personal information, saved addresses, current and
past orders, shipment tracking, order repetition, favourites, reviews,
newsletter preferences.

**Content editor** — create, edit, and publish articles; upload images; manage
article categories and tags; drafts, previews, scheduled publication, archiving.
Denied Stripe settings, payment information, courier credentials, user
permissions, and sensitive customer data.

**Order and warehouse employee** — view new orders, confirm availability,
prepare deliveries, change order status, add internal notes, create shipments,
generate labels, print courier documents, add tracking numbers, review shipment
status.

**Administrator** — everything, plus roles and permissions, reports, website
settings, and audit logs.

**[Changed]** Guest and registered customer are not roles in the permission
system: a guest has no user row, and a customer is the default authenticated
state. Three assignable staff roles exist. §3.5 requires permissions editable
without a deploy, so they are data rather than code.

## 4–5. Public pages

Home, catalogue, category, product detail, search results, cart, checkout,
payment result, order confirmation, order tracking, blog index, article detail,
login, registration, password reset, customer profile, order history, about,
contact, FAQ, delivery information, payment information, terms, privacy policy,
cookie policy.

The home page carries a promotional banner, featured categories, featured
products, discounted products, new products, popular products, latest articles,
promotional sections, newsletter signup, delivery and payment information, and
business benefits, with the main content administrator-controlled.

## 6–7. Products and variations

A product carries name, slug, SKU, short and full description, main and
additional images, regular price, discount price, minimum order quantity,
weight, dimensions, category, brand, attributes, variations, active and featured
flags, and SEO title and description.

**[Added]** `vat_rate` per product.

**[Changed]** Available and reserved quantity are not product columns — see §20.

Variations may vary by size, colour, weight, material, or package type, each
with its own SKU, price, discount price, stock, image, and availability. Stock
is managed per variation.

**[Changed]** Every sellable product has at least one variation, including
products with nothing to vary, because stock hangs off the variation. See
`explanation/db-schema-design.md`.

## 8–9. Catalogue and product detail

Browse all products or by category; search by name, SKU, and description; filter
by category, price, brand, attribute, and availability; sort by price, newest,
popularity, and alphabetically; change page size. Filters and search parameters
survive pagination. On mobile, filters collapse into a panel or modal.

The product page shows name, images and gallery, regular and discount price and
percentage, description, available variations, stock status, delivery
information, a quantity selector, add-to-cart and favourite buttons, related
products, reviews, and related articles. All required variations must be
selected before adding to the cart.

## 10–12. Cart and checkout

The cart supports adding, removing, and changing quantities including manual
entry, changing variation, discount codes, subtotal, discounts, estimated
delivery cost, and final total. Quantity never falls below one. Before any
update the server validates availability, variation, stock, minimum quantity,
maximum available quantity, and current price. The cart works for guests and
registered customers; a guest cart merges into the customer's on login.

Checkout runs in six steps: customer information, delivery information, courier
selection, payment method, review, confirmation. The customer may continue as a
guest, log in, or register; enters contact details; chooses address or courier
office delivery; chooses Econt or Speedy; sees the calculated delivery price;
adds invoice information and an order note; and accepts the terms.

**All prices are recalculated server-side before an order is created. The total
received from the browser is never trusted.**

A guest supplies first name, last name, email, telephone, delivery address or
office, and invoice information where required, and may be offered an account
afterwards.

## 13–14. Payments

Stripe in test mode. Payments are created from the backend, linked to an order,
and store the Stripe identifier, amount, and currency. Successful, failed,
cancelled, and additional-authentication outcomes are all handled. Webhook events
are processed with signature verification, duplicate suppression, and a record of
what was processed.

Payment statuses: pending, processing, paid, failed, cancelled, refunded,
partially refunded.

Card numbers and security codes are never stored. Stripe secrets live in
environment variables.

The workflow: submit → validate → recalculate → create order as pending → create
payment → customer pays → webhook arrives → signature verified → payment status
updated → order status updated → stock reserved or deducted → confirmation email
→ order visible to the warehouse.

**An order is never marked paid because the customer opened the success page.**

**[Added]** Cash on delivery skips Stripe. Stock is reserved on order
confirmation, the amount is carried to the courier, and the payment is marked
paid on remittance.

## 15–16. Couriers

Econt and Speedy, to an address or a courier office, with the available services
and optional extras each supports. The integrations search cities, postcodes,
streets, and offices; calculate delivery price and estimated time; create
shipments; return shipment and tracking numbers; generate labels; print
documents; track; and cancel where supported.

Failures to handle: API unavailable, request timeout, invalid credentials,
invalid city, unavailable office, price calculation failure, shipment creation
failure, incomplete response. Technical details and credentials are never shown
to the customer; errors go to the application log; the customer gets a clear
message and can retry or choose another option.

## 17–19. Orders

An order carries its number, customer or guest details, email, telephone,
billing and delivery address, courier and office, items with quantities and
prices, discounts, delivery price, total, payment method and status, order
status, shipment and tracking number, customer and internal notes, and creation
and update dates.

**Product names, SKUs, variations, and prices are copied onto order items.
Changing a product later never alters a historical order.**

Statuses: new, awaiting payment, paid, confirmed, preparing, ready for shipment,
shipped, delivered, cancelled, returned, refunded. Not every employee may select
every status.

Every change records the order, previous and new status, the user, timestamp,
reason, and internal note. History is not editable by regular users.

**[Changed]** Which transitions are legal at all, which actor may perform one,
and how the change is recorded are answered in three separate places. See
ADR-0004.

## 20. Inventory

Tracks current, reserved, available, sold, returned, and damaged quantity.
Available quantity is current minus reserved. Stock is reserved when an order
reaches the appropriate stage, and released when a payment fails, a payment
session expires, an order is cancelled, or an administrator cancels it.

Stock changes through movements rather than direct quantity writes. Movement
types: initial stock, new delivery, order reservation, completed sale,
reservation release, customer return, damaged product, manual correction.

**[Changed]** Available quantity is derived, not stored. The issued document
spells two movement types "Order reserion" and "Reserion release"; both are
typos for reservation.

## 21. Discounts

Percentage discounts, product discounts, order discounts, minimum order value,
start and expiry dates, total usage limit, per-customer usage limit, and an
active flag.

## 22–24. Content

Articles carry title, slug, summary, rich-text content, main image, gallery,
author, category, tags, draft and published state, scheduled publication,
featured flag, related articles and products, SEO fields, and publication and
update dates. Types include news, guides, comparisons, tutorials, interviews,
promotional articles, and industry information.

The editor supports headings, paragraphs, bold, italic, underline, bullet and
numbered lists, links, images, quotes, tables, embedded video, code blocks, and
alignment. Uploaded images are validated and optimised.

Registered customers may review products, with customer, product, rating, text,
creation date, and approval status. Only purchasers may review; one review per
customer per product; reviews require approval; administrators can hide
inappropriate ones.

**[Changed]** The issued document numbers two consecutive sections 24 (the
editor and reviews) and omits §23 entirely. Article and review bodies are
sanitised before rendering.

## 25–26. Customer account and administration

The customer dashboard covers personal information, saved addresses, current and
past orders, payment status, shipment tracking, favourites, reviews, and
newsletter preferences. Orders can be repeated, using current prices and stock.

The administration dashboard shows orders and revenue today, revenue this month,
new customers, pending and paid orders, failed payments, orders awaiting
shipment, low-stock and unavailable products, popular products, recent orders,
customers, and articles, and sales by period, orders by status, orders by
courier, and revenue by category. Reports filter by date.

## 27–28. Entities and business rules

The suggested entity list is implemented with additions — `Carrier`,
`ProductSpecification`, `CouponRedemption`, `OrderAddress`, `WishlistItem`. See
`reference/schema.md`.

The business rules are load-bearing and restated in full:

Prices are calculated on the server. The browser does not determine the total.
Availability is checked before order creation and again before payment
completes. Quantity never falls below one. A customer cannot order more than is
available. Historical order prices do not change. Stripe webhook signatures are
verified. The same Stripe event is not processed twice. A shipment cannot be
created for an invalid order. Courier credentials are not in source code.
Unauthorized users cannot reach administrative pages. Content editors cannot
reach payment data. Important administrative actions are recorded. Critical order
operations run inside database transactions.

Emails are queued, for registration, verification, password reset, order
confirmation, payment success and failure, status changes, shipment creation,
cancellation, refunds, and newsletter subscription.

**[Changed]** The issued document numbers the email section 28 and the business
rules section 28 as well; both are kept.

## 29–32. User manuals

Step-by-step walkthroughs for placing an order, processing an order as a
warehouse employee, creating a product as an administrator, and publishing an
article as a content editor. They describe the intended flows rather than adding
requirements.

**[Changed]** §31 step 6 reads "Enter the price and ␣ rate" with a word missing.
Read as VAT rate.

## 33–36. Technical, security, responsive, and testing requirements

Laravel authentication, middleware, policies and gates, form request validation,
Eloquent relationships, service classes, events and listeners, queues,
notifications, the scheduler, database transactions, indexes, structured
logging, API service clients, feature and unit tests, factories, and seeders.
Controllers handle HTTP only; complex logic sits in service classes.

**[Changed]** Business logic lives in `app/Actions/*`, one command per class —
this project's reading of "service classes".

Security: CSRF protection, server-side validation, role-based authorization,
secure password hashing, email verification, rate limiting, secure credential
storage, webhook verification, duplicate-request protection, file validation
with restricted types and sizes, protected administration routes, audit logging,
safe error messages, protection of personal information, and prevention of
unauthorized resource access.

Responsive: desktop, laptop, tablet, and mobile, with collapsible navigation and
filters, touch-friendly controls, responsive catalogue, cart, checkout, images,
and administration tables, card-based order history, visible validation errors,
and no unnecessary horizontal scrolling.

Tests: authentication, email verification, role permissions, product creation and
updating, cart operations and quantity limits, stock validation, checkout
validation, server-side price calculation, order creation, stock reservation,
Stripe webhook processing and duplicate events, payment status updates, courier
price calculation, shipment creation, article publishing, unauthorized
administration access, guest checkout, and customer order access. External APIs
are mocked.

## 37. Mandatory acceptance criteria

The project is accepted when all twenty hold:

1. An administrator can create and manage products.
2. Customers can browse and search products.
3. Customers can filter and sort products.
4. Customers can add products to a shopping cart.
5. Cart quantities are validated correctly.
6. A guest can complete checkout.
7. A registered customer can complete checkout.
8. The final total is calculated on the server.
9. Stripe test payments work.
10. Stripe webhooks update payment statuses.
11. Duplicate Stripe events are not processed twice.
12. Customers can select Econt or Speedy.
13. At least one courier works with a real test environment.
14. Both couriers use the shared courier interface.
15. A shipment can be created from an order.
16. An employee can update order statuses.
17. A content editor can publish articles.
18. User roles cannot access prohibited features.
19. The website works on desktop and mobile.
20. Critical payment, order, and authorization workflows have automated tests.

## 38. Optional features

Wish lists, product comparison, multiple languages and currencies, loyalty
points, gift cards, bundles, abandoned-cart emails, recommendations, recently
viewed products, PDF invoices, refund management, advanced reports, newsletter
campaigns, support tickets, live chat, a progressive web app, barcode inventory,
and AI recommendations.

**[Added]** Wish lists are in scope; the rest are not.

## 39. Suggested phases

Analysis and planning; project foundation; product catalogue; cart and checkout;
external integrations; blog and administration; testing and deployment.

**[Changed]** The issued document numbers two consecutive phases 3.

## Implementation standards

Issued separately from the numbered specification and unnumbered in the source.
They constrain how the project is built rather than what it does, so compliance
is a state the repository is in at any moment rather than a feature that ships.

Status is what is true on `main` today: **Met** — satisfied and verified.
**Not met** — a known gap with work outstanding. **Open** — no decision yet.
**Pending** — nothing built that could satisfy or violate it.

### Tooling and conventions

| # | Requirement | Status |
|---|---|---|
| 1 | One code formatter and shared settings across every IDE | Met — see below |
| 2 | Consistent naming conventions for files, classes, methods, variables | Met — Pint enforces PSR-12; `App\Enums`, Actions, and Policies follow Laravel conventions |
| 3 | Commit messages explain what changed, not `fix` / `test` / `final2` | Met — convention recorded in `CONTRIBUTING.md` |

Pint handles PHP formatting; `.editorconfig` handles everything else. There is
one `.editorconfig`, at the repository root, and there must stay one: a nested
file declaring `root = true` stops the cascade, so nothing beneath it sees the
outer config.

That is exactly what happened before — a second `.editorconfig` in
`online-store/` shadowed the root for the entire application, and the two
disagreed about compose indentation (2 versus 4) and about JavaScript, which the
inner file did not mention at all. Since every JavaScript file lives under
`online-store/`, all of them were indenting at 4 against the root's stated
intent. Merged into the root file and the nested one deleted.

### Frontend

| # | Requirement | Status |
|---|---|---|
| 4 | Replace Laravel's default welcome page with a real home page | **Not met** — `routes/web.php` still returns `view('welcome')`, and `welcome.blade.php` is the only view in the project |
| 5 | At most one or two core CSS and JavaScript technologies; no unnecessary mixing | Met by decision — Livewire and Alpine, argued in ADR-0001. Nothing built yet to violate it |
| 6 | Per-view CSS and JavaScript files where genuinely needed | Pending |
| 7 | Where the task calls for Livewire, use Livewire components rather than plain forms that reload the page | Met by decision — ADR-0001. Enforced in review |
| 8 | No oversized Blade files; repeated markup extracted into components | Pending |

### Authentication

| # | Requirement | Status |
|---|---|---|
| 9 | No more than one authentication library or ready-made solution in the project | Met, with a constraint to hold |

Laravel's own authentication is the only one present. Filament's panel login is
not a second solution — it authenticates through the same guard. This becomes a
live risk the moment storefront authentication is built: adding Breeze,
Jetstream, or Fortify alongside would violate this outright. The storefront
builds on Laravel's authentication directly.

### Code quality

| # | Requirement | Status |
|---|---|---|
| 10 | No magic numbers or literal strings in logic | Met for fixed value sets — twelve backed enums cover all 19 enum columns. Money and other constants as they arrive |
| 11 | No repeated code; extract components, partials, helpers, or reusable classes | Met by architecture — business logic lives in one Action per command, called by both the storefront and Filament |
| 12 | Understand the approach rather than copying an implementation | Met by process — `CLAUDE.md` treats generated code as a first draft that is read before it is trusted |
| 13 | No `dd()`, `dump()`, `console.log()`, or test data left in the finished solution | Met — none present |

### Validation and data

| # | Requirement | Status |
|---|---|---|
| 14 | Always validate server-side, never only through HTML attributes or JavaScript | Met by decision — §11 and §28 already require it; Form Requests per `CLAUDE.md` |
| 15 | Do not rely on PHP validation alone; add matching database constraints | Met — see below |

Composite primary keys on all six pivot tables, and 45 `CHECK` constraints
across 11 tables. Form Requests remain the readable half; the constraints are
the binding one, and they hold for seeders, queued jobs, and fixture imports
alike. `docs/adr/0005-database-level-validation.md` records what is enforced,
what cannot be expressed as a constraint and therefore stays an application
invariant, and the ten factories this forced fixing.

### Configuration

| # | Requirement | Status |
|---|---|---|
| 16 | Never read `.env` directly in application code; add settings to `config/` and read them with `config()` | Met — no `env()` call exists in `app/` or `routes/` |
| 17 | Ship `.env.example` with the required variables and no real passwords or keys | Met |

### Files and uploads

| # | Requirement | Status |
|---|---|---|
| 18 | Check size, type, and storage method for images, video, and PDFs | Pending |
| 19 | Generate unique names for uploaded files so they cannot collide | Pending |

§34 already requires secure file validation with restricted types and sizes, and
`explanation/gdpr.md` covers where user-uploaded content lives. Both apply the
moment product images and article images are built.

### Repository hygiene

| # | Requirement | Status |
|---|---|---|
| 20 | Never commit `.env`, `vendor`, `node_modules`, logs, or user-uploaded files | Met — `.gitignore` covers `.env`, `.env.backup`, `.env.production`, `*.log`, `/vendor`, `/node_modules`, `/public/storage`, `/storage/*.key` |

## Defects in the issued document

Recorded so they are not rediscovered:

- Two sections numbered 24; §23 referenced but absent; two sections numbered 28;
  two phases numbered 3.
- "Order reserion" and "Reserion release" for reservation; "Actie the product"
  for activate.
- §31 step 6 is missing a word before "rate".
- Stock is specified in three places with three different owners.
- No VAT and no cash on delivery, despite Econt and Speedy implying a Bulgarian
  market where both are standard.
- The deployment suggestions cannot run a persistent queue worker or scheduler,
  which §22 and §28 require.
