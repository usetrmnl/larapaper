<?php

use App\Models\Device;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

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
        'default_refresh_interval',
    ]);
});

test('api key and friendly id are auto-generated when left blank', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = Livewire::test('devices.manage')
        ->set('name', 'My TRMNL')
        ->set('mac_address', 'aa:bb:cc:dd:ee:ff')
        ->set('api_key', '')
        ->set('friendly_id', '')
        ->set('default_refresh_interval', 900)
        ->call('createDevice');

    $response->assertHasNoErrors();

    $device = Device::first();
    expect($device->api_key)->not->toBeEmpty();
    expect($device->friendly_id)->not->toBeEmpty();
    // MAC is normalised to uppercase so /api/setup (which upper-cases the header) resolves it.
    expect($device->mac_address)->toBe('AA:BB:CC:DD:EE:FF');
    expect($device->user_id)->toBe($user->id);
});

// Multi-user claim flow: a non-first user pre-registers their own device by MAC,
// and /api/setup returns *their* device's generated key — not the main user's.
test('a second user can claim their own device via setup without auto-join', function (): void {
    $mainUser = User::factory()->create(); // id 1 — would win any auto-join race
    $secondUser = User::factory()->create();

    $this->actingAs($secondUser);
    Livewire::test('devices.manage')
        ->set('name', "Second User's TRMNL")
        ->set('mac_address', '11:22:33:44:55:66')
        ->set('default_refresh_interval', 900)
        ->call('createDevice')
        ->assertHasNoErrors();

    $device = Device::where('mac_address', '11:22:33:44:55:66')->first();
    expect($device->user_id)->toBe($secondUser->id);

    $response = $this->getJson('/api/setup', ['id' => '11:22:33:44:55:66']);

    $response->assertOk()
        ->assertJson([
            'status' => 200,
            'api_key' => $device->api_key,
            'friendly_id' => $device->friendly_id,
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
