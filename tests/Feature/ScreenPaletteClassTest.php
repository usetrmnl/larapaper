<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\DevicePalette;
use App\Models\Plugin;
use App\Models\User;

test('plugin screen uses the device palette framework class', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'test-color-4bwry',
        'grays' => 2,
        'framework_class' => 'screen--color-4bwry',
    ]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => DeviceModel::factory()->create()->id,
        'palette_id' => $palette->id,
    ]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'blade',
        'render_markup' => '<div>Hello</div>',
    ]);

    $html = $plugin->render(device: $device);

    expect($html)->toContain('screen--color-4bwry')
        ->not->toContain('screen--color-6a');
});

test('plugin screen uses the device model palette when the device has none', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'test-gray-16',
        'grays' => 16,
        'framework_class' => 'screen--4bit',
    ]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => DeviceModel::factory()->create([
            'palette_id' => $palette->id,
        ])->id,
        'palette_id' => null,
    ]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'blade',
        'render_markup' => '<div>Hello</div>',
    ]);

    $html = $plugin->render(device: $device);

    expect($html)->toContain('screen--4bit');
});

test('plugin screen omits a palette class when the device has no palette', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => DeviceModel::factory()->create([
            'palette_id' => null,
        ])->id,
        'palette_id' => null,
    ]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'blade',
        'render_markup' => '<div>Hello</div>',
    ]);

    $html = $plugin->render(device: $device);

    expect($html)->not->toContain('screen--color-');
});

test('mashup screen uses the device palette framework class', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'test-gray-16',
        'grays' => 16,
        'framework_class' => 'screen--4bit',
    ]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => DeviceModel::factory()->create()->id,
        'palette_id' => $palette->id,
    ]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'blade',
        'render_markup' => '<div>Hello</div>',
    ]);

    $html = $plugin->render('half_horizontal', device: $device);

    expect($html)->toContain('screen--4bit')
        ->and($html)->toContain('mashup');
});
