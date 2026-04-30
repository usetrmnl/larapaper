<?php

declare(strict_types=1);

use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Models\User;
use App\Services\ImageGenerationService;
use Bnussbau\TrmnlPipeline\TrmnlPipeline;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    TrmnlPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
});

test('display alias returns 403 when plugin alias is disabled', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->for($user)->create([
        'alias' => false,
    ]);

    $this->getJson(route('api.display.alias', ['plugin' => $plugin->uuid]))
        ->assertForbidden()
        ->assertJsonPath('message', 'Alias is not active for this plugin');
});

test('display alias returns 404 when device model is unknown', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->for($user)->create([
        'alias' => true,
    ]);

    $this->getJson(route('api.display.alias', ['plugin' => $plugin->uuid]).'?device-model=nonexistent_model_xyz')
        ->assertNotFound()
        ->assertJsonPath('message', "Device model 'nonexistent_model_xyz' not found");
});

test('display alias serves cached png when recipe plugin has matching metadata', function (): void {
    $ogPng = DeviceModel::query()->where('name', 'og_png')->first();
    expect($ogPng)->not->toBeNull();

    $user = User::factory()->create();
    $metadata = ImageGenerationService::buildImageMetadataFromDeviceModel($ogPng);

    $imageUuid = 'cached-alias-test-uuid';
    Storage::disk('public')->put("images/generated/{$imageUuid}.png", 'fake-png-bytes');

    $plugin = Plugin::factory()->for($user)->create([
        'alias' => true,
        'plugin_type' => 'recipe',
        'current_image' => $imageUuid,
        'current_image_metadata' => $metadata,
        'data_strategy' => 'static',
        'data_payload_updated_at' => now(),
        'data_stale_minutes' => 1440,
        'render_markup' => '<div>x</div>',
        'markup_language' => 'html',
    ]);

    $response = $this->get(route('api.display.alias', ['plugin' => $plugin->uuid]));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('image/png');
});
