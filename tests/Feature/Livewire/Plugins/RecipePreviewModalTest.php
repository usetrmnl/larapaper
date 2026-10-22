<?php

declare(strict_types=1);

use App\Enums\ScaleLevel;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Models\User;
use Livewire\Livewire;

test('preview defaults to the first registered device model', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $customDeviceModel = DeviceModel::factory()->create([
        'kind' => null,
        'label' => 'Seeed E1001 Monochrome (2bit)',
        'width' => 800,
        'height' => 480,
    ]);

    Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => $customDeviceModel->id,
    ]);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'markup_language' => 'liquid',
        'render_markup' => '<div class="title">Preview</div>',
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertSet('preview_device_model_id', $customDeviceModel->id)
        ->assertSee('Your Devices')
        ->assertSee('Seeed E1001 Monochrome (2bit)');
});

test('preview device selector does not duplicate user device models in other groups', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $trmnlDeviceModel = DeviceModel::factory()->create([
        'kind' => 'trmnl',
        'label' => 'TRMNL OG (2-bit)',
    ]);

    Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => $trmnlDeviceModel->id,
    ]);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'markup_language' => 'liquid',
        'render_markup' => '<div class="title">Preview</div>',
    ]);

    $groups = Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->instance()
        ->getDeviceModels();

    $allModelIds = collect($groups)
        ->flatMap(fn (array $group) => $group['models'])
        ->pluck('id');

    expect($allModelIds->duplicates()->isEmpty())->toBeTrue()
        ->and($groups['user_devices']['models']->pluck('id'))->toContain($trmnlDeviceModel->id)
        ->and($groups['trmnl']['models']->pluck('id'))->not->toContain($trmnlDeviceModel->id);
});

test('preview falls back to trmnl og when user has no device model', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $ogModel = DeviceModel::query()->firstWhere('name', 'og_plus')
        ?? DeviceModel::factory()->create([
            'name' => 'og_plus',
            'kind' => 'trmnl',
            'label' => 'TRMNL OG (2-bit)',
        ]);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'markup_language' => 'liquid',
        'render_markup' => '<div class="title">Preview</div>',
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertSet('preview_device_model_id', $ogModel->id);
});

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
        ->assertDispatched('preview-updated', fn (string $name, array $params): bool => $name === 'preview-updated'
            && $params['screenWidth'] === 1872
            && $params['screenHeight'] === 1404
            && is_string($params['preview'] ?? null)
            && $params['preview'] !== '');
});

test('render preview uses the scale of the user\'s device with the selected model', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $deviceModel = DeviceModel::factory()->create([
        'kind' => 'trmnl',
        'width' => 1872,
        'height' => 1404,
    ]);

    Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => $deviceModel->id,
        'scale_level' => ScaleLevel::REGULAR,
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
        ->assertDispatched('preview-updated', fn (string $name, array $params): bool => str_contains($params['preview'], 'screen--scale-regular'));
});

test('render preview uses the device model\'s scale when the user has no device with it', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $deviceModel = DeviceModel::factory()->create([
        'kind' => 'trmnl',
        'width' => 1872,
        'height' => 1404,
    ]);

    Device::factory()->create([
        'user_id' => User::factory(),
        'device_model_id' => $deviceModel->id,
        'scale_level' => ScaleLevel::REGULAR,
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
        ->assertDispatched('preview-updated', fn (string $name, array $params): bool => str_contains($params['preview'], 'screen--scale-xxlarge'));
});

test('add to playlist modal gates layout block with alpine using wire state', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'markup_language' => 'liquid',
        'render_markup' => '<div class="title">Preview</div>',
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertSee('($wire.checked_devices ?? []).length > 0', false)
        ->assertSee('($wire.checked_devices ?? []).some((id) =>', false);
});
