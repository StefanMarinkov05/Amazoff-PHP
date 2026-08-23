# Permissions and roles

What exists after `migrate:fresh --seed`. Why it is arranged this way is
ADR-0006; how to use the roles screen is `how-to/edit-a-role.md`.

106 permissions, three roles, four demo accounts.

## Naming

`{ability}_{resource}` — `viewAny_product`, `updateStatus_order`,
`publish_article`.

The ability half is the Laravel policy method that checks it, which is what
keeps a policy method to one line. The ability is camelCase and never contains
an underscore; the resource is snake_case. The first underscore therefore
splits the two halves unambiguously, including for `viewAny_product_review`
(`viewAny` + `product_review`).

The catalogue's shape is defined once, in `App\Support\PermissionCatalogue`.
`PermissionSeeder` writes it and the roles UI groups its checkboxes by it.

## Abilities

| Ability | On | Meaning |
|---|---|---|
| `viewAny` | every resource | List page and navigation entry |
| `view` | every resource | Single record |
| `create` | see below | New record |
| `update` | every resource | Edit an existing record |
| `delete` | every resource | Remove a record |
| `updateStatus` | `order` | §17 — not every employee may select every status. Routine advances only; `Cancelled` and `Refunded` route to the two abilities below instead (ADR-0011) |
| `cancel` | `order` | Administrator move, per ADR-0004's own reasoning — distinct from a warehouse employee's routine status advance |
| `refund` | `order` | Same reasoning as `cancel_order`, for the symmetric terminal move |
| `addInternalNote` | `order` | §18 — staff-only, never shown to the customer |
| `publish` | `article` | §22 — separate from `update`, so drafting can be granted without publication |
| `approve` | `product_review` | §24 — moderation rather than an edit |
| `refund` | `payment` | Refunding money is not editing a row |

`restore` and `forceDelete` do not exist. Only `User`, `Product`, and
`ProductVariation` soft-delete and no admin surface exposes a trash view; they
get added with that UI.

## Resources

Twenty, each with the five CRUD abilities unless noted.

| Area | Resources |
|---|---|
| Catalogue | `product`, `product_category`, `product_variation`, `brand`, `attribute`, `attribute_value`, `coupon`, `product_review`\* |
| Content | `article`, `article_category`, `tag` |
| Operations | `order`, `shipment`, `inventory`, `carrier`, `payment`\* |
| Administration | `user`, `role`, `contact_message`\*, `newsletter_subscriber`\* |

\* No `create`. A payment row is written by the Stripe webhook (§13), a review
by a verified purchaser (§24), a contact message and a newsletter subscription
by a public form (§26). None is authored in the panel, so a `create_payment`
permission could only ever be ticked by mistake.

`create_order` and `delete_order` are also absent — an order exists because a
customer completed checkout, and §19 requires the history to survive.
`OrderPolicy::create` and `::delete` return `false` outright.

## Permissions with no model

Checked directly on the page that uses them rather than through a policy.
§3.5 names all three as administrator-only.

- `view_report`
- `view_audit_log`
- `update_setting`

## Roles

| Role | Permissions | Scope |
|---|---|---|
| `administrator` | **0** | Everything, via `Gate::before`. Attaching all 106 would drift as the catalogue grows |
| `content_editor` | 16 | Articles, article categories, tags |
| `warehouse_employee` | 12 | Orders, shipments, inventory, read-only carriers |

### content_editor

Full CRUD on `article` (plus `publish`), `article_category`, and `tag`.

No `product_review`. §3.3 scopes this role to articles, images, article
categories, and tags and does not mention reviews; §24 assigns hiding an
inappropriate review to administrators by name. Moderation is judging spam and
abuse in a customer's own words rather than authoring content, so the four
review permissions sit with the administrator. They were briefly granted here
and removed once §3.3 was read against §24.

Denied **by name** in §3.3, not merely omitted: `payment`, `carrier` (courier
credentials), `user` and `role` (user permissions), `setting`. Anyone widening
the role should read that section first.

Catalogue lookups — `brand`, `product_category`, `attribute`,
`attribute_value` — appear in neither §3.3's grant list nor its deny list and
are currently excluded.

The Article resource has now landed, answering the "related products" (§22)
question this section used to defer: `ArticleForm`'s products field is a
relationship `Select`, `relationship('products', 'name')`, so it renders
product *names* only — no price, SKU, stock, or anything else `ProductResource`
exposes. Product names are already public on the storefront. `viewAny_product`
correctly stays ungranted — it gates catalog management, not naming a product
in an unrelated picker — and no permission change is needed for this field.

### warehouse_employee

- `order` — `viewAny`, `view`, `updateStatus`, `addInternalNote`. Not
  `cancel` or `refund` — ADR-0011 reserves both for the administrator, via
  `Gate::before`, the same way `content_editor` reaches nothing it is not
  explicitly granted. Not `create` or `delete`; those do not exist.
- `shipment` — `viewAny`, `view`, `create`, `update`. §37 criterion 15.
- `inventory` — `viewAny`, `view`, `update`.
- `carrier` — `viewAny` only. Picking a courier is not administering one.

Not products, not articles, not payments, not users.

## Demo accounts

Created by `UserSeeder`, non-production only. Password `password` for all four.

| Email | Role | Panel |
|---|---|---|
| `admin@example.com` | `administrator` | yes |
| `editor@example.com` | `content_editor` | yes |
| `warehouse@example.com` | `warehouse_employee` | yes |
| `customer@example.com` | none | **no** |

The customer holds no role rather than a `customer` role: a registered
customer is the default authenticated state, and access to their own orders is
an ownership check in a policy, not a permission.

## Where each check lives

| Question | Mechanism |
|---|---|
| Is this person staff? | `User::canAccessPanel()` — role allow-list plus `is_active` |
| May their role do this? | Permission row, checked by a policy method |
| May they touch this record? | Ownership branch in the policy |
| Is this the administrator? | `Gate::before` in `AppServiceProvider` |

20 policies exist, one per resource above. Nineteen are resolved by
convention; `RolePolicy` is registered by hand in `AppServiceProvider` because
`Spatie\Permission\Models\Role` lives outside `App\Models`.
