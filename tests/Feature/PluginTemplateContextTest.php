<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Models\User;
use Carbon\Carbon;

dataset('template markups', [
    'liquid' => [
        'liquid',
        '{{ trmnl.device.model }}|{{ trmnl.device.bit_depth }}|{{ trmnl.device.refresh_interval_seconds }}|{{ trmnl.device.sleep_mode_enabled }}|{{ trmnl.device.sleep_start_time }}|{{ trmnl.device.sleep_end_time }}|{{ trmnl.plugin_settings.refresh_interval_minutes }}|{{ trmnl.plugin_settings.data_fetched_utc }}',
    ],
    'blade' => [
        'blade',
        "{{ \$trmnl['device']['model'] }}|{{ \$trmnl['device']['bit_depth'] }}|{{ \$trmnl['device']['refresh_interval_seconds'] }}|{{ \$trmnl['device']['sleep_mode_enabled'] === true ? 'true' : (\$trmnl['device']['sleep_mode_enabled'] === false ? 'false' : '') }}|{{ \$trmnl['device']['sleep_start_time'] }}|{{ \$trmnl['device']['sleep_end_time'] }}|{{ \$trmnl['plugin_settings']['refresh_interval_minutes'] }}|{{ \$trmnl['plugin_settings']['data_fetched_utc'] }}",
    ],
]);

test('plugin render includes the assigned device and last poll time', function (string $markupLanguage, string $markup): void {
    $user = User::factory()->create();
    $deviceModel = DeviceModel::query()->where('name', 'v2')->firstOrFail();
    $deviceModel->update(['bit_depth' => 4]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => $deviceModel->id,
        'default_refresh_interval' => 900,
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '23:00',
        'sleep_mode_to' => '07:00',
    ]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => $markupLanguage,
        'render_markup' => $markup,
        'data_stale_minutes' => 5,
        'data_payload_updated_at' => Carbon::createFromTimestamp(1_790_695_085, 'UTC'),
    ]);

    $output = $plugin->render(standalone: false, device: $device);

    expect($output)->toBe('v2|4|900|true|1380|420|5|1790695085');
})->with('template markups');

test('plugin render leaves device fields empty when no device is assigned', function (string $markupLanguage, string $markup): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => $markupLanguage,
        'render_markup' => $markup,
        'data_stale_minutes' => 5,
        'data_payload_updated_at' => Carbon::createFromTimestamp(1_790_695_085, 'UTC'),
    ]);

    $output = $plugin->render(standalone: false);

    expect($output)->toBe('||||||5|1790695085');
})->with('template markups');

test('plugin render leaves the last poll time empty when data has never been fetched', function (string $markupLanguage, string $markup): void {
    $user = User::factory()->create();
    $deviceModel = DeviceModel::query()->where('name', 'v2')->firstOrFail();
    $deviceModel->update(['bit_depth' => 4]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => $deviceModel->id,
        'default_refresh_interval' => 900,
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '23:00',
        'sleep_mode_to' => '07:00',
    ]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => $markupLanguage,
        'render_markup' => $markup,
        'data_stale_minutes' => 5,
        'data_payload_updated_at' => null,
    ]);

    $output = $plugin->render(standalone: false, device: $device);

    expect($output)->toBe('v2|4|900|true|1380|420|5|');
})->with('template markups');

test('plugin render leaves sleep times empty when the device has none', function (string $markupLanguage, string $markup): void {
    $user = User::factory()->create();
    $deviceModel = DeviceModel::query()->where('name', 'v2')->firstOrFail();
    $deviceModel->update(['bit_depth' => 4]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => $deviceModel->id,
        'default_refresh_interval' => 900,
        'sleep_mode_enabled' => false,
        'sleep_mode_from' => null,
        'sleep_mode_to' => null,
    ]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => $markupLanguage,
        'render_markup' => $markup,
        'data_stale_minutes' => 5,
        'data_payload_updated_at' => Carbon::createFromTimestamp(1_790_695_085, 'UTC'),
    ]);

    $output = $plugin->render(standalone: false, device: $device);

    expect($output)->toBe('v2|4|900|false|||5|1790695085');
})->with('template markups');
