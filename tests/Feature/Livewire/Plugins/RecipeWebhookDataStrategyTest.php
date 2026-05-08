<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Livewire\Livewire;

test('recipe editor renders for webhook data strategy', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'webhook',
    ]);

    $expectedUrl = route('api.custom_plugins.webhook', ['plugin' => $plugin->uuid]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertSee($expectedUrl, false);
});
