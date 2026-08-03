<?php

use App\Models\Device;
use App\Models\User;
use Carbon\Carbon;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

$originalPhpTimezone = date_default_timezone_get();

afterEach(function () use ($originalPhpTimezone): void {
    Carbon::setTestNow();
    date_default_timezone_set($originalPhpTimezone);
});

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
    expect($device->name)->toBe($deviceData['name']);
    expect($device->mac_address)->toBe($deviceData['mac_address']);
    expect($device->api_key)->toBe($deviceData['api_key']);
    expect($device->default_refresh_interval)->toBe($deviceData['default_refresh_interval']);
    expect($device->friendly_id)->toBe($deviceData['friendly_id']);
    expect($device->user_id)->toBe($user->id);
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

test('pause modal keeps the fixed presets and offers a specific date and time', function (): void {
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->assertSee(['30 min', '60 min', '120 min', '240 min', '480 min'])
        ->assertSee('Specific date and time')
        ->assertSeeHtml('wire:model.live="pause_duration"')
        ->assertSeeHtml('value="specific_date"')
        ->set('pause_duration', 'specific_date')
        ->assertSeeHtml('type="datetime-local"')
        ->assertSeeHtml('wire:model="pause_until"')
        ->assertSee('Europe/Amsterdam');
});

test('active pause deadline is shown in the owners timezone with a complete local date', function (): void {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    Carbon::setTestNow('2026-01-01 00:00:00 UTC');
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    Device::factory()->create([
        'user_id' => $user->id,
        'pause_until' => Carbon::parse('2026-01-15 11:30:00 UTC'),
    ]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->assertSee('Device paused until: 2026-01-15 12:30')
        ->assertDontSee('Device paused until: 2026-01-15 11:30');
});

test('pause form state and errors are cleared when reopening or cancelling a pause modal', function (): void {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $component = Livewire::test('devices.manage')
        ->assertSeeHtml('wire:click="resetPauseForm"');

    expect(mb_substr_count($component->html(), 'wire:click="resetPauseForm"'))->toBe(2);

    $component
        ->set('pause_duration', 'specific_date')
        ->set('pause_until', null)
        ->call('pauseDevice', $device->id)
        ->assertHasErrors(['pause_until'])
        ->call('resetPauseForm')
        ->assertSet('pause_duration', null)
        ->assertSet('pause_until', null)
        ->assertHasNoErrors();
});

test('user can pause a device using each fixed preset', function (int $minutes): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);
    Carbon::setTestNow('2026-01-15 10:00:00');

    Livewire::test('devices.manage')
        ->set('pause_duration', (string) $minutes)
        ->call('pauseDevice', $device->id)
        ->assertHasNoErrors()
        ->assertSet('pause_duration', null)
        ->assertSet('pause_until', null);

    expect($device->fresh()->pause_until->equalTo(now()->addMinutes($minutes)))->toBeTrue();

    Carbon::setTestNow();
})->with([
    '30 minutes' => 30,
    '60 minutes' => 60,
    '120 minutes' => 120,
    '240 minutes' => 240,
    '480 minutes' => 480,
]);

test('specific pause dates are persisted as the intended user-timezone instant', function (string $localTime, string $expectedUtc): void {
    config(['app.timezone' => 'UTC']);
    Carbon::setTestNow('2026-01-01 00:00:00 UTC');
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->set('pause_duration', 'specific_date')
        ->set('pause_until', $localTime)
        ->call('pauseDevice', $device->id)
        ->assertHasNoErrors();

    expect($device->fresh()->pause_until->utc()->format('Y-m-d H:i:s'))->toBe($expectedUtc);

    Carbon::setTestNow();
})->with([
    'Amsterdam winter time' => ['2026-01-15T12:30', '2026-01-15 11:30:00'],
    'Amsterdam summer time' => ['2026-07-15T12:30', '2026-07-15 10:30:00'],
]);

