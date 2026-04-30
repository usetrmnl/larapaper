<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Playlist;
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

test('screenshot instance rejects invalid configuration url on save', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'screenshot',
        'data_strategy' => 'static',
        'configuration' => ['url' => 'https://example.com'],
    ]);
    $this->actingAs($user);

    Livewire::test('plugins.type-instance', ['type' => 'screenshot', 'plugin' => $plugin])
        ->set('configuration.url', 'not-a-valid-url')
        ->call('updateConfiguration')
        ->assertHasErrors(['configuration.url']);

    expect($plugin->fresh()->configuration['url'])->toBe('https://example.com');
});

test('screenshot instance cannot add to playlist without valid url', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($device)->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'screenshot',
        'data_strategy' => 'static',
        'configuration' => [],
    ]);
    $this->actingAs($user);

    Livewire::test('plugins.type-instance', ['type' => 'screenshot', 'plugin' => $plugin])
        ->set('checked_devices', [(string) $device->id])
        ->set('device_playlists.'.$device->id, (string) $playlist->id)
        ->call('addToPlaylist')
        ->assertHasErrors(['configuration.url']);

    expect($playlist->fresh()->items)->toHaveCount(0);
});

test('screenshot instance can add to playlist when url is valid', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($device)->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'screenshot',
        'data_strategy' => 'static',
        'configuration' => ['url' => 'https://example.com'],
    ]);
    $this->actingAs($user);

    Livewire::test('plugins.type-instance', ['type' => 'screenshot', 'plugin' => $plugin])
        ->set('checked_devices', [(string) $device->id])
        ->set('device_playlists.'.$device->id, (string) $playlist->id)
        ->call('addToPlaylist')
        ->assertHasNoErrors();

    expect($playlist->fresh()->items)->toHaveCount(1);
    expect($playlist->fresh()->items->first()->plugin_id)->toBe($plugin->id);
});

test('screenshot instance persists valid http or https url', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'screenshot',
        'data_strategy' => 'static',
        'configuration' => ['url' => 'https://old.example'],
    ]);
    $this->actingAs($user);

    Livewire::test('plugins.type-instance', ['type' => 'screenshot', 'plugin' => $plugin])
        ->set('configuration.url', 'https://example.com/path')
        ->call('updateConfiguration')
        ->assertHasNoErrors();

    expect($plugin->fresh()->configuration['url'])->toBe('https://example.com/path');
});

test('instance page 404s when plugin type does not match route type', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->get("/plugins/type/screenshot/{$plugin->id}")->assertNotFound();
});
