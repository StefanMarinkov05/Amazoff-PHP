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
`attribute_values`, and the pivots `attribute_product` and
`attribute_value_product_variation`.

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
| `product_reviews` | `UNIQUE(user_id, product_id)` | One review per customer per product (§24) |
| `wishlist_items` | `UNIQUE(user_id, product_id)` | No duplicate favourites |
| `cart_items` | `UNIQUE(cart_id, product_variation_id)` | One line per variation; quantity changes instead |
| `attribute_values` | `UNIQUE(attribute_id, slug)` | Value slugs unique within their axis |
| `orders` | `UNIQUE(serial_number)`, `INDEX(email)` | Order lookup by number; tracking by email |
| `shipments` | `UNIQUE(tracking_number)` | Tracking numbers are not reused |
| `products`, `product_variations` | `UNIQUE(sku)` | SKU identifies one sellable item |

Unique slugs: `products`, `product_categories`, `brands`, `articles`,
`article_categories`, `tags`, `attributes`. Unique codes: `coupons`,
`carriers`.

**Known gap.** `attribute_product` and `attribute_value_product_variation` have
no unique constraint and no primary key, so the same attribute can be attached
to a product twice. Recorded in ADR-0002; needs a new migration.

## Soft deletes

`users`, `products`, `product_variations`. Everything else deletes hard.
`orders` is never deleted; `anonymized_at` marks a GDPR erasure —
`explanation/gdpr.md`.

## Money

`decimal(10,2)` columns with `decimal:2` casts, `decimal(8,2)` for weights.
Prices are stored gross; `products.vat_rate` is per-product and snapshotted
onto order items. Arithmetic uses `bcmath`, never float.

## Enum columns

19 columns across 13 tables, each cast to a backed enum in `App\Enums`.

| Enum | Columns |
|---|---|
| `OrderStatus` | `orders.status`, `order_status_histories.previous_status`, `.new_status` |
| `PaymentStatus` | `payments.status`, `orders.payment_status`, `payment_events.status_before`, `.status_after` |
| `PaymentMethod` | `payments.method`, `orders.payment_method` |
| `ShipmentStatus` | `shipments.status`, `shipment_tracking_events.status` |
| `InventoryMovementType` | `inventory_movements.movement_type` |
| `ArticleStatus` | `articles.status` |
| `CouponType` | `coupons.type` |
| `CouponScope` | `coupons.scope` |
| `AddressType` | `order_addresses.type` |
| `DeliveryType` | `order_addresses.delivery_type` |
| `AttributeInputType` | `attributes.input_type` |
| `NewsletterStatus` | `newsletter_subscribers.status` |

The value lists exist twice — in the migration `enum()` literals, which are
frozen by the append-only rule, and in `App\Enums`. Migrations added from here
on use `OrderStatus::values()` rather than a literal array.

`OrderStatus`, `PaymentStatus`, `ShipmentStatus`, and `ArticleStatus` carry a
transition matrix; see ADR-0004.
