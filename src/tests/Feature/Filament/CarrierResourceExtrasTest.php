<?php

declare(strict_types=1);

use App\Filament\Resources\Carriers\Pages\ListCarriers;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('administrator');
    $this->actingAs($admin);
});

it('explains the COD abbreviation under the Carriers list title', function (): void {
    Livewire::test(ListCarriers::class)
        ->assertOk()
        ->assertSee('*COD = Cash on delivery');
});

it('offers a way back to the storefront from the admin user menu', function (): void {
    $labels = collect(filament()->getUserMenuItems())->map(fn ($item) => $item->getLabel());

    expect($labels)->toContain('View site');
});
