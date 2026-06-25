<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;

it('owner can view their private plugin', function (): void {
    $user = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $user->id, 'is_shared' => false]);

    expect($user->can('view', $plugin))->toBeTrue();
});

it('non-owner cannot view private plugin', function (): void {
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'is_shared' => false]);

    expect($other->can('view', $plugin))->toBeFalse();
});

it('any confirmed user can view shared plugin', function (): void {
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'is_shared' => true]);

    expect($other->can('view', $plugin))->toBeTrue();
});

it('admin can view any plugin', function (): void {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'is_shared' => false]);

    expect($admin->can('view', $plugin))->toBeTrue();
});

it('owner can update their plugin', function (): void {
    $user = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $user->id]);

    expect($user->can('update', $plugin))->toBeTrue();
});

it('non-owner cannot update plugin', function (): void {
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id]);

    expect($other->can('update', $plugin))->toBeFalse();
});

it('admin can update any plugin', function (): void {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id]);

    expect($admin->can('update', $plugin))->toBeTrue();
});

it('only admin can reassign a plugin', function (): void {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $user->id]);

    expect($admin->can('reassign', $plugin))->toBeTrue();
    expect($user->can('reassign', $plugin))->toBeFalse();
});

it('any confirmed user can copy a shared plugin', function (): void {
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'is_shared' => true]);

    expect($other->can('copy', $plugin))->toBeTrue();
});

it('cannot copy a private plugin', function (): void {
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'is_shared' => false]);

    expect($other->can('copy', $plugin))->toBeFalse();
});

it('api plugin settings index only returns own plugins', function (): void {
    $user = User::factory()->confirmed()->create();
    $otherUser = User::factory()->confirmed()->create();
    Plugin::factory()->create(['user_id' => $user->id, 'trmnlp_id' => 'own-plugin']);
    Plugin::factory()->create(['user_id' => $otherUser->id, 'trmnlp_id' => 'other-plugin']);
    $token = $user->createToken('test', ['update-screen'])->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/plugin_settings');

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain('own-plugin');
    expect($ids)->not->toContain('other-plugin');
});

it('api plugin destroy denies non-owner', function (): void {
    User::factory()->confirmed()->create(); // consume id=1 (auto-admin)
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'trmnlp_id' => 'target-plugin']);
    $token = $other->createToken('test', ['update-screen'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/plugin_settings/target-plugin')
        ->assertStatus(403);
});
