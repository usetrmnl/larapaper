<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceLog;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Pest\Laravel\actingAs;

test('owner can clear logs for the current device', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $otherDevice = Device::factory()->create(['user_id' => $user->id]);
    $log = DeviceLog::factory()->create([
        'device_id' => $device->id,
        'device_timestamp' => now(),
        'log_entry' => ['message' => 'Battery low on porch'],
    ]);
    $otherLog = DeviceLog::factory()->create([
        'device_id' => $otherDevice->id,
        'device_timestamp' => now(),
        'log_entry' => ['message' => 'Other device still logging'],
    ]);
    actingAs($user);

    Livewire::test('devices.logs', ['device' => $device])
        ->assertSee('Search messages')
        ->assertSee('Clear logs')
        ->assertSee('permanently deleted')
        ->assertSee('Battery low on porch')
        ->call('clearLogs')
        ->assertDontSee('Battery low on porch');

    $this->assertModelMissing($log);
    $this->assertModelExists($otherLog);
});

test('logs page forbids viewing another users device', function (): void {
    $user = User::factory()->create();
    $foreignDevice = Device::factory()->create([
        'user_id' => User::factory()->create()->id,
    ]);
    actingAs($user);

    $this->get(route('devices.logs', $foreignDevice))
        ->assertForbidden();
});

test('logs page forbids clearing another users device logs', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $foreignDevice = Device::factory()->create([
        'user_id' => User::factory()->create()->id,
    ]);
    $foreignLog = DeviceLog::factory()->create([
        'device_id' => $foreignDevice->id,
        'device_timestamp' => now(),
        'log_entry' => ['message' => 'Foreign device log'],
    ]);
    actingAs($user);

    $component = Livewire::test('devices.logs', ['device' => $device])->instance();
    $component->device = $foreignDevice;

    expect(fn () => $component->clearLogs())->toThrow(function (HttpException $exception): bool {
        return $exception->getStatusCode() === 403;
    });

    $this->assertModelExists($foreignLog);
});

test('logs page filters messages from either stored format', function (string $messageKey): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $otherDevice = Device::factory()->create(['user_id' => $user->id]);
    DeviceLog::factory()->create([
        'device_id' => $device->id,
        'device_timestamp' => now(),
        'log_entry' => [$messageKey => 'Sensor timeout on porch'],
    ]);
    DeviceLog::factory()->create([
        'device_id' => $device->id,
        'device_timestamp' => now()->subMinute(),
        'log_entry' => ['message' => 'Unrelated heartbeat'],
    ]);
    DeviceLog::factory()->create([
        'device_id' => $otherDevice->id,
        'device_timestamp' => now(),
        'log_entry' => [$messageKey => 'Sensor timeout on the other device'],
    ]);
    actingAs($user);

    Livewire::test('devices.logs', ['device' => $device])
        ->set('search', 'TIMEOUT')
        ->assertSee('Sensor timeout on porch')
        ->assertDontSee('Unrelated heartbeat')
        ->assertDontSee('Sensor timeout on the other device');
})->with(['message', 'log_message']);

test('logs page shows an empty message when no log matches the search', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    DeviceLog::factory()->create([
        'device_id' => $device->id,
        'device_timestamp' => now(),
        'log_entry' => ['message' => 'Battery low on porch'],
    ]);
    actingAs($user);

    Livewire::test('devices.logs', ['device' => $device])
        ->set('search', 'missing-term')
        ->assertSee('No logs found.')
        ->assertDontSee('Battery low on porch');
});

test('logs search treats percent and underscore as literal characters', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    DeviceLog::factory()->create([
        'device_id' => $device->id,
        'device_timestamp' => now(),
        'log_entry' => ['message' => 'Charge at 100%'],
    ]);
    DeviceLog::factory()->create([
        'device_id' => $device->id,
        'device_timestamp' => now()->subMinute(),
        'log_entry' => ['message' => 'Ordinary status'],
    ]);
    actingAs($user);

    Livewire::test('devices.logs', ['device' => $device])
        ->set('search', '100%')
        ->assertSee('Charge at 100%')
        ->assertDontSee('Ordinary status');
});
