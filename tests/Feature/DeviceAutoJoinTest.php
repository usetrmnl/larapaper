<?php
// tests/Feature/DeviceAutoJoinTest.php
declare(strict_types=1);

use App\Actions\Api\ResolveDeviceByApiKey;
use App\Actions\Api\ResolveDeviceByMacAddress;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;

it('auto-join via api key creates device with null user_id', function (): void {
    $user = User::factory()->confirmed()->create(['assign_new_devices' => true]);

    $request = Request::create('/', 'GET', [], [], [], [
        'HTTP_ACCESS-TOKEN' => 'new-token-123',
        'HTTP_ID' => 'AA:BB:CC:DD:EE:FF',
    ]);

    $device = app(ResolveDeviceByApiKey::class)->handle($request, autoAssign: true);

    expect($device)->not->toBeNull();
    expect($device->user_id)->toBeNull();
});

it('auto-join via mac address creates device with null user_id', function (): void {
    $user = User::factory()->confirmed()->create(['assign_new_devices' => true]);

    $request = Request::create('/', 'GET', [], [], [], [
        'HTTP_ID' => '11:22:33:44:55:66',
    ]);

    $device = app(ResolveDeviceByMacAddress::class)->handle($request);

    expect($device)->not->toBeNull();
    expect($device->user_id)->toBeNull();
});
