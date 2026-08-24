<?php

declare(strict_types=1);

namespace Database\Seeders\System;

use App\Support\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes the permission catalogue. Roles attach to these in RoleSeeder;
 * nothing here grants anything on its own.
 *
 * The catalogue's shape lives in App\Support\PermissionCatalogue, because the
 * roles UI groups its checkboxes by the same resources and CLAUDE.md does not
 * allow the same list twice.
 *
 * Names are `{ability}_{resource}`, where the ability half matches the
 * Laravel policy method that checks it. That is what keeps a policy method to
 * one line — `ProductPolicy::update()` is `$user->can('update_product')` —
 * and it is why the ability is camelCase while the resource is snake_case:
 * the ability never contains an underscore, so the first underscore always
 * splits the two halves unambiguously (`viewAny_product_review` is `viewAny`
 * + `product_review`).
 *
 * Permission names deliberately differ from the bare policy method names. A
 * permission called `update` would collide with `ProductPolicy::update()`
 * when resolved through the gate; `update_product` cannot.
 *
 * The catalogue is seeded in full, including resources with no Filament
 * resource yet. The role definitions in §3.3 and §3.4 are statements about
 * what a role may do, not about what happens to be built, and
 * content_editor's deny-list is only meaningful if the permissions it
 * excludes actually exist. Rows nothing checks yet are inert.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // The registrar caches the whole permission table for 24 hours. A
        // seeder that writes permissions and then reads one back — which
        // RoleSeeder does immediately after this — otherwise resolves
        // against the pre-seed cache and silently attaches nothing.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalogue::names() as $name) {
            // Guard name is explicit rather than left to the config
            // default: a permission created under the wrong guard fails
            // every check without raising anything.
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
