<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('GET /api/me returns name and email for authenticated user', function (): void {
    $user = User::factory()->create(['name' => 'Bluey', 'email' => 'bluey@example.com']);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/me');

    $response->assertOk();
    $response->assertJson(['data' => ['name' => 'Bluey', 'email' => 'bluey@example.com']]);
});

it('GET /api/me returns 401 when unauthenticated', function (): void {
    $this->getJson('/api/me')->assertUnauthorized();
});

it('GET /api/plugin_settings returns all user plugins with null plugin_id', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Alpha', 'trmnlp_id' => 'uuid-1']);
    Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Beta', 'trmnlp_id' => 'uuid-2']);

    $otherUser = User::factory()->create();
    Plugin::factory()->create(['user_id' => $otherUser->id, 'name' => 'Other']);

    $response = $this->getJson('/api/plugin_settings');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2);
    expect($data[0])->toMatchArray(['id' => 'uuid-1', 'name' => 'Alpha', 'plugin_id' => null]);
    expect($data[1])->toMatchArray(['id' => 'uuid-2', 'name' => 'Beta', 'plugin_id' => null]);
});

it('GET /api/plugin_settings returns 401 when unauthenticated', function (): void {
    $this->getJson('/api/plugin_settings')->assertUnauthorized();
});

it('POST /api/plugin_settings creates a blank plugin and returns its trmnlp_id', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/plugin_settings', [
        'name' => 'New TRMNLP Plugin',
        'plugin_id' => 37,
    ]);

    $response->assertOk();
    $id = $response->json('data.id');
    expect($id)->not->toBeNull();

    $plugin = Plugin::where('trmnlp_id', $id)->where('user_id', $user->id)->first();
    expect($plugin)->not->toBeNull();
});

it('POST /api/plugin_settings returns 401 when unauthenticated', function (): void {
    $this->postJson('/api/plugin_settings')->assertUnauthorized();
});

it('DELETE /api/plugin_settings/{trmnlp_id} deletes the plugin and returns 204', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $plugin = Plugin::factory()->create(['user_id' => $user->id, 'trmnlp_id' => 'uuid-del']);

    $response = $this->deleteJson('/api/plugin_settings/uuid-del');

    $response->assertNoContent();
    expect(Plugin::find($plugin->id))->toBeNull();
});

it('DELETE /api/plugin_settings/{trmnlp_id} returns 404 for unknown id', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->deleteJson('/api/plugin_settings/nonexistent-uuid')->assertNotFound();
});

it('DELETE /api/plugin_settings/{trmnlp_id} returns 401 when unauthenticated', function (): void {
    $this->deleteJson('/api/plugin_settings/any-uuid')->assertUnauthorized();
});
