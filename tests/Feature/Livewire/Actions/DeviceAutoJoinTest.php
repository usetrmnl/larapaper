<?php

declare(strict_types=1);

use App\Livewire\Actions\DeviceAutoJoin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('device auto join component can be rendered', function (): void {
    $user = User::factory()->create(['assign_new_devices' => false]);

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertSee('Permit Auto-Join')
        ->assertSet('deviceAutojoin', false);
});

test('device auto join reflects global state: on when any user has it enabled', function (): void {
    $userA = User::factory()->create(['assign_new_devices' => true]);
    $userB = User::factory()->create(['assign_new_devices' => false]);

    Livewire::actingAs($userB)
        ->test(DeviceAutoJoin::class)
        ->assertSet('deviceAutojoin', true);
});

test('device auto join reflects global state: off when no user has it enabled', function (): void {
    $userA = User::factory()->create(['assign_new_devices' => false]);
    $userB = User::factory()->create(['assign_new_devices' => false]);

    Livewire::actingAs($userB)
        ->test(DeviceAutoJoin::class)
        ->assertSet('deviceAutojoin', false);
});

test('device auto join component is visible to all confirmed users', function (): void {
    $firstUser = User::factory()->create(['id' => 1, 'assign_new_devices' => false]);
    $otherUser = User::factory()->create(['id' => 2, 'assign_new_devices' => false]);

    Livewire::actingAs($firstUser)
        ->test(DeviceAutoJoin::class)
        ->assertSee('Permit Auto-Join');

    Livewire::actingAs($otherUser)
        ->test(DeviceAutoJoin::class)
        ->assertSee('Permit Auto-Join');
});

test('turning on sets current user assign_new_devices', function (): void {
    $user = User::factory()->create(['assign_new_devices' => false]);

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', true)
        ->assertSet('deviceAutojoin', true);

    expect($user->fresh()->assign_new_devices)->toBeTrue();
});

test('turning off clears assign_new_devices for all users', function (): void {
    $userA = User::factory()->create(['assign_new_devices' => true]);
    $userB = User::factory()->create(['assign_new_devices' => true]);

    Livewire::actingAs($userA)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', false)
        ->assertSet('deviceAutojoin', false);

    expect($userA->fresh()->assign_new_devices)->toBeFalse();
    expect($userB->fresh()->assign_new_devices)->toBeFalse();
});

test('device auto join component renders correct view', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertViewIs('livewire.actions.device-auto-join');
});

test('device auto join component handles multiple updates correctly', function (): void {
    $user = User::factory()->create(['assign_new_devices' => false]);

    $component = Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', true);

    expect($user->fresh()->assign_new_devices)->toBeTrue();

    $component->set('deviceAutojoin', false);

    expect($user->fresh()->assign_new_devices)->toBeFalse();
});
