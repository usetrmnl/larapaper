<?php

use App\Jobs\PrerenderPluginScreenJob;
use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Queue::fake();
});

/**
 * Show the plugins on the device through an always-active playlist.
 */
function showOnDevice(Device $device, Plugin ...$plugins): void
{
    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    foreach ($plugins as $order => $plugin) {
        PlaylistItem::factory()->create([
            'playlist_id' => $playlist->id,
            'plugin_id' => $plugin->id,
            'order' => $order,
            'is_active' => true,
        ]);
    }
}

test('device refresh renders the polling recipes the device shows, stale or not', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $recipe = Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'data_payload_updated_at' => now(),
    ]);
    $webhook = Plugin::factory()->create(['plugin_type' => 'recipe', 'data_strategy' => 'webhook']);
    showOnDevice($device, $recipe, $webhook);

    Sanctum::actingAs($user);

    $this->postJson(route('api.devices.refreshes.store', $device))->assertAccepted();

    Queue::assertPushedTimes(PrerenderPluginScreenJob::class, 1);
    Queue::assertPushed(PrerenderPluginScreenJob::class, fn (PrerenderPluginScreenJob $job): bool => $job->plugin->is($recipe) && $job->device->is($device));
});

test('device refresh of a mirror renders the screens of the device it mirrors', function (): void {
    $user = User::factory()->create();
    $source = Device::factory()->create(['user_id' => $user->id]);
    $mirror = Device::factory()->create(['user_id' => $user->id, 'mirror_device_id' => $source->id]);
    $recipe = Plugin::factory()->create(['plugin_type' => 'recipe', 'data_strategy' => 'polling']);
    showOnDevice($source, $recipe);

    Sanctum::actingAs($user);

    $this->postJson(route('api.devices.refreshes.store', $mirror))->assertAccepted();

    Queue::assertPushed(PrerenderPluginScreenJob::class, fn (PrerenderPluginScreenJob $job): bool => $job->device->is($source));
});

test('device refresh is not found for devices of other users', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => User::factory()->create()->id]);

    Sanctum::actingAs($user);

    $this->postJson(route('api.devices.refreshes.store', $device))->assertNotFound();

    Queue::assertNothingPushed();
});

test('plugin refresh renders a recipe for the device that shows it', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $recipe = Plugin::factory()->create(['user_id' => $user->id, 'plugin_type' => 'recipe', 'data_strategy' => 'polling']);
    showOnDevice($device, $recipe);

    Sanctum::actingAs($user);

    $this->postJson(route('api.plugin_settings.refreshes.store', $recipe->uuid))
        ->assertAccepted()
        ->assertJson(['data' => ['status' => 'queued']]);

    Queue::assertPushed(PrerenderPluginScreenJob::class, fn (PrerenderPluginScreenJob $job): bool => $job->plugin->is($recipe) && $job->device->is($device));
});

test('plugin refresh polls a recipe that no device shows', function (): void {
    $user = User::factory()->create();
    $recipe = Plugin::factory()->create(['user_id' => $user->id, 'plugin_type' => 'recipe', 'data_strategy' => 'polling']);

    Sanctum::actingAs($user);

    $this->postJson(route('api.plugin_settings.refreshes.store', $recipe->uuid))->assertAccepted();

    Queue::assertNotPushed(PrerenderPluginScreenJob::class);
    Queue::assertPushed(CallQueuedClosure::class);
});

test('plugin refresh is not found for plugins of other users', function (): void {
    $user = User::factory()->create();
    $recipe = Plugin::factory()->create(['user_id' => User::factory()->create()->id, 'plugin_type' => 'recipe']);

    Sanctum::actingAs($user);

    $this->postJson(route('api.plugin_settings.refreshes.store', $recipe->uuid))->assertNotFound();
});

test('device refresh requires authentication with 401', function (): void {
    $device = Device::factory()->create();

    $this->postJson(route('api.devices.refreshes.store', $device))->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('plugin refresh requires authentication with 401', function (): void {
    $recipe = Plugin::factory()->create(['plugin_type' => 'recipe']);

    $this->postJson(route('api.plugin_settings.refreshes.store', $recipe->uuid))->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('plugin refresh rejects plugins that are not recipes with 422', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create(['user_id' => $user->id, 'plugin_type' => 'image_webhook']);

    Sanctum::actingAs($user);

    $this->postJson(route('api.plugin_settings.refreshes.store', $plugin->uuid))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only recipes can be refreshed.');

    Queue::assertNothingPushed();
});
