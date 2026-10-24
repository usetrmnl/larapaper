<?php

declare(strict_types=1);

use App\Livewire\Actions\DeviceAutoJoin;
use App\Models\User;
use Livewire\Livewire;

test('device auto join component can be rendered', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertSee('Auto-Join Permitted')
        ->assertSet('deviceAutojoin', false)
        ->assertSet('isFirstUser', true);
});

test('device auto join component initializes with user settings', function (): void {
    $user = User::factory()->autoJoinEnabled()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertSet('deviceAutojoin', true)
        ->assertSet('isFirstUser', true);
});

test('device auto join component identifies first user correctly', function (): void {
    $firstUser = User::factory()->create(['id' => 1]);
    $otherUser = User::factory()->create(['id' => 2]);

    Livewire::actingAs($firstUser)
        ->test(DeviceAutoJoin::class)
        ->assertSet('isFirstUser', true);

    Livewire::actingAs($otherUser)
        ->test(DeviceAutoJoin::class)
        ->assertSet('isFirstUser', false);
});

test('device auto join component updates user setting when toggled', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', true)
        ->assertSet('deviceAutojoin', true);

    $user->refresh();
    expect($user->hasActiveAutoJoin())->toBeTrue();
});

// Validation test removed - Livewire automatically handles boolean conversion

test('device auto join component handles false value correctly', function (): void {
    $user = User::factory()->autoJoinEnabled()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', false)
        ->assertSet('deviceAutojoin', false);

    $user->refresh();
    expect($user->hasActiveAutoJoin())->toBeFalse();
});

test('device auto join component only updates when deviceAutojoin property changes', function (): void {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class);

    // Set a different property to ensure it doesn't trigger the update
    $component->set('isFirstUser', true);

    $user->refresh();
    expect($user->hasActiveAutoJoin())->toBeFalse();
});

test('device auto join component renders correct view', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertViewIs('livewire.actions.device-auto-join');
});

test('device auto join component works with authenticated user', function (): void {
    $user = User::factory()->autoJoinEnabled()->create();

    $component = Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class);

    expect($component->instance()->deviceAutojoin)->toBeTrue()
        ->and($component->instance()->isFirstUser)->toBe($user->id === 1);
});

test('device auto join is persisted in the layout so wire navigate does not remount it', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('x-persist="device-auto-join-desktop"', false)
        ->assertSee('x-persist="device-auto-join-mobile"', false)
        ->assertSee('Auto-Join Permitted');
});

test('device auto join component handles multiple updates correctly', function (): void {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', true);

    $user->refresh();
    expect($user->hasActiveAutoJoin())->toBeTrue();

    $component->set('deviceAutojoin', false);

    $user->refresh();
    expect($user->hasActiveAutoJoin())->toBeFalse();
});

test('device auto join sibling instances stay in sync', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', true)
        ->assertDispatched('device-auto-join-changed');

    Livewire::actingAs($user->refresh())
        ->test(DeviceAutoJoin::class)
        ->dispatch('device-auto-join-changed')
        ->assertSet('deviceAutojoin', true)
        ->assertSet('autoJoinExpiresAt', $user->assign_new_devices_until->timestamp);
});

test('enabling auto join opens a window of fifteen minutes', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', true)
        ->assertSet('autoJoinExpiresAt', now()->addMinutes(15)->timestamp)
        ->assertSet('serverNow', now()->timestamp);

    expect($user->refresh()->assign_new_devices_until->timestamp)->toBe(now()->addMinutes(15)->timestamp);
});

test('disabling auto join closes the window', function (): void {
    $user = User::factory()->autoJoinEnabled()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', false)
        ->assertSet('autoJoinExpiresAt', null);

    expect($user->refresh()->assign_new_devices_until)->toBeNull();
});

test('device auto join component mounts as disabled once the window has expired', function (): void {
    $user = User::factory()->create(['assign_new_devices_until' => now()->subSecond()]);

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertSet('deviceAutojoin', false)
        ->assertSet('autoJoinExpiresAt', null);
});

test('device auto join switches off when synced after the window expires', function (): void {
    $user = User::factory()->autoJoinEnabled()->create();

    $component = Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertSet('deviceAutojoin', true);

    $this->travel(16)->minutes();

    $component->call('syncState')
        ->assertSet('deviceAutojoin', false)
        ->assertSet('autoJoinExpiresAt', null);
});

test('device auto join exposes the expiry and server time for the countdown on mount', function (): void {
    $this->freezeTime();
    $user = User::factory()->autoJoinEnabled()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertSet('autoJoinExpiresAt', now()->addMinutes(15)->timestamp)
        ->assertSet('serverNow', now()->timestamp);
});
