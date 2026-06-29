<?php
// tests/Feature/PluginVisibilityTest.php
declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;

it('scopeVisibleTo returns own plugins', function (): void {
    $user = User::factory()->confirmed()->create();
    $plugin = Plugin::factory()->create(['user_id' => $user->id, 'is_shared' => false]);

    $results = Plugin::visibleTo($user)->pluck('id');

    expect($results)->toContain($plugin->id);
});

it('scopeVisibleTo returns shared plugins from other users', function (): void {
    $owner = User::factory()->confirmed()->create();
    $viewer = User::factory()->confirmed()->create();
    $sharedPlugin = Plugin::factory()->create(['user_id' => $owner->id, 'is_shared' => true]);

    $results = Plugin::visibleTo($viewer)->pluck('id');

    expect($results)->toContain($sharedPlugin->id);
});

it('scopeVisibleTo hides non-shared plugins from other users', function (): void {
    $owner = User::factory()->confirmed()->create();
    $viewer = User::factory()->confirmed()->create();
    $privatePlugin = Plugin::factory()->create(['user_id' => $owner->id, 'is_shared' => false]);

    $results = Plugin::visibleTo($viewer)->pluck('id');

    expect($results)->not->toContain($privatePlugin->id);
});

it('scopeVisibleTo returns all plugins for admins', function (): void {
    $admin = User::factory()->admin()->create();
    $otherUser = User::factory()->confirmed()->create();
    $privatePlugin = Plugin::factory()->create(['user_id' => $otherUser->id, 'is_shared' => false]);

    $results = Plugin::visibleTo($admin)->pluck('id');

    expect($results)->toContain($privatePlugin->id);
});
