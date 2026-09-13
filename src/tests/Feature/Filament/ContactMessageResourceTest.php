<?php

declare(strict_types=1);

use App\Filament\Resources\ContactMessages\Pages\ViewContactMessage;
use App\Models\ContactMessage;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The "Mark handled" header action is where ContactMessageReceived's link
 * lands, so it is the shortcut the email promises.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});

it('offers Mark handled on the page the email links to', function (): void {
    $message = ContactMessage::factory()->create();

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get("/admin/contact-messages/{$message->getKey()}")
        ->assertOk()
        ->assertSee('Mark handled');
});

it('marks an outstanding message handled in one click', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
    $message = ContactMessage::factory()->create();

    Livewire::test(ViewContactMessage::class, ['record' => $message->getKey()])
        ->callAction('markHandled')
        ->assertNotified('Marked handled');

    expect($message->fresh()->handled_at)->not->toBeNull();
});

it('hides Mark handled once the message is handled', function (): void {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
    $message = ContactMessage::factory()->handled()->create();

    Livewire::test(ViewContactMessage::class, ['record' => $message->getKey()])
        ->assertActionHidden('markHandled');
});

it('hides Mark handled from staff who may view but not update the message', function (): void {
    $warehouse = User::where('email', 'warehouse@example.com')->firstOrFail();
    $warehouse->givePermissionTo(['viewAny_contact_message', 'view_contact_message']);
    $this->actingAs($warehouse);
    $message = ContactMessage::factory()->create();

    Livewire::test(ViewContactMessage::class, ['record' => $message->getKey()])
        ->assertActionHidden('markHandled');

    expect($message->fresh()->handled_at)->toBeNull();
});
