<?php

declare(strict_types=1);

use App\Livewire\Account\DownloadData;
use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

it('streams the signed-in customer their data as JSON', function (): void {
    $user = User::factory()->create(['email' => 'me@example.com']);
    Order::factory()->for($user)->create(['anonymized_at' => null, 'serial_number' => 'ORD-DL-1']);

    $response = Livewire::actingAs($user)
        ->test(DownloadData::class)
        ->call('download');

    $response->assertFileDownloaded();

    $payload = $response->effects['download'] ?? null;
    // The streamed body is base64 in the Livewire test effect; decode and parse.
    $json = json_decode(base64_decode($payload['content']), true);

    expect($json['account']['email'])->toBe('me@example.com')
        ->and($json['orders'][0]['number'])->toBe('ORD-DL-1');
});

it('is not reachable by a guest', function (): void {
    $this->get('/account/data')->assertRedirect('/login');
});
