# Local access — accounts, URLs, and routes

Seeded credentials and the full route map for a local Docker run. Verified
against a running stack on 2026-08-29 (`amazoff_demo` database, `main` at
`4c60f7c`) — the accounts were logged in, the panel gate checked per role,
and every route below taken from `route:list`, not from reading code.

Nothing here is a secret: `UserSeeder` gates itself to non-production, and
every password is the factory default. On any deployed environment these
are default credentials and the seeder does not run.

## Services

| Service | URL |
|---|---|
| Storefront | http://localhost:8080 |
| Filament admin | http://localhost:8080/admin |
| Vite dev server (HMR) | http://localhost:5173 |
| Mailpit (caught outbound mail) | http://localhost:8025 |
| MySQL | `localhost:3306` |

## Accounts

Password for all four: `password` (from `UserFactory`, not `UserSeeder` —
the seeder supplies only email, name, and role).

| Email | Role | Panel access | Purpose |
|---|---|---|---|
| `admin@example.com` | `administrator` | yes | Full access. Holds **0 permissions** by design |
| `editor@example.com` | `content_editor` | yes | 16 permissions — articles, categories, tags |
| `warehouse@example.com` | `warehouse_employee` | yes | 12 permissions — orders, shipments, inventory |
| `customer@example.com` | *(none)* | **no** | Proves `canAccessPanel()` denies a roleless user |

The customer holds no role at all rather than a `customer` role: a
registered customer is the default authenticated state, and access to their
own orders is an ownership check in a policy, not a permission (§3).

### Why `administrator` has zero permissions

A `Gate::before` callback in `AppServiceProvider` returns `true` for that
role and short-circuits every check, so the role cannot drift out of step
with the 106-permission catalogue as permissions are added. The cost: a
policy can no longer deny an administrator anything, which pushes
"nobody may do X" rules into the Actions as domain invariants. ADR-0006.

### What each staff role actually reaches

- **`content_editor`** — `article`, `article_category`, `tag`
- **`warehouse_employee`** — `order` (incl. `updateStatus`,
  `addInternalNote`), `shipment`, `inventory`, `carrier` (view only)

Permission names are `{ability}_{resource}`, where the ability half matches
the policy method that checks it. `docs/reference/permissions.md` has the
full catalogue.

## Storefront routes

| Method | URI | Component | Name |
|---|---|---|---|
| ANY | `/` | redirect → `/catalogue` | — |
| GET | `/catalogue` | `App\Livewire\Catalogue\ProductList` | — |
| GET | `/products/{product:slug}` | `App\Livewire\Catalogue\ProductDetails` | — |
| GET | `/about` | `pages.about` (static Blade) | `about` |
| GET | `/contact` | `App\Livewire\Contact\ContactForm` | `contact` |
| GET | `/login` | `App\Livewire\Auth\Login` | `login` |
| GET | `/register` | `App\Livewire\Auth\Register` | `register` |
| GET | `/account/password` | `App\Livewire\Auth\ChangePassword` | `password.change` |
| POST | `/logout` | closure | `logout` |

`/login` and `/register` are behind the `guest` middleware; `/account/password`
and `/logout` behind `auth`. Logout is POST-only — a GET logout is triggerable
by any `<img>` tag on any page the user visits.

`NewsletterSignup` has no route of its own — it is embedded in the site
footer component.

There is no cart, checkout, storefront authentication, order tracking, or
article frontend yet. See the specification's §37 table for what that
leaves outstanding.

## Admin panel routes

Panel at `/admin`, id `admin`, guarded by `Authenticate` middleware plus
`canAccessPanel()` on `User` (role membership **and** `is_active` — Filament
is not protected past login by default).

**There is no `/admin/login`.** Filament's own `->login()` is removed from
`AdminPanelProvider`: it authenticated the same `web` guard as the storefront
form, so a guest reaching `/admin` is redirected to `/login` instead. Staff
sign in there like anyone else and reach the panel through the **Admin panel**
link in the header account menu, which is shown by calling `canAccessPanel()`
itself — the link and the gate cannot disagree.

