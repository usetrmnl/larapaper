<?php

use App\Enums\FirmwareModel;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Firmware;
use App\Models\Plugin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

test('device management page can be rendered', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/devices');

    $response->assertOk();
});

test('user can create a new device', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $deviceData = [
        'name' => 'Test Device',
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'default_refresh_interval' => 900,
        'friendly_id' => 'test-device-1',
    ];

    $response = Livewire::test('devices.manage')
        ->set('name', $deviceData['name'])
        ->set('mac_address', $deviceData['mac_address'])
        ->set('api_key', $deviceData['api_key'])
        ->set('default_refresh_interval', $deviceData['default_refresh_interval'])
        ->set('friendly_id', $deviceData['friendly_id'])
        ->call('createDevice');

    $response->assertHasNoErrors();

    expect(Device::count())->toBe(1);

    $device = Device::first();
    expect($device->name)->toBe($deviceData['name'])
        ->and($device->mac_address)->toBe($deviceData['mac_address'])
        ->and($device->api_key)->toBe($deviceData['api_key'])
        ->and($device->default_refresh_interval)->toBe($deviceData['default_refresh_interval'])
        ->and($device->friendly_id)->toBe($deviceData['friendly_id'])
        ->and($device->user_id)->toBe($user->id);
});

test('device creation requires required fields', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = Livewire::test('devices.manage')
        ->set('name', '')
        ->set('mac_address', '')
        ->set('api_key', '')
        ->set('default_refresh_interval', '')
        ->set('friendly_id', '')
        ->call('createDevice');

    $response->assertHasErrors([
        'mac_address',
        'api_key',
        'default_refresh_interval',
    ]);
});

test('add device form has generate buttons for api key and friendly id', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->assertSee('Generate API key')
        ->assertSee('Generate friendly ID');
});

test('user can generate a credential when adding a device', function (string $action, string $property, int $length): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Str::createRandomStringsUsing(fn (int $length): string => str_repeat('A', $length));

    try {
        Livewire::test('devices.manage')
            ->call($action)
            ->assertSet($property, str_repeat('A', $length));
    } finally {
        Str::createRandomStringsNormally();
    }
})->with([
    'friendly id' => ['generateFriendlyId', 'friendly_id', 6],
    'api key' => ['generateApiKey', 'api_key', 22],
]);

test('user can toggle proxy cloud for their device', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'proxy_cloud' => false,
    ]);

    $response = Livewire::test('devices.manage')
        ->call('toggleProxyCloud', $device);

    $response->assertHasNoErrors();
    expect($device->fresh()->proxy_cloud)->toBeTrue();

    // Toggle back to false
    $response = Livewire::test('devices.manage')
        ->call('toggleProxyCloud', $device);

    expect($device->fresh()->proxy_cloud)->toBeFalse();
});

test('user cannot toggle proxy cloud for other users devices', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $otherUser = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $otherUser->id,
        'proxy_cloud' => false,
    ]);

    $response = Livewire::test('devices.manage')
        ->call('toggleProxyCloud', $device);

    $response->assertStatus(403);
    expect($device->fresh()->proxy_cloud)->toBeFalse();
});

test('user can pause device with preset duration', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Carbon::setTestNow('2026-08-04 12:00:00');

    Livewire::test('devices.manage')
        ->set('pause_duration', 60)
        ->call('pauseDevice', $device->id)
        ->assertHasNoErrors();

    expect($device->fresh()->pause_until?->equalTo(now()->addMinutes(60)))->toBeTrue();
});

test('user can pause device with custom date and time in user timezone', function (): void {
    $user = User::factory()->create(['timezone' => 'Europe/Berlin']);
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Carbon::setTestNow(Carbon::parse('2026-08-04 10:00:00', 'UTC'));

    Livewire::test('devices.manage')
        ->set('pause_until_date', '2026-08-10')
        ->set('pause_until_time', '14:30')
        ->call('pauseDevice', $device->id)
        ->assertHasNoErrors();

    expect($device->fresh()->pause_until?->utc()->format('Y-m-d H:i'))->toBe('2026-08-10 12:30');
});

test('custom pause rejects datetime more than 30 days in the future', function (): void {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Carbon::setTestNow('2026-08-04 12:00:00');

    Livewire::test('devices.manage')
        ->set('pause_until_date', '2026-09-10')
        ->set('pause_until_time', '14:30')
        ->call('pauseDevice', $device->id)
        ->assertHasErrors(['pause_until_date']);

    expect($device->fresh()->pause_until)->toBeNull();
});

