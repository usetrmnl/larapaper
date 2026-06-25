<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\User;

it('owner can view their device', function (): void {
    $user = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    expect($user->can('view', $device))->toBeTrue();
});

it('other user cannot view a private device', function (): void {
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => $owner->id]);

    expect($other->can('view', $device))->toBeFalse();
});

it('any user can view an unowned device', function (): void {
    $user = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => null]);

    expect($user->can('view', $device))->toBeTrue();
});

it('admin can view any device', function (): void {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => $owner->id]);

    expect($admin->can('view', $device))->toBeTrue();
});

it('owner can update their device', function (): void {
    $user = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    expect($user->can('update', $device))->toBeTrue();
});

it('non-owner cannot update another user device', function (): void {
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => $owner->id]);

    expect($other->can('update', $device))->toBeFalse();
});

it('regular user can update an unowned device', function (): void {
    User::factory()->create(); // consume id=1 (auto-admin slot)
    $user = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => null]);

    expect($user->can('update', $device))->toBeTrue();
});

it('admin can update unowned device', function (): void {
    $admin = User::factory()->admin()->create();
    $device = Device::factory()->create(['user_id' => null]);

    expect($admin->can('update', $device))->toBeTrue();
});

it('only admin can reassign a device', function (): void {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    expect($admin->can('reassign', $device))->toBeTrue();
    expect($user->can('reassign', $device))->toBeFalse();
});

it('api returns 403 when non-owner requests device status', function (): void {
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $device = Device::factory()->create(['user_id' => $owner->id]);
    $token = $other->createToken('test', ['update-screen'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/display/status?device_id={$device->id}")
        ->assertStatus(403);
});
