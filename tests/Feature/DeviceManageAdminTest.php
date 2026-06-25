<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\User;

// Burn id=1 so no test-created user is treated as admin by the id===1 shortcut
beforeEach(function (): void {
    User::factory()->create(); // id=1 burn user
});

it('regular user sees their own devices and unowned devices', function (): void {
    $user = User::factory()->confirmed()->create();
    $ownDevice = Device::factory()->create(['user_id' => $user->id]);
    $unownedDevice = Device::factory()->create(['user_id' => null]);
    $otherDevice = Device::factory()->create(['user_id' => User::factory()->confirmed()->create()->id]);

    $this->actingAs($user);
    Livewire::test('devices.manage')
        ->assertSee($ownDevice->name)
        ->assertSee($unownedDevice->name)
        ->assertDontSee($otherDevice->name);
});

it('admin can toggle show-all to see all devices', function (): void {
    $admin = User::factory()->admin()->create();
    $otherUser = User::factory()->confirmed()->create();
    $otherDevice = Device::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($admin);
    Livewire::test('devices.manage')
        ->set('showAllDevices', true)
        ->assertSee($otherDevice->name);
});

it('admin can reassign a device', function (): void {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => null]);

    $this->actingAs($admin);
    Livewire::test('devices.configure', ['device' => $device])
        ->call('reassignDevice', $user->id)
        ->assertHasNoErrors();

    expect($device->fresh()->user_id)->toBe($user->id);
});

it('regular user cannot reassign a device', function (): void {
    $user = User::factory()->confirmed()->create();
    $otherUser = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);
    Livewire::test('devices.configure', ['device' => $device])
        ->call('reassignDevice', $otherUser->id)
        ->assertForbidden();
});
