<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

it('creates exactly one administrator from options and config', function (): void {
    Config::set('auth.admin_password', 'correct-horse-battery-staple');

    $this->artisan('admin:bootstrap', [
        '--email' => 'root@example.com',
        '--first-name' => 'Root',
        '--last-name' => 'Admin',
    ])
        ->assertExitCode(0);

    $user = User::where('email', 'root@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->hasRole('administrator'))->toBeTrue();
});

it('refuses to run once an administrator already exists', function (): void {
    Config::set('auth.admin_password', 'correct-horse-battery-staple');

    User::factory()->create()->assignRole('administrator');

    $this->artisan('admin:bootstrap', [
        '--email' => 'second@example.com',
        '--first-name' => 'Second',
        '--last-name' => 'Admin',
    ])
        ->assertExitCode(1);

    expect(User::where('email', 'second@example.com')->exists())->toBeFalse();
});

it('rejects a password that fails the default password policy', function (): void {
    Config::set('auth.admin_password', 'short');

    $this->artisan('admin:bootstrap', [
        '--email' => 'weak@example.com',
        '--first-name' => 'Weak',
        '--last-name' => 'Password',
    ])
        ->assertExitCode(1);

    expect(User::where('email', 'weak@example.com')->exists())->toBeFalse();
});
