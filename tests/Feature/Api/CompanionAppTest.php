<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use MessagePack\MessagePack;

it('GET /api/companion/me returns required user fields for authenticated user', function (): void {
    $user = User::factory()->create([
        'name' => 'Example User',
        'email' => 'user@example.test',
        'timezone' => 'UTC',
    ]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/companion/me');

    $response->assertOk();
    $response->assertJsonPath('data.name', 'Example User');
    $response->assertJsonPath('data.email', 'user@example.test');
    $response->assertJsonPath('data.first_name', 'Example');
    $response->assertJsonPath('data.last_name', 'User');
    $response->assertJsonPath('data.locale', (string) config('app.locale'));
    $response->assertJsonPath('data.time_zone', 'UTC');
    $response->assertJsonPath('data.time_zone_iana', 'UTC');
    $response->assertJsonPath('data.utc_offset', 0);
});

it('GET /api/companion/me returns 401 when unauthenticated', function (): void {
    $this->getJson('/api/companion/me')->assertUnauthorized();
});

it('GET /api/companion/plugin_settings lists webhook recipes as plugin type 37', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $webhook = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'webhook',
        'name' => 'Webhook Recipe',
    ]);
    $webhookTwo = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'webhook',
        'name' => 'Webhook Recipe Two',
    ]);
    Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'name' => 'Polling Recipe',
    ]);
    Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'name' => 'Static Recipe',
    ]);

    $otherUser = User::factory()->create();
    Plugin::factory()->create([
        'user_id' => $otherUser->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'webhook',
        'name' => 'Other',
    ]);

    $response = $this->getJson('/api/companion/plugin_settings');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2)
        ->and(collect($data)->pluck('id')->all())->toEqualCanonicalizing([$webhook->id, $webhookTwo->id]);

    foreach ($data as $item) {
        expect($item)->toMatchArray([
            'plugin_id' => 37,
            'strategy' => 'webhook',
            'read_only?' => false,
        ]);
    }
});

it('POST /api/companion/plugin_settings/{id}/data accepts MessagePack and replaces data_payload', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'webhook',
        'data_payload' => ['events' => []],
    ]);

    $body = MessagePack::pack([
        'merge_variables' => [
            'events' => [['summary' => 'Planning']],
            'reminders' => [],
        ],
    ]);

    $response = $this->call(
        'POST',
        "/api/companion/plugin_settings/{$plugin->id}/data",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/msgpack',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $body,
    );

    $response->assertOk();
    $response->assertJson(['message' => 'Data accepted']);

    $plugin->refresh();
    expect($plugin->data_payload)->toBe([
        'events' => [['summary' => 'Planning']],
        'reminders' => [],
    ]);
    expect($plugin->data_payload_updated_at)->not->toBeNull();
});

it('POST /api/companion/plugin_settings/{id}/data replaces data on repeated upload', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'webhook',
    ]);

    $this->postJson("/api/companion/plugin_settings/{$plugin->id}/data", [
        'merge_variables' => ['count' => 1],
    ])->assertOk();

    $this->postJson("/api/companion/plugin_settings/{$plugin->id}/data", [
        'merge_variables' => ['count' => 2],
    ])->assertOk();

    $plugin->refresh();
    expect($plugin->data_payload)->toBe(['count' => 2]);
});

it('POST /api/companion/plugin_settings/{id}/data returns 404 for another users plugin', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Sanctum::actingAs($other);

    $plugin = Plugin::factory()->create([
        'user_id' => $owner->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'webhook',
    ]);

    $this->postJson("/api/companion/plugin_settings/{$plugin->id}/data", [
        'merge_variables' => ['x' => 1],
    ])->assertNotFound();
});

it('POST /api/companion/plugin_settings/{id}/data returns 404 for polling strategy', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
    ]);

    $this->postJson("/api/companion/plugin_settings/{$plugin->id}/data", [
        'merge_variables' => ['x' => 1],
    ])->assertNotFound();
});

it('POST /api/companion/plugin_settings/{id}/data returns 400 for invalid MessagePack', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'webhook',
    ]);

    $response = $this->call(
        'POST',
        "/api/companion/plugin_settings/{$plugin->id}/data",
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/msgpack'],
        'not-msgpack',
    );

    $response->assertBadRequest();
});

it('POST /api/companion/plugin_settings/{id}/data returns 400 when merge_variables is missing', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'webhook',
    ]);

    $this->postJson("/api/companion/plugin_settings/{$plugin->id}/data", [])
        ->assertBadRequest();
});
