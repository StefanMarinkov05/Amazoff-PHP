# Amazoff — product walkthrough

Amazoff is a Laravel e-commerce platform built for the Lumen101 2026
internship: a full storefront (catalogue, cart, checkout, accounts, returns,
a blog-style "Journal") plus a Filament admin panel with role-scoped access
for content editors, warehouse staff, and administrators. It supports Stripe
and cash-on-delivery payments and Econt/Speedy courier integration.

This folder is a **static, screenshot-based walkthrough** of the whole
product — every page below is a real screenshot taken from the running,
seeded demo instance, with a few sentences of context per screen. You don't
need to run the app to see what it does; just read through in order, or jump
to the section you care about.

**Demo accounts** (password `password` for all):

| Email | Role |
|---|---|
| `customer@example.com` | Plain customer — no panel access, owns orders in all 11 order states |
| `editor@example.com` | `content_editor` — articles, categories, tags only |
| `warehouse@example.com` | `warehouse_employee` — orders, shipments, inventory |
| `admin@example.com` | `administrator` — full panel access |

## Contents

**Orientation**

- [`00-architecture-summary.md`](00-architecture-summary.md) — condensed
  architecture and conventions for a technical reviewer: tech stack, the
  non-negotiable rules (Actions, no repository pattern, backed enums, money
  handling, eager-loading, concurrency), the permissions model, the order/
  payment/return lifecycle, GDPR posture, and the full ADR list. Read this
  first if you want the mental model before the screenshots.

**Storefront — anonymous visitor & customer**

- [`01-storefront-browsing.md`](01-storefront-browsing.md) — home page,
  catalogue with filters, product detail (variations, reviews), search, the
  Journal (blog), static pages, contact form, newsletter signup.
- [`02-cart-and-checkout.md`](02-cart-and-checkout.md) — adding to cart, the
  cart page, guest checkout with cash-on-delivery through to order
  confirmation, the confirmation email in Mailpit.
- [`03-customer-account.md`](03-customer-account.md) — register/login,
  order history across all 11 order statuses, order detail, order tracking
  by number+email, requesting a return, wishlist, account deletion page.

**Admin panel — Content Editor**

- [`04-admin-content-editor.md`](04-admin-content-editor.md) — the
  role-limited sidebar, creating and editing an article, publishing,
  article categories, tags.

**Admin panel — Warehouse Employee**

- [`05-admin-warehouse.md`](05-admin-warehouse.md) — orders list and detail,
  changing an order's status, creating a shipment, inventory and stock
  adjustment, the read-only carrier view.

**Admin panel — Administrator**

- [`06-admin-administrator.md`](06-admin-administrator.md) — the full
  sidebar, product management (variations, images), categories/brands/
  attributes, coupons, users & roles, payments and refunds, returns,
  review moderation, order detail and the admin-only cancel/refund path,
  contact messages.

All screenshots live in [`screenshots/`](screenshots/), named with a numeric
prefix matching the doc they belong to.