test('custom pause rejects datetime in the past', function (): void {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Carbon::setTestNow('2026-08-04 12:00:00');

    Livewire::test('devices.manage')
        ->set('pause_until_date', '2026-08-04')
        ->set('pause_until_time', '08:00')
        ->call('pauseDevice', $device->id)
        ->assertHasErrors(['pause_until_date']);

    expect($device->fresh()->pause_until)->toBeNull();
});

test('user can end pause early', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'pause_until' => now()->addHour(),
    ]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->call('unpauseDevice', $device->id)
        ->assertHasNoErrors();

    expect($device->fresh()->pause_until)->toBeNull();
});

test('unpause modal shows touch bar instructions for v2 devices', function (): void {
    $user = User::factory()->create();
    $deviceModel = DeviceModel::query()->where('name', 'v2')->firstOrFail();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => $deviceModel->id,
        'pause_until' => now()->addHour(),
    ]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->assertSee('touch bar in the middle')
        ->assertDontSee('physical screen button');
});

test('unpause modal shows screen button instructions for non-v2 devices', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'pause_until' => now()->addHour(),
    ]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->assertSee('physical screen button')
        ->assertDontSee('touch bar in the middle');
});

test('add device modal highlights auto join for the first user', function (): void {
    $user = User::factory()->create(['id' => 1]);

    $this->actingAs($user)
        ->get('/devices')
        ->assertOk()
        ->assertSee('Or add manually')
        ->assertSee('Click the button above to permit auto join for')
        ->assertSee('device playlist to get started');
});

test('add device modal does not show auto join for other users', function (): void {
    User::factory()->create(['id' => 1]);
    $other = User::factory()->create(['id' => 2]);

    $this->actingAs($other)
        ->get('/devices')
        ->assertOk()
        ->assertDontSee('Or add manually')
        ->assertDontSee('Point the device at this server to finish setup');
});

test('device management page warns about devices awaiting activation', function (): void {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'name' => 'Kitchen TRMNL', 'last_refreshed_at' => null]);
    Device::factory()->create(['user_id' => $user->id, 'name' => 'Office TRMNL', 'last_refreshed_at' => now()]);

    $this->actingAs($user);

    $component = Livewire::test('devices.manage')
        ->assertSee('Awaiting activation');

    expect($component->instance()->devicesAwaitingActivation->pluck('name')->all())->toBe(['Kitchen TRMNL']);
});

test('device management page shows no activation warning when all devices are activated', function (): void {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'last_refreshed_at' => now()]);

    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->assertDontSee('Awaiting activation');
});

test('user cannot pause device with another users plugin as pause screen', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $foreignPlugin = Plugin::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->set('pause_duration', 60)
        ->set('pause_plugin_id', $foreignPlugin->id)
        ->call('pauseDevice', $device->id)
        ->assertHasErrors(['pause_plugin_id']);

    expect($device->fresh()->pause_plugin_id)->toBeNull();
});

test('device list shows remaining pause time when a device is paused', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'pause_until' => now()->addHour(),
    ]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->assertSee('Device paused until: '.$device->pause_until->diffForHumans())
        ->assertDontSee('Pause screen generation');
});

test('device list offers pause screen generation when a device is not paused', function (): void {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->assertSee('Pause screen generation')
        ->assertDontSee('Device paused until');
});

test('device list shows firmware, logs, and mirror actions', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'api_key' => 'list-device-key',
    ]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->assertDontSee('Pause Screen Generation')
        ->assertSee('Update Firmware')
        ->assertSee('Show Logs')
        ->assertSee('Mirror URL')
        ->assertSee('End pause')
        ->assertSee(route('devices.logs', $device), false)
        ->assertSee('/mirror/index.html?api_key=list-device-key&amp;mode=mirror', false);
});

test('user can queue a firmware update from the device list', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $firmware = Firmware::factory()->trmnl()->latest()->create([
        'version_tag' => '1.2.3',
    ]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->call('prepareFirmwareUpdate', $device->id)
        ->set('selected_firmware_model', FirmwareModel::Trmnl->value)
        ->set('selected_firmware_id', $firmware->id)
        ->call('updateFirmware', $device->id)
        ->assertHasNoErrors();

    expect($device->fresh()->update_firmware_id)->toBe($firmware->id);
});

test('user cannot queue a firmware update for another users device', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => User::factory()->create()->id]);
    $firmware = Firmware::factory()->trmnl()->latest()->create([
        'version_tag' => '9.9.9',
    ]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->set('selected_firmware_model', FirmwareModel::Trmnl->value)
        ->set('selected_firmware_id', $firmware->id)
        ->call('updateFirmware', $device->id)
        ->assertNotFound();

    expect($device->fresh()->update_firmware_id)->toBeNull();
});
