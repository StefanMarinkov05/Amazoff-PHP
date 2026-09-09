<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The permission catalogue's shape, in one place.
 *
 * `PermissionSeeder` writes these rows; the roles UI groups its checkboxes by
 * them. Both need the same answer to "which abilities belong to which
 * resource", and CLAUDE.md's rule against the same list appearing twice is
 * what puts it here rather than in either caller.
 *
 * This is not an enum. `App\Enums` is for fixed value sets that are cast on a
 * model and rendered through Filament's HasLabel — a permission name is a
 * database row whose set grows at runtime under §3.5, so the catalogue
 * describes the shape the seeder creates rather than constraining what may
 * exist. A role edited through the panel can hold a permission this class
 * never mentions, and that is intended.
 */
final class PermissionCatalogue
{
    /**
     * Standard Laravel policy methods.
     *
     * `restore` and `forceDelete` are omitted: only User, Product, and
     * ProductVariation soft-delete, and no admin surface exposes a trash view
     * yet. They get added alongside that UI, not before it.
     *
     * @var list<string>
     */
    public const CRUD_ABILITIES = ['viewAny', 'view', 'create', 'update', 'delete'];

    /**
     * The abilities NON_AUTHORED_RESOURCES get: everything except `create`.
     *
     * @var list<string>
     */
    public const NON_AUTHORED_ABILITIES = ['viewAny', 'view', 'update', 'delete'];

    /**
     * Resources getting the plain CRUD set, grouped as the roles UI shows
     * them. Snake_case singular, matching the model name as Laravel would
     * generate it — `ProductCategory` becomes `product_category`.
     *
     * @var array<string, list<string>>
     */
    public const CRUD_RESOURCES = [
        'Catalogue' => [
            'product',
            'product_category',
            'product_variation',
            'brand',
            'attribute',
            'attribute_value',
            'coupon',
        ],
        'Content' => [
            'article',
            'article_category',
            'tag',
        ],
        'Operations' => [
            'order',
            'shipment',
            'inventory',
            'carrier',
        ],
        'Administration' => [
            'user',
            'role',
        ],
    ];

    /**
     * Resources nobody creates by hand, so `create` is never granted and a
     * `create_payment` permission would only ever be a mistake waiting to be
     * ticked in the roles UI.
     *
     * A payment row is written by Stripe's webhook (§13), a review by a
     * customer who bought the product (§24), a contact message and a
     * newsletter subscription by a public form (§26).
     *
     * @var list<string>
     */
    public const NON_AUTHORED_RESOURCES = [
        'payment',
        'product_review',
        'contact_message',
        'newsletter_subscriber',
    ];

    /**
     * Abilities that are domain verbs rather than CRUD.
     *
     * §17 says not every employee may select every order status, §22
     * separates writing a draft from publishing it, and §24 makes review
     * approval a moderation step rather than an edit.
     *
     * `cancel_order` and `refund_order` exist alongside `updateStatus_order`
     * rather than folded into it: ADR-0004's own context names cancelling
     * and refunding as administrator moves, distinct from the routine
     * status advance a warehouse employee makes. One flat
     * `updateStatus_order` permission cannot express that split — see
     * ADR-0011 and `OrderPolicy::updateStatus()`, which routes to one of the
     * three depending on the target status.
     *
     * `assignRole_user` exists for the same reason and a sharper one:
     * assigning a role is how someone becomes an administrator, so folding
     * it into `update_user` would make every holder of `update_user` able to
     * promote themselves. `UserPolicy`'s docblock named this as the thing to
     * fix when a User resource was built, and this is that split — editing a
     * user's name and granting them the panel are not the same act.
     *
     * @var array<string, list<string>>
     */
    public const DOMAIN_ABILITIES = [
        'order' => ['updateStatus', 'addInternalNote', 'cancel', 'refund'],
        'article' => ['publish'],
        'product_review' => ['approve'],
        'payment' => ['refund'],
        'user' => ['assignRole', 'erase'],
    ];

    /**
     * Permissions with no model behind them, checked directly on a Filament
     * page rather than through a policy. §3.5 names all three as
     * administrator-only.
     *
     * @var list<string>
     */
    public const STANDALONE_PERMISSIONS = [
        'view_report',
        'view_audit_log',
        'update_setting',
    ];

    /**
     * Every permission name the seeder is responsible for.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (self::CRUD_RESOURCES as $resources) {
            foreach ($resources as $resource) {
                foreach (self::CRUD_ABILITIES as $ability) {
                    $names[] = "{$ability}_{$resource}";
                }
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

    /**
     * Every permission belonging to one resource, CRUD and domain verbs
     * together, in the order the roles UI should show them.
     *
     * @return list<string>
     */
    public static function namesForResource(string $resource): array
    {
        $abilities = in_array($resource, self::NON_AUTHORED_RESOURCES, true)
            ? self::NON_AUTHORED_ABILITIES
            : self::CRUD_ABILITIES;

        return array_map(
            fn (string $ability): string => "{$ability}_{$resource}",
            [...$abilities, ...(self::DOMAIN_ABILITIES[$resource] ?? [])],
        );
    }

    /**
     * Resource names grouped under the heading the roles UI renders, with the
     * non-authored resources folded into the groups they belong to.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        $groups = self::CRUD_RESOURCES;

        $groups['Catalogue'][] = 'product_review';
        $groups['Operations'][] = 'payment';
        $groups['Administration'][] = 'contact_message';
        $groups['Administration'][] = 'newsletter_subscriber';

        return $groups;
    }

    /**
     * `viewAny_product_category` reads as "View any product category".
     *
     * The ability is camelCase and never contains an underscore, so the first
     * underscore splits ability from resource unambiguously — which is what
     * lets this method work for `viewAny_product_review` as well as
     * `approve_product_review`.
     */
    public static function label(string $permission): string
    {
        [$ability, $resource] = explode('_', $permission, 2);

        $ability = strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $ability) ?? $ability);

        return ucfirst($ability).' '.str_replace('_', ' ', $resource);
    }
}
