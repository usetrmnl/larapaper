<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

test('plugins api page documents companion app setup', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('plugins.api')
        ->assertSee('TRMNL Companion app', false)
        ->assertSee(url('/api/companion'), false)
        ->assertSee(route('settings.api-tokens'), false);
});
