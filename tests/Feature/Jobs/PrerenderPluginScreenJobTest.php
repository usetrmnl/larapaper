<?php

use App\Jobs\PrerenderPluginScreenJob;
use App\Models\Device;
use App\Models\Plugin;
use App\Services\ImageGenerationService;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    EpaperPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function pollingRecipe(array $attributes = []): Plugin
{
    return Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/data',
        'polling_verb' => 'get',
        'data_stale_minutes' => 15,
        'markup_language' => 'liquid',
        'render_markup' => '<div>{{ value }}</div>',
        ...$attributes,
    ]);
}

test('it polls and renders the recipe without changing the device screen', function (): void {
    $this->freezeSecond();
    Http::preventStrayRequests();
    Http::fake(['https://example.com/data' => Http::response(['value' => 42])]);
    $device = Device::factory()->create(['current_screen_image' => null]);
    $plugin = pollingRecipe();

    PrerenderPluginScreenJob::dispatchSync($plugin, $device);

    $plugin->refresh();
    expect($plugin->data_payload)->toBe(['value' => 42])
        ->and($plugin->data_payload_updated_at->equalTo(now()))->toBeTrue()
        ->and($plugin->current_image)->not->toBeNull()
        ->and($plugin->current_image_metadata)->toBe(ImageGenerationService::buildImageMetadataFromDevice($device));
    Storage::disk('public')->assertExists("/images/generated/{$plugin->current_image}.png");
    expect($device->refresh()->current_screen_image)->toBeNull();
});

test('it keeps the image id when the rendered screen is unchanged', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://example.com/data' => Http::response(['value' => 42])]);
    $device = Device::factory()->create();
    $plugin = pollingRecipe();
    PrerenderPluginScreenJob::dispatchSync($plugin, $device);
    $firstImage = $plugin->refresh()->current_image;

    PrerenderPluginScreenJob::dispatchSync($plugin, $device);

    expect($plugin->refresh()->current_image)->toBe($firstImage);
});

test('it keeps the old screen when the polled data skips display', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://example.com/data' => Http::response(['TRMNL_SKIP_DISPLAY' => true])]);
    $plugin = pollingRecipe(['data_payload' => ['TRMNL_SKIP_DISPLAY' => true], 'current_image' => 'old-image']);

    PrerenderPluginScreenJob::dispatchSync($plugin, Device::factory()->create());

    expect($plugin->refresh()->current_image)->toBe('old-image');
});

test('it clears the screen when the markup skips display', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://example.com/data' => Http::response(['value' => 42])]);
    $plugin = pollingRecipe([
        'render_markup' => '<script>window.TRMNL_SKIP_DISPLAY = true;</script>',
        'current_image' => 'old-image',
    ]);

    PrerenderPluginScreenJob::dispatchSync($plugin, Device::factory()->create());

    expect($plugin->refresh()->current_image)->toBeNull();
});

test('it marks the recipe as failed for one refresh interval when rendering fails', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://example.com/data' => Http::response(['value' => 42])]);
    $plugin = pollingRecipe(['render_markup' => '{% if %}', 'data_payload' => ['value' => 42], 'current_image' => 'old-image']);

    PrerenderPluginScreenJob::dispatchSync($plugin, Device::factory()->create());

    expect($plugin->refresh()->current_image)->toBe('old-image')
        ->and(Cache::has(PrerenderPluginScreenJob::failedCacheKey($plugin)))->toBeTrue();
});

test('it clears the failed mark after one refresh interval', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://example.com/data' => Http::response(['value' => 42])]);
    $plugin = pollingRecipe(['render_markup' => '{% if %}']);
    PrerenderPluginScreenJob::dispatchSync($plugin, Device::factory()->create());

    $this->travel(16)->minutes();

    expect(Cache::has(PrerenderPluginScreenJob::failedCacheKey($plugin)))->toBeFalse();
});
