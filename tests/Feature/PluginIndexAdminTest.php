<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;

it('owner can toggle plugin sharing', function (): void {
    User::factory()->confirmed()->create(); // consume id=1
    $user = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $user->id, 'is_shared' => false, 'plugin_type' => 'recipe']);

    $this->actingAs($user);
    Livewire::test('plugins.index')
        ->call('toggleShared', $plugin->id)
        ->assertHasNoErrors();

    expect($plugin->fresh()->is_shared)->toBeTrue();
});

it('non-owner cannot toggle plugin sharing', function (): void {
    User::factory()->confirmed()->create(); // consume id=1
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'is_shared' => false, 'plugin_type' => 'recipe']);

    $this->actingAs($other);
    Livewire::test('plugins.index')
        ->call('toggleShared', $plugin->id)
        ->assertForbidden();
});

it('user can copy a shared plugin', function (): void {
    User::factory()->confirmed()->create(); // consume id=1
    $owner = User::factory()->confirmed()->create();
    $copier = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $owner->id,
        'is_shared' => true,
        'plugin_type' => 'recipe',
        'name' => 'Shared Plugin',
    ]);

    $this->actingAs($copier);
    Livewire::test('plugins.index')
        ->call('copyPlugin', $plugin->id)
        ->assertHasNoErrors();

    expect(Plugin::where('user_id', $copier->id)->where('name', 'Shared Plugin')->exists())->toBeTrue();
});

it('cannot copy a private plugin', function (): void {
    User::factory()->confirmed()->create(); // consume id=1
    $owner = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'is_shared' => false, 'plugin_type' => 'recipe']);

    $this->actingAs($other);
    Livewire::test('plugins.index')
        ->call('copyPlugin', $plugin->id)
        ->assertForbidden();
});

it('admin can reassign plugin ownership', function (): void {
    User::factory()->confirmed()->create(); // consume id=1
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->confirmed()->create();
    $newOwner = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'plugin_type' => 'recipe']);

    $this->actingAs($admin);
    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('reassignPlugin', $newOwner->id)
        ->assertHasNoErrors();

    expect($plugin->fresh()->user_id)->toBe($newOwner->id);
});
