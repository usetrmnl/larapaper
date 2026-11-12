<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('GET /api/me returns the user in the shape of TRMNL\'s Account API', function (): void {
    $this->travelTo('2026-07-01 12:00:00');
    $user = User::factory()->create(['name' => 'Bluey Heeler', 'email' => 'bluey@example.com', 'timezone' => 'America/New_York']);
    Sanctum::actingAs($user);

    $this->getJson('/api/me')->assertOk()->assertExactJson(['data' => [
        'id' => $user->id,
        'name' => 'Bluey Heeler',
        'email' => 'bluey@example.com',
        'first_name' => 'Bluey',
        'last_name' => 'Heeler',
        'locale' => 'en',
        'time_zone' => 'America/New_York',
        'time_zone_iana' => 'America/New_York',
        'utc_offset' => -14400,
    ]]);
});

it('GET /api/me falls back to the app time zone and an empty last name', function (): void {
    config(['app.timezone' => 'UTC']);
    Sanctum::actingAs(User::factory()->create(['name' => 'Bingo', 'timezone' => null]));

    $this->getJson('/api/me')->assertOk()->assertJson(['data' => [
        'first_name' => 'Bingo',
        'last_name' => '',
        'time_zone' => 'UTC',
        'time_zone_iana' => 'UTC',
        'utc_offset' => 0,
    ]]);
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
    expect($data)->toHaveCount(2)
        ->sequence(fn ($e) => $e->toMatchArray(['id' => 'uuid-1', 'name' => 'Alpha', 'plugin_id' => null]), fn ($e) => $e->toMatchArray(['id' => 'uuid-2', 'name' => 'Beta', 'plugin_id' => null]));
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
