<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * UserPolicy's docblock named the gap this closes: assigning a role is how
 * an account gains panel access, so gating it by update_user would let
 * anyone who may edit a user promote themselves to administrator. It is now
 * its own ability, assignRole_user.
 *
 * The tests that matter here are the denial ones. A form field being
 * disabled is UI; what has to hold is that a crafted submission cannot
 * write roles — a hidden button is not security (CLAUDE.md).
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});

it('is reachable by administrator and denied to the other staff roles', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/admin/users')
        ->assertOk();

    $this->actingAs(User::where('email', 'warehouse@example.com')->firstOrFail())
        ->get('/admin/users')
        ->assertForbidden();

    $this->actingAs(User::where('email', 'editor@example.com')->firstOrFail())
        ->get('/admin/users')
        ->assertForbidden();
});

it('lets an administrator assign a staff role', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    $customer = User::where('email', 'customer@example.com')->firstOrFail();
    $editorRole = Role::where('name', 'content_editor')->firstOrFail();

    Livewire::test(EditUser::class, ['record' => $customer->getKey()])
        ->fillForm(['roles' => [$editorRole->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->fresh()->hasRole('content_editor'))->toBeTrue();
});

/*
 * The escalation path this ability exists to close: an actor holding
 * update_user but not assignRole_user must not be able to grant a role.
 *
 * These two call mutateFormDataBeforeSave directly rather than going through
 * fillForm()->call('save'), because the form disables the roles field for
 * exactly these actors — so a fillForm submission never carries `roles` at
 * all, and a test written that way passes with the server-side guard
 * deleted. It was, until the guard was removed to check. This is the seam a
 * crafted Livewire payload actually reaches, and the one that has to hold:
 * a disabled control is not security (CLAUDE.md).
 */
it('strips a submitted role from an actor holding update_user but not assignRole_user', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['viewAny_user', 'view_user', 'update_user']);
    $this->actingAs($actor);

    $target = User::where('email', 'customer@example.com')->firstOrFail();
    $adminRole = Role::where('name', 'administrator')->firstOrFail();

    $page = Livewire::test(EditUser::class, ['record' => $target->getKey()])->instance();

    $mutated = (fn (array $data): array => $this->mutateFormDataBeforeSave($data))
        ->call($page, ['first_name' => 'Edited', 'roles' => [$adminRole->getKey()]]);

    expect($mutated)->not->toHaveKey('roles')
        // The rest of the edit survives — the key is dropped, not the
        // whole submission refused.
        ->and($mutated['first_name'])->toBe('Edited');
});

it('lets an administrator through the same seam for someone else', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    $target = User::where('email', 'customer@example.com')->firstOrFail();
    $editorRole = Role::where('name', 'content_editor')->firstOrFail();

    $page = Livewire::test(EditUser::class, ['record' => $target->getKey()])->instance();

    $mutated = (fn (array $data): array => $this->mutateFormDataBeforeSave($data))
        ->call($page, ['roles' => [$editorRole->getKey()]]);

    expect($mutated)->toHaveKey('roles')
        ->and($mutated['roles'])->toBe([$editorRole->getKey()]);
});

/*
 * Self-assignment, refused in EditUser rather than UserPolicy.
 *
 * The policy cannot express it: Gate::before short-circuits every check for
 * an administrator — the only role holding assignRole_user — so a
 * policy-level `$model->id !== $user->id` would never execute. ADR-0006
 * accepted that trade, and tech-stack-overview.md names this exact
 * consequence. An administrator clearing their own roles is the one edit
 * that can lock the panel against the person making it.
 */
it('strips an administrator changing their own roles, at the seam', function (): void {
    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    $this->actingAs($admin);

    $page = Livewire::test(EditUser::class, ['record' => $admin->getKey()])->instance();

    $mutated = (fn (array $data): array => $this->mutateFormDataBeforeSave($data))
        ->call($page, ['first_name' => 'Edited', 'roles' => []]);

    expect($mutated)->not->toHaveKey('roles')
        ->and($mutated['first_name'])->toBe('Edited');
});

it('does not offer a delete action, since §19 needs the order history to survive', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    $customer = User::where('email', 'customer@example.com')->firstOrFail();

    Livewire::test(EditUser::class, ['record' => $customer->getKey()])
        ->assertActionDoesNotExist('delete');
});

it('deactivates an account through the panel', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

    $customer = User::where('email', 'customer@example.com')->firstOrFail();

    Livewire::test(EditUser::class, ['record' => $customer->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->fresh()->is_active)->toBeFalse();
});