| Resource | Index | Create | View | Edit |
|---|---|---|---|---|
| Products | `/admin/products` | yes | — | yes |
| Orders | `/admin/orders` | — | yes | **no** |
| Articles | `/admin/articles` | yes | yes | yes |
| Product reviews | `/admin/product-reviews` | — | yes | — |
| Coupons | `/admin/coupons` | yes | — | yes |
| Contact messages | `/admin/contact-messages` | — | yes | yes |
| Newsletter subscribers | `/admin/newsletter-subscribers` | — | yes | yes |
| Roles | `/admin/roles` | — | — | yes |
| Brands | `/admin/brands` | yes | — | yes |
| Tags | `/admin/tags` | yes | — | yes |
| Carriers | `/admin/carriers` | yes | — | yes |
| Attributes | `/admin/attributes` | yes | — | yes |
| Attribute values | `/admin/attribute-values` | yes | — | yes |
| Product categories | `/admin/product-categories` | yes | — | yes |
| Article categories | `/admin/article-categories` | yes | — | yes |

Plus `/admin/logout`. There is no `/admin/login` — see above.

Products carry three relation managers (variations, images, specifications);
Orders carry three (items, addresses, status histories). Reviews are
moderation-only — approve/unapprove row actions calling
`ApproveProductReview`/`UnapproveProductReview`, no create. Roles are edit
only: `canAccessPanel()` gates on the `User::STAFF_ROLES` constant, so a
role created in the UI would grant no panel access until someone edited
that constant and deployed.

**`Orders` has no edit page and no status-transition action.** Its
`getPages()` maps `index` and `view` only, and nothing in the panel calls
`TransitionOrderStatus` — so §37 criterion 16 ("an employee can update
order statuses") is not reachable through the UI, even though the Action,
its policy, and its concurrency tests all exist. `ViewOrder` also renders an
`EditAction` pointing at an edit route that is not registered.

### Dashboard

Seven widgets, `app/Filament/Widgets/`, discovered automatically — no entry
needed in `->widgets([...])`, that array is only for classes outside the
discovered directory (`AccountWidget`, Filament's own).

| Widget | Shows |
|---|---|
| `RevenueOverview` | Revenue, orders, items sold, avg order value (30d, trend vs. prior 30d), return rate (all time) |
| `RevenueTrendChart` | Daily revenue, last 90 days |
| `OrdersByStatusChart` | Every current order by status — the live pipeline |
| `TopSellingProductsTable` | Units sold, all time, from `order_items`' own snapshot columns |
| `ReturnsAndDamageTable` | Ranked by loss rate (returned+damaged ÷ sold), not raw count |
| `ReviewsOverview` | Average rating, pending/approved counts, new reviews (30d) |
| `RatingDistributionChart` | 1–5 star counts, approved and pending together |

Every widget aggregates in SQL (`SUM`/`COUNT`/`GROUP BY`); none load
Eloquent models to compute a total. Revenue reads `payments.paid_at`
specifically, not `created_at` — the only column that answers "when did the
money arrive."

### Branding

`AdminPanelProvider` sets `brandName('Amazoff')` and a custom `brandLogo` —
the same two-tone wordmark as the storefront, rendered from
`resources/views/filament/components/brand-logo.blade.php`. The storefront's
`--color-brand-orange` CSS variable does not reach here (Filament ships its
own compiled bundle, independent of the storefront's Vite build), so the
partial uses the same sampled hex literally.

The user menu (top-right, click the account name) carries a **View site**
link — `sort(-2)`, before Filament's own Profile/Sign-out entries — since
typing the URL by hand was otherwise the only way back to the storefront.

## Database

`.env`'s `DB_DATABASE` selects which database the app serves. Two exist
locally:

| Database | Contents |
|---|---|
| `amazoff_demo` | Full demo dataset — 169 products, 104 users, 140 orders, 90 reviews, 24 articles, 182 images |
| `online_shop` | Schema only, no catalogue |
| `online_shop_test` | What `phpunit.xml` forces; migrated, not seeded |

`docs/reference/schema/demo-data.md` has the exact seeder run order and the
SKU-level inventory of what the demo contains.
