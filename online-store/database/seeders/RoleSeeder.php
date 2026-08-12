<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Permissions held by content_editor, from §3.3.
     *
     * @var list<string>
     */
    private const CONTENT_EDITOR_PERMISSIONS = [
        'viewAny_article', 'view_article', 'create_article', 'update_article', 'delete_article', 'publish_article',
        'viewAny_article_category', 'view_article_category', 'create_article_category', 'update_article_category', 'delete_article_category',
        'viewAny_tag', 'view_tag', 'create_tag', 'update_tag', 'delete_tag',
        'viewAny_product_review', 'view_product_review', 'approve_product_review', 'delete_product_review',
    ];

    /**
     * Permissions held by warehouse_employee, from §3.4.
     *
     * Orders are viewable and their status changeable, but not creatable or
     * deletable — an order exists because a customer placed it, and §17
     * requires the status history rather than direct edits. `carrier` is
     * read-only for the same reason it is denied to content_editor below:
     * picking a courier is not the same as administering one.
     *
     * @var list<string>
     */
    private const WAREHOUSE_EMPLOYEE_PERMISSIONS = [
        'viewAny_order', 'view_order', 'updateStatus_order', 'addInternalNote_order',
        'viewAny_shipment', 'view_shipment', 'create_shipment', 'update_shipment',
        'viewAny_inventory', 'view_inventory', 'update_inventory',
        'viewAny_carrier',
    ];

    /**
     * Reference data needed in every environment, production included — a
     * role must exist before anyone can be assigned to it through the panel.
     *
     * administrator holds no permissions on purpose. `Gate::before` in
     * AppServiceProvider returns true for that role and short-circuits every
     * check, so attaching all 108 rows would be redundant and would drift out
     * of step with the catalogue every time a permission is added.
     *
     * The two staff roles are synced rather than added to. syncPermissions()
     * makes re-seeding deterministic, which ADR-0003 requires: a permission
     * removed from a list above is actually revoked on the next run, where
     * givePermissionTo() would leave it attached forever. The cost is that a
     * re-seed discards runtime edits an administrator made through the panel,
     * which is why DatabaseSeeder is a first-boot and development operation
     * rather than something to run against a populated production database.
     */
    public function run(): void
    {
        foreach (User::STAFF_ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Role::findByName('content_editor')
            ->syncPermissions(self::CONTENT_EDITOR_PERMISSIONS);

        Role::findByName('warehouse_employee')
            ->syncPermissions(self::WAREHOUSE_EMPLOYEE_PERMISSIONS);

        // Denied to content_editor by name in §3.3, not merely left out:
        // payment (Stripe settings and payment data), carrier (courier
        // credentials), user and role (user permissions), and setting.
        // Anyone widening the list above should read that section first —
        // the omission is a requirement, not an oversight.
    }
}
