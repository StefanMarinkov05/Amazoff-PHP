# Database schema

Generated from `online-store/draft.yaml` by Blueprint. That file is the source
of truth for the shape; the migrations in `online-store/database/migrations/`
are the source of truth for what is applied. `erd-diagram.pdf` in this folder
is the visual form.

Migrations are append-only after the schema freeze — a change is a new
migration, never an edit to a merged one. Regeneration procedure:
`how-to/regenerate-with-blueprint.md`.

Design rationale is not here. ADR-0002 argues the catalogue shape, ADR-0004 the
state transitions, and `explanation/db-schema-design.md` describes how the
pieces fit.

## Tables by area

**Identity** — `users`, `addresses`, plus the `spatie/laravel-permission`
tables (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`,
`role_has_permissions`).

**Catalogue** — `product_categories`, `brands`, `products`, `product_images`,
`product_variations`, `product_specifications`, `attributes`,
`attribute_values`, and the pivots `attribute_product`,
`attribute_value_product_variation`, and `product_image_product_variation`.

**Inventory** — `inventories`, `inventory_movements`.

**Promotions** — `coupons`, `coupon_redemptions`, and the pivots
`coupon_product` and `coupon_product_category`.

**Cart** — `carts`, `cart_items`, `wishlist_items`.

**Orders** — `orders`, `order_items`, `order_addresses`,
`order_status_histories`.

**Payments** — `payments`, `payment_events`.

**Shipping** — `carriers`, `shipments`, `shipment_tracking_events`.

**Content** — `article_categories`, `articles`, `tags`, and the pivots
`article_tag` and `article_product`.

**Engagement** — `product_reviews`, `newsletter_subscribers`,
`contact_messages`.

**Framework** — `cache`, `jobs`, `activity_log`.

## Constraints that carry a rule

| Table | Constraint | Enforces |
|---|---|---|
| `inventories` | `UNIQUE(product_variation_id)` | Stock has one owner, per variation |
| `payment_events` | `UNIQUE(stripe_event_id)` | A Stripe event is processed once (§13) |
| `payments` | `UNIQUE(stripe_payment_intent_id)` | One payment row per intent |
| `coupon_redemptions` | `UNIQUE(coupon_id, order_id)` | A coupon counts once per order |
| `order_addresses` | `UNIQUE(order_id, type)` | One billing and one delivery address per order |
| `order_status_histories` | `UNIQUE(order_id, new_status)` | An order enters a given status once — backstop for `TransitionOrderStatus`'s `orders` lock, safe because `OrderStatus`'s transition graph is acyclic |
| `product_reviews` | `UNIQUE(user_id, product_id)` | One review per customer per product (§24) |
| `wishlist_items` | `UNIQUE(user_id, product_id)` | No duplicate favourites |
| `cart_items` | `UNIQUE(cart_id, product_variation_id)` | One line per variation; quantity changes instead |
| `attribute_values` | `UNIQUE(attribute_id, slug)` | Value slugs unique within their axis |
| `orders` | `UNIQUE(serial_number)`, `UNIQUE(cart_id)` (nullable, no foreign key), `INDEX(email)` | Order lookup by number; 1 order per cart; tracking by email |
| `shipments` | `UNIQUE(tracking_number)` | Tracking numbers are not reused |
| `products`, `product_variations` | `UNIQUE(sku)` | SKU identifies one sellable item |

Unique slugs: `products`, `product_categories`, `brands`, `articles`,
`article_categories`, `tags`, `attributes`. Unique codes: `coupons`,
`carriers`.

## Composite primary keys

All seven pivot tables carry a composite primary key over their column pair:
`attribute_product`, `attribute_value_product_variation`, `coupon_product`,
`coupon_product_category`, `article_tag`, `article_product`, and
`product_image_product_variation`. A primary key rather than a unique index —
InnoDB clusters by it, and these tables are always read by one side of the
pair, never by an id of their own.

The first six were generated as bare foreign-key pairs with neither, so each
accepted the same pair twice. A duplicate is not a visible error: it doubles a
row in every join, so a product would list an attribute twice. See ADR-0005.

`product_image_product_variation` is the only one carrying a payload column —
`position`, the order an image appears in one variation's gallery — and the
only one with a second index, `(product_variation_id, position)`, because its
primary key leads with the image and every read is by variation. ADR-0013.

## Check constraints

45 `CHECK` constraints across 11 tables enforce what a single row must satisfy —
money and quantities non-negative, discounts below the price they reduce, VAT
rates inside 0–100, reservations not above stock on hand, refunds not above the
payment, date windows ordered, review ratings inside the five-star scale, and a
percentage coupon capped at 100.

They are absent on SQLite, which has no `ALTER TABLE ADD CONSTRAINT`; the
migration skips itself there. This is why the test suite runs on MySQL — see
`how-to/use-ci.md`.

What the database cannot express, and therefore stays an application invariant:
cross-table SKU uniqueness, every product having at least 1 variation, 2
variations sharing an attribute-value set, order totals agreeing with bcmath
rounding, and status transitions. ADR-0005 lists them; ADR-0004 owns the last.

## Soft deletes

`users`, `products`, `product_variations`. Everything else deletes hard —
including `product_images` and every pivot, so a detached gallery membership is
gone rather than hidden.
`orders` is never deleted; `anonymized_at` marks a GDPR erasure —
`explanation/gdpr.md`.

## Money

`decimal(10,2)` columns with `decimal:2` casts, `decimal(8,2)` for weights.
Prices are stored gross; `products.vat_rate` is per-product and snapshotted
onto order items. Arithmetic uses `bcmath`, never float.

## Enum columns

19 columns across 13 tables, each cast to a backed enum in `App\Enums`. The
backing value — the string a fixture or a raw insert must use — is the one on
the right of `=` below, not the case name.

| Enum | Values | Columns |
|---|---|---|
| `OrderStatus` | `new`, `awaiting_payment`, `paid`, `confirmed`, `preparing`, `ready_for_shipment`, `shipped`, `delivered`, `cancelled`, `returned`, `refunded` | `orders.status`, `order_status_histories.previous_status`, `.new_status` |
| `PaymentStatus` | `pending`, `processing`, `paid`, `failed`, `cancelled`, `refunded`, `partially_refunded` | `payments.status`, `orders.payment_status`, `payment_events.status_before`, `.status_after` |
| `PaymentMethod` | `stripe`, `cash_on_delivery` | `payments.method`, `orders.payment_method` |
| `ShipmentStatus` | `pending`, `shipped`, `in_transit`, `delivered`, `returned`, `cancelled` | `shipments.status`, `shipment_tracking_events.status` |
| `InventoryMovementType` | `initial_stock`, `new_delivery`, `order_reservation`, `completed_sale`, `reservation_release`, `customer_return`, `damaged_product`, `manual_correction` | `inventory_movements.movement_type` |
| `ArticleStatus` | `draft`, `scheduled`, `published`, `archived` | `articles.status` |
| `CouponType` | `percentage`, `fixed` | `coupons.type` |
| `CouponScope` | `entire_order`, `products`, `categories` | `coupons.scope` |
| `AddressType` | `billing`, `delivery` | `order_addresses.type` |
| `DeliveryType` | `address`, `office` | `order_addresses.delivery_type` |
| `AttributeInputType` | `select`, `color`, `text` | `attributes.input_type` |
| `NewsletterStatus` | `subscribed`, `unsubscribed` | `newsletter_subscribers.status` |

The value lists exist twice — in the migration `enum()` literals, which are
frozen by the append-only rule, and in `App\Enums`. Migrations added from here
on use `OrderStatus::values()` rather than a literal array.

`OrderStatus`, `PaymentStatus`, `ShipmentStatus`, and `ArticleStatus` carry a
transition matrix; see ADR-0004.