test('specific pause dates use the app timezone when the user has none', function (): void {
    $originalTimezone = date_default_timezone_get();
    config(['app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');
    Carbon::setTestNow('2026-01-01 00:00:00 UTC');
    $user = User::factory()->create(['timezone' => null]);
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->set('pause_duration', 'specific_date')
        ->set('pause_until', '2026-01-15T12:30')
        ->call('pauseDevice', $device->id)
        ->assertHasNoErrors();

    expect($device->fresh()->pause_until->utc()->format('Y-m-d H:i:s'))->toBe('2026-01-15 17:30:00');

    Carbon::setTestNow();
    date_default_timezone_set($originalTimezone);
});

test('invalid specific pause values leave the device unchanged', function (?string $pauseUntil): void {
    config(['app.timezone' => 'UTC']);
    Carbon::setTestNow('2026-01-01 00:00:00 UTC');
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $sentinel = Carbon::parse('2026-02-01 00:00:00 UTC');
    $device = Device::factory()->create(['user_id' => $user->id, 'pause_until' => $sentinel]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->set('pause_duration', 'specific_date')
        ->set('pause_until', $pauseUntil)
        ->call('pauseDevice', $device->id)
        ->assertHasErrors(['pause_until']);

    expect($device->fresh()->pause_until->equalTo($sentinel))->toBeTrue();

    Carbon::setTestNow();
})->with([
    'missing date' => null,
    'malformed date' => '2026-1-15T12:30',
    'nonexistent Amsterdam spring-forward time' => '2026-03-29T02:30',
]);

test('an invalid stored user timezone falls back to the app timezone for active pauses and custom inputs', function (): void {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    Carbon::setTestNow('2026-01-01 00:00:00 UTC');
    $user = User::factory()->create(['timezone' => 'Not/AZone']);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'pause_until' => Carbon::parse('2026-01-15 12:30:00 UTC'),
    ]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->assertSee('Device paused until: 2026-01-15 12:30')
        ->set('pause_duration', 'specific_date')
        ->assertSee('Times are interpreted in UTC.')
        ->set('pause_until', '2026-01-16T14:30')
        ->call('pauseDevice', $device->id)
        ->assertHasNoErrors();

    expect($device->fresh()->pause_until->utc()->format('Y-m-d H:i:s'))->toBe('2026-01-16 14:30:00');
});

test('past and equal specific pause instants leave the device unchanged', function (string $pauseUntil): void {
    config(['app.timezone' => 'UTC']);
    Carbon::setTestNow('2026-01-15 12:30:00 UTC');
    $user = User::factory()->create(['timezone' => 'UTC']);
    $sentinel = Carbon::parse('2026-02-01 00:00:00 UTC');
    $device = Device::factory()->create(['user_id' => $user->id, 'pause_until' => $sentinel]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->set('pause_duration', 'specific_date')
        ->set('pause_until', $pauseUntil)
        ->call('pauseDevice', $device->id)
        ->assertHasErrors(['pause_until']);

    expect($device->fresh()->pause_until->equalTo($sentinel))->toBeTrue();

    Carbon::setTestNow();
})->with([
    'past' => '2026-01-15T12:29',
    'equal to now' => '2026-01-15T12:30',
]);

test('unsupported pause selector leaves the device unchanged', function (): void {
    $user = User::factory()->create();
    $sentinel = Carbon::parse('2026-02-01 00:00:00 UTC');
    $device = Device::factory()->create(['user_id' => $user->id, 'pause_until' => $sentinel]);
    $this->actingAs($user);

    Livewire::test('devices.manage')
        ->set('pause_duration', '999')
        ->call('pauseDevice', $device->id)
        ->assertHasErrors(['pause_duration']);

    expect($device->fresh()->pause_until->equalTo($sentinel))->toBeTrue();
});

test('user cannot pause another users device', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $sentinel = Carbon::parse('2026-02-01 00:00:00 UTC');
    $device = Device::factory()->create(['user_id' => $otherUser->id, 'pause_until' => $sentinel]);
    $this->actingAs($user);

    expect(fn () => Livewire::test('devices.manage')
        ->set('pause_duration', '30')
        ->call('pauseDevice', $device->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect($device->fresh()->pause_until->equalTo($sentinel))->toBeTrue();
});
