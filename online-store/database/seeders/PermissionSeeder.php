<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission catalogue. Roles attach to these in RoleSeeder; nothing
 * here grants anything on its own.
 *
 * Names are `{ability}_{resource}`, where the ability half matches the
 * Laravel policy method that checks it. That is what keeps a policy method
 * to one line — `ProductPolicy::update()` is `$user->can('update_product')`
 * — and it is why the ability is camelCase while the resource is
 * snake_case: the ability never contains an underscore, so the first
 * underscore always splits the two halves unambiguously
 * (`viewAny_product_review` is `viewAny` + `product_review`).
 *
 * Permission names deliberately differ from the bare policy method names.
 * A permission called `update` would collide with `ProductPolicy::update()`
 * when resolved through the gate; `update_product` cannot.
 *
 * The catalogue is seeded in full, including resources with no Filament
 * resource or policy yet. The role definitions in §3.3 and §3.4 are
 * statements about what a role may do, not about what happens to be built,
 * and content_editor's deny-list is only meaningful if the permissions it
 * excludes actually exist. Rows nothing checks yet are inert.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Standard Laravel policy methods, applied to every resource below.
     *
     * `restore` and `forceDelete` are omitted: only User, Product, Order,
     * and Article are soft-deletable, and no admin surface exposes a trash
     * view yet. They get added alongside that UI, not before it.
     *
     * @var list<string>
     */
    private const CRUD_ABILITIES = ['viewAny', 'view', 'create', 'update', 'delete'];

    /**
     * Resources getting the plain CRUD set.
     *
     * Snake_case singular, matching the model name as Laravel would
     * generate it — `ProductCategory` becomes `product_category`, so the
     * permission is `viewAny_product_category`.
     *
     * @var list<string>
     */
    private const CRUD_RESOURCES = [
        // Catalogue
        'product',
        'product_category',
        'product_variation',
        'brand',
        'attribute',
        'attribute_value',
        'coupon',
        // Content
        'article',
        'article_category',
        'tag',
        // Operations
        'order',
        'shipment',
        'inventory',
        'carrier',
        // Administration
        'user',
        'role',
    ];

    /**
     * Resources nobody creates by hand, so `create` is never granted and a
     * `create_payment` permission would only ever be a mistake waiting to be
     * ticked in the roles UI.
     *
     * A payment row is written by Stripe's webhook (§13), a review by a
     * customer who bought the product (§24), a contact message and a
     * newsletter subscription by a public form (§26). Administration means
     * moderating and correcting these, not authoring them.
     *
     * `delete` is granted because §24 requires administrators to be able to
     * remove inappropriate reviews, and GDPR erasure needs the same for
     * contact messages and subscribers — see `explanation/gdpr.md`. Payment
     * keeps `update` for manual correction (a COD remittance marked paid by
     * hand) but has `refund_payment` as a separate ability below, since
     * refunding money is not editing a row.
     *
     * @var list<string>
     */
    private const NON_AUTHORED_RESOURCES = [
        'payment',
        'product_review',
        'contact_message',
        'newsletter_subscriber',
    ];

    /**
     * The abilities NON_AUTHORED_RESOURCES get: everything except `create`.
     *
     * @var list<string>
     */
    private const NON_AUTHORED_ABILITIES = ['viewAny', 'view', 'update', 'delete'];

    /**
     * Abilities that are domain verbs rather than CRUD, and the resource
     * each belongs to.
     *
     * These exist because the specification distinguishes them from plain
     * editing: §17 says not every employee may select every order status,
     * §22 separates writing a draft from publishing it, and §24 makes
     * review approval a moderation step rather than an edit.
     *
     * @var array<string, list<string>>
     */
    private const DOMAIN_ABILITIES = [
        'order' => ['updateStatus', 'addInternalNote'],
        'article' => ['publish'],
        'product_review' => ['approve'],
        'payment' => ['refund'],
    ];

    /**
     * Permissions with no model behind them, checked directly on a Filament
     * page rather than through a policy. §3.5 names all three as
     * administrator-only.
     *
     * @var list<string>
     */
    private const STANDALONE_PERMISSIONS = [
        'view_report',
        'view_audit_log',
        'update_setting',
    ];

    public function run(): void
    {
        // The registrar caches the whole permission table for 24 hours. A
        // seeder that writes permissions and then reads one back — which
        // RoleSeeder does immediately after this — otherwise resolves
        // against the pre-seed cache and silently attaches nothing.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissionNames() as $name) {
            // Guard name is explicit rather than left to the config
            // default: a permission created under the wrong guard fails
            // every check without raising anything.
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    /**
     * Every permission name this seeder is responsible for.
     *
     * @return list<string>
     */
    private function permissionNames(): array
    {
        $names = [];

        foreach (self::CRUD_RESOURCES as $resource) {
            foreach (self::CRUD_ABILITIES as $ability) {
                $names[] = "{$ability}_{$resource}";
            }
        }

        foreach (self::NON_AUTHORED_RESOURCES as $resource) {
            foreach (self::NON_AUTHORED_ABILITIES as $ability) {
                $names[] = "{$ability}_{$resource}";
            }
        }

        foreach (self::DOMAIN_ABILITIES as $resource => $abilities) {
            foreach ($abilities as $ability) {
                $names[] = "{$ability}_{$resource}";
            }
        }

        return [...$names, ...self::STANDALONE_PERMISSIONS];
    }
}
