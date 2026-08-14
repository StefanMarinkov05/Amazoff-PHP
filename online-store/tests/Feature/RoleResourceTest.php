<?php

declare(strict_types=1);

use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * §3.5 — roles and permissions editable without a deploy. Until this resource
 * existed the property was architectural: changing a role meant editing
 * RoleSeeder and re-seeding, which is a deploy.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});

function actingAsAdmin(): User
{
    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    test()->actingAs($admin);

    return $admin;
}

it('renders the roles list for an administrator', function (): void {
    actingAsAdmin();

    Livewire::test(ListRoles::class)
        ->assertOk()
        ->assertCanSeeTableRecords(Role::all());
});

it('renders the edit form with the permission checkboxes', function (): void {
    actingAsAdmin();

    // A form schema that references a missing relationship or a wrong
    // component name passes Pint and Larastan and fails on render, so this
    // asserting nothing beyond "it loaded" is still worth having.
    Livewire::test(EditRole::class, ['record' => Role::findByName('content_editor')->getKey()])
        ->assertOk();
});

it('grants a permission through the form and has it take effect', function (): void {
    actingAsAdmin();
    $editor = User::where('email', 'editor@example.com')->firstOrFail();
    $role = Role::findByName('content_editor');

    expect($editor->can('viewAny_brand'))->toBeFalse();

    $existing = $role->permissions->pluck('id')->all();
    $brandPermissions = Permission::whereIn('name', [
        'viewAny_brand', 'view_brand', 'create_brand', 'update_brand', 'delete_brand',
    ])->pluck('id')->all();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions_brand' => $brandPermissions])
        ->call('save')
        ->assertHasNoFormErrors();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The point of §3.5: no deploy, no re-seed, effective on the next request.
    expect($editor->fresh()->can('viewAny_brand'))->toBeTrue();

    // Editing one resource's checkboxes must not clear another's — each list
    // is scoped to its own permission names for exactly this reason.
    expect($role->fresh()->permissions->pluck('id')->intersect($existing))
        ->toHaveCount(count($existing));
});

it('revokes a permission through the form', function (): void {
    actingAsAdmin();
    $editor = User::where('email', 'editor@example.com')->firstOrFail();
    $role = Role::findByName('content_editor');

    expect($editor->can('delete_tag'))->toBeTrue();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions_tag' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($editor->fresh()->can('delete_tag'))->toBeFalse()
        // Articles are a different resource and a different checkbox list.
        ->and($editor->fresh()->can('delete_article'))->toBeTrue();
});

it('keeps non-administrators out of the roles resource', function (): void {
    foreach (['editor@example.com', 'warehouse@example.com'] as $email) {
        $user = User::where('email', $email)->firstOrFail();

        // RolePolicy is registered by hand in AppServiceProvider — spatie's
        // Role is outside App\Models, so convention would leave the model
        // that controls every other role ungated.
        expect($user->can('viewAny', Role::class))->toBeFalse();
    }
});
