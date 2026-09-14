<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;

/**
 * The whole demo dataset, in the one order that works.
 *
 * `DatabaseSeeder` deliberately seeds only what every environment needs —
 * permissions, roles, carriers, staff accounts — so `migrate:fresh --seed`
 * leaves an empty catalogue (ADR-0003: a production catalogue is entered
 * through the panel, never seeded). Loading the demo content therefore meant
 * running thirteen `db:seed --class=` invocations by hand, in an order where
 * several steps are load-bearing and none of them says so at the point of
 * use. That is the failure this class removes: the ordering constraints now
 * live next to the calls they constrain, checked by running rather than by
 * re-reading `how-to/seed-the-database.md`.
 *
 * Not wired into `DatabaseSeeder`, and it must not be. CI wants the smallest
 * fixture that exercises the code, and this set is neither small nor fast;
 * `demo:seed` is the entry point a person uses.
 *
 * ## What each ordering constraint actually is
 *
 * Alphabetical order would break this set in five separate places, so each
 * one is named here rather than left to be rediscovered from a failure:
 *
 * - `CatalogueReferenceSeeder` before `DemoSeeder` — product fixtures resolve
 *   category, brand, and attribute slugs against real rows. `DemoSeeder`
 *   calls it itself; it is listed explicitly anyway so the dependency is
 *   visible here rather than hidden one level down.
 * - `DemoCustomerSeeder` before `DemoAddressSeeder`, `DemoCartSeeder`, and
 *   `DemoWishlistSeeder` — all three pick from the customers it creates.
 * - `DemoAddressSeeder` before `DemoOrderSeeder` — orders pass a
 *   `source_address_id` for customers holding a saved address.
 * - `DemoCouponSeeder` before `DemoOrderSeeder` — orders redeem real coupons,
 *   including closing `ONEUSEONLY`'s single-redemption gap.
 * - `DemoOrderSeeder` before `DemoReviewSeeder` — `CreateProductReview`
 *   enforces §24's verified-purchase rule itself, so a delivered order must
 *   already exist. This is enforced by the Action, not merely by seed order.
 * - `DemoReviewSeeder` before `DemoShowcaseOrderSeeder` — its case 6 needs
 *   real reviews already attached to be a genuine case rather than a label
 *   on an empty product.
 * - `ContentReferenceSeeder` before `DemoArticleSeeder` — article fixtures
 *   resolve category and tag slugs the same way product fixtures resolve
 *   brands. Unlike the catalogue pair, `DemoArticleSeeder` does *not* call
 *   its reference seeder, because articles also reference products by slug:
 *   the ordering against `DemoSeeder` has to be deliberate anyway, and
 *   hiding one call inside the other would obscure that.
 *
 * `docs/how-to/seed-the-database.md` remains the reference for running any
 * one of these alone; this class is the composed path, not a replacement for
 * knowing what the pieces do.
 */
class DemoDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        // Catalogue first — everything downstream resolves a slug against it.
        $this->call(CatalogueReferenceSeeder::class);
        $this->call(DemoSeeder::class);

        // People, then the state that hangs off them.
        $this->call(DemoCustomerSeeder::class);
        $this->call(DemoAddressSeeder::class);
        $this->call(DemoEngagementSeeder::class);
        $this->call(DemoCartSeeder::class);
        $this->call(DemoCouponSeeder::class);
        $this->call(DemoWishlistSeeder::class);

        // Transactional history, produced by running the real Actions.
        $this->call(DemoOrderSeeder::class);
        $this->call(DemoReviewSeeder::class);
        $this->call(DemoShowcaseOrderSeeder::class);

        // Content is independent of the transactional pass — it references
        // products by slug, never orders — but still needs its own lookup
        // rows first.
        $this->call(ContentReferenceSeeder::class);
        $this->call(DemoArticleSeeder::class);
    }
}
