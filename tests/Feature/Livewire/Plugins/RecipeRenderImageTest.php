<?php

declare(strict_types=1);

use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Models\User;
use Bnussbau\TrmnlPipeline\TrmnlPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TrmnlPipeline::fake();
});

test('render image generates image and dispatches event', function (): void {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    $deviceModel = DeviceModel::factory()->create([
        'kind' => 'trmnl',
        'width' => 800,
        'height' => 480,
        'mime_type' => 'image/png',
    ]);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'markup_language' => 'liquid',
        'render_markup' => '<div class="title">Preview</div>',
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->set('preview_device_model_id', $deviceModel->id)
        ->call('renderImage')
        ->assertDispatched('preview-image-updated', function (string $name, array $params): bool {
            return $name === 'preview-image-updated'
                && $params['screenWidth'] === 800
                && $params['screenHeight'] === 480
                && str_contains($params['imageUrl'], 'storage/images/generated/');
        })
        ->assertSet('preview_image_url', function ($url) {
            return str_contains($url, 'storage/images/generated/');
        });
});
