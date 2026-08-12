<?php

declare(strict_types=1);

use App\Models\ArticleCategory;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Carrier;
use App\Models\ProductCategory;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
 * §37 criterion 18 — "user roles cannot access prohibited features".
 *
 * The negative cases are the point. A test that only asserts content_editor
 * reaches articles would pass just as happily against a system with no
 * authorization at all, which is exactly the state this branch replaced.
 */

beforeEach(function (): void {
    // RefreshDatabase truncates but does not seed, and the registrar caches
    // the permission table for 24 hours — without this the second test in the
    // run resolves against the first test's now-deleted rows.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});

/** The seven models with a Filament resource, and who may list them. */
dataset('panel resources', [
    'Brand' => [Brand::class],
    'Tag' => [Tag::class],
    'ProductCategory' => [ProductCategory::class],
    'ArticleCategory' => [ArticleCategory::class],
    'Attribute' => [Attribute::class],
    'AttributeValue' => [AttributeValue::class],
    'Carrier' => [Carrier::class],
]);

function userByEmail(string $email): User
{
    return User::where('email', $email)->firstOrFail();
}

it('seeds the three staff roles and a customer with none', function (): void {
    expect(userByEmail('admin@example.com')->hasRole('administrator'))->toBeTrue()
        ->and(userByEmail('editor@example.com')->hasRole('content_editor'))->toBeTrue()
        ->and(userByEmail('warehouse@example.com')->hasRole('warehouse_employee'))->toBeTrue()
        ->and(userByEmail('customer@example.com')->getRoleNames())->toBeEmpty();
});

it('lets staff reach the admin panel and keeps a customer out', function (): void {
    $panel = filament()->getPanel('admin');

    expect(userByEmail('admin@example.com')->canAccessPanel($panel))->toBeTrue()
        ->and(userByEmail('editor@example.com')->canAccessPanel($panel))->toBeTrue()
        ->and(userByEmail('warehouse@example.com')->canAccessPanel($panel))->toBeTrue()
        ->and(userByEmail('customer@example.com')->canAccessPanel($panel))->toBeFalse();
});

it('returns 403 from /admin for a customer', function (): void {
    $this->actingAs(userByEmail('customer@example.com'))
        ->get('/admin')
        ->assertForbidden();
});

it('grants an administrator every ability without attaching any permission', function (string $model): void {
    $admin = userByEmail('admin@example.com');

    expect($admin->roles()->first()->permissions)->toBeEmpty()
        ->and($admin->can('viewAny', $model))->toBeTrue()
        ->and($admin->can('create', $model))->toBeTrue();
})->with('panel resources');

it('denies a customer every resource in the panel', function (string $model): void {
    expect(userByEmail('customer@example.com')->can('viewAny', $model))->toBeFalse();
})->with('panel resources');

it('gives content_editor content resources and nothing else', function (): void {
    $editor = userByEmail('editor@example.com');

    // §3.3 grants article categories and tags. Articles and reviews have no
    // Filament resource yet, so only these two are reachable today.
    expect($editor->can('viewAny', Tag::class))->toBeTrue()
        ->and($editor->can('create', Tag::class))->toBeTrue()
        ->and($editor->can('viewAny', ArticleCategory::class))->toBeTrue();

    // Catalogue data is not content.
    expect($editor->can('viewAny', Brand::class))->toBeFalse()
        ->and($editor->can('viewAny', ProductCategory::class))->toBeFalse()
        ->and($editor->can('viewAny', Attribute::class))->toBeFalse()
        ->and($editor->can('viewAny', AttributeValue::class))->toBeFalse();

    // §3.3 denies these by name rather than by omission.
    expect($editor->can('viewAny', Carrier::class))->toBeFalse()
        ->and($editor->can('viewAny_payment'))->toBeFalse()
        ->and($editor->can('viewAny_user'))->toBeFalse()
        ->and($editor->can('viewAny_role'))->toBeFalse()
        ->and($editor->can('update_setting'))->toBeFalse();
});

it('gives warehouse_employee operations resources and read-only carriers', function (): void {
    $warehouse = userByEmail('warehouse@example.com');

    expect($warehouse->can('viewAny_order'))->toBeTrue()
        ->and($warehouse->can('updateStatus_order'))->toBeTrue()
        ->and($warehouse->can('viewAny_shipment'))->toBeTrue()
        ->and($warehouse->can('create_shipment'))->toBeTrue()
        ->and($warehouse->can('update_inventory'))->toBeTrue();

    // An order exists because a customer placed it; §17 requires status
    // history rather than direct creation or deletion.
    expect($warehouse->can('create_order'))->toBeFalse()
        ->and($warehouse->can('delete_order'))->toBeFalse();

    // Picking a courier is not administering one.
    expect($warehouse->can('viewAny', Carrier::class))->toBeTrue()
        ->and($warehouse->can('create', Carrier::class))->toBeFalse()
        ->and($warehouse->can('update_carrier'))->toBeFalse()
        ->and($warehouse->can('delete_carrier'))->toBeFalse();

    // Not products, not articles, not payments.
    expect($warehouse->can('viewAny', Brand::class))->toBeFalse()
        ->and($warehouse->can('viewAny', Tag::class))->toBeFalse()
        ->and($warehouse->can('viewAny_product'))->toBeFalse()
        ->and($warehouse->can('viewAny_article'))->toBeFalse()
        ->and($warehouse->can('viewAny_payment'))->toBeFalse();
});

it('resolves a policy for every model with a Filament resource', function (string $model): void {
    // A resource whose model has no policy is reachable by anyone who passes
    // canAccessPanel(), which is the gap this branch closed. Filament reads
    // authorization off the policy, so a missing one fails open.
    expect(Gate::getPolicyFor($model))->not->toBeNull();
})->with('panel resources');

it('revokes a permission removed from the seeder on the next run', function (): void {
    $editor = userByEmail('editor@example.com');
    $editor->roles()->first()->givePermissionTo('viewAny_payment');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($editor->fresh()->can('viewAny_payment'))->toBeTrue();

    // syncPermissions rather than givePermissionTo is what makes this true;
    // it is also what discards an administrator's runtime edits, which is why
    // DatabaseSeeder is a first-boot and development operation.
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($editor->fresh()->can('viewAny_payment'))->toBeFalse();
});
