<?php

declare(strict_types=1);

use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('render preview dispatches screen dimensions from selected device model', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $deviceModel = DeviceModel::factory()->create([
        'kind' => 'trmnl',
        'width' => 1872,
        'height' => 1404,
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
        ->call('renderPreview', 'full')
        ->assertDispatched('preview-updated', function (string $name, array $params): bool {
            return $name === 'preview-updated'
                && $params['screenWidth'] === 1872
                && $params['screenHeight'] === 1404
                && is_string($params['preview'] ?? null)
                && $params['preview'] !== '';
        });
});
