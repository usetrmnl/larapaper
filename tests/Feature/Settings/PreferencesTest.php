<?php

use App\Models\Device;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create(['timezone' => 'UTC']);
    $this->actingAs($this->user);
});

test('changing the timezone saves it immediately', function (): void {
    Livewire::test('pages::settings.preferences')
        ->set('timezone', 'Europe/Vienna')
        ->assertHasNoErrors();

    expect($this->user->fresh()->timezone)->toBe('Europe/Vienna');
});

test('changing the mirrored device saves it immediately', function (): void {
    $device = Device::factory()->create(['user_id' => $this->user->id]);

    Livewire::test('pages::settings.preferences')
        ->set('assign_new_device_id', $device->id)
        ->assertHasNoErrors();

    expect($this->user->fresh()->assign_new_device_id)->toBe($device->id);
});

test('invalid preferences are rejected and not saved', function (): void {
    $otherUsersDevice = Device::factory()->create(['user_id' => User::factory()->create()->id]);

    Livewire::test('pages::settings.preferences')
        ->set('timezone', 'Not/AZone')
        ->assertHasErrors(['timezone']);

    Livewire::test('pages::settings.preferences')
        ->set('assign_new_device_id', $otherUsersDevice->id)
        ->assertHasErrors(['assign_new_device_id']);

    $user = $this->user->fresh();
    expect($user->timezone)->toBe('UTC')
        ->and($user->assign_new_device_id)->toBeNull();
});
