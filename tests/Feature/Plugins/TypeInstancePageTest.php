<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Livewire\Livewire;

test('shared listing page renders for image webhook type', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('plugins.type', ['type' => 'image_webhook'])
        ->assertSee('Image Webhook')
        ->assertSee($plugin->name);
});

test('shared listing page 404s for unknown type', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/plugins/type/nonexistent')->assertNotFound();
});

test('shared instance page renders name form, delete modal, and image webhook settings partial', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('plugins.type-instance', ['type' => 'image_webhook', 'plugin' => $plugin])
        ->assertSee('Image Webhook – '.$plugin->name)
        ->assertSee('Delete Instance')
        ->assertSee('Webhook URL')
        ->assertSee('POST an image');
});

test('shared instance page renders schema-driven fields for screenshot plugin', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'screenshot',
        'data_strategy' => 'static',
    ]);
    $this->actingAs($user);

    Livewire::test('plugins.type-instance', ['type' => 'screenshot', 'plugin' => $plugin])
        ->assertSee('Screenshot – '.$plugin->name)
        ->assertSee('URL');
});

test('instance page 404s when plugin type does not match route type', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->get("/plugins/type/screenshot/{$plugin->id}")->assertNotFound();
});
