<?php

use App\Models\Plugin;
use Illuminate\Support\Str;

test('webhook updates plugin data for webhook strategy', function (): void {
    // Create a plugin with webhook strategy
    $plugin = Plugin::factory()->create([
        'data_strategy' => 'webhook',
        'data_payload' => ['old' => 'data'],
    ]);

    // Make request to update plugin data
    $response = $this->postJson("/api/custom_plugins/{$plugin->uuid}", [
        'merge_variables' => ['new' => 'data'],
    ]);

    // Assert response
    $response->assertOk()
        ->assertJson(['message' => 'Data updated successfully']);

    // Assert plugin was updated
    $this->assertDatabaseHas('plugins', [
        'id' => $plugin->id,
        'data_payload' => json_encode(['new' => 'data']),
    ]);
});

test('webhook returns 400 for non-webhook strategy plugins', function (): void {
    // Create a plugin with non-webhook strategy
    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'data_payload' => ['old' => 'data'],
    ]);

    // Make request to update plugin data
    $response = $this->postJson("/api/custom_plugins/{$plugin->uuid}", [
        'merge_variables' => ['new' => 'data'],
    ]);

    // Assert response
    $response->assertStatus(400)
        ->assertJson(['error' => 'Plugin does not use webhook strategy']);
});

test('webhook returns 400 when merge_variables is missing', function (): void {
    // Create a plugin with webhook strategy
    $plugin = Plugin::factory()->create([
        'data_strategy' => 'webhook',
        'data_payload' => ['old' => 'data'],
    ]);

    // Make request without merge_variables
    $response = $this->postJson("/api/custom_plugins/{$plugin->uuid}", []);

    // Assert response
    $response->assertStatus(400)
        ->assertJson(['error' => 'Request must contain merge_variables key']);
});

test('webhook returns 404 for non-existent plugin', function (): void {
    // Make request with non-existent plugin UUID
    $response = $this->postJson('/api/custom_plugins/'.Str::uuid(), [
        'merge_variables' => ['new' => 'data'],
    ]);

    // Assert response
    $response->assertNotFound();
});

test('webhook rejects merge_variables that exceed the wire size limit', function (): void {
    // Tiny limit so a small payload can exceed the budget (max_size - 512 bytes).
    config(['livewire.payload.max_size' => 768]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'webhook',
        'data_payload' => ['old' => 'data'],
    ]);

    $oversized = ['blob' => str_repeat('A', 1024)];

    $response = $this->postJson("/api/custom_plugins/{$plugin->uuid}", [
        'merge_variables' => $oversized,
    ]);

    $response->assertStatus(413)
        ->assertJson(Plugin::oversizedDataPayloadErrorPayload());

    expect($plugin->fresh()->data_payload)->toBe(['old' => 'data']);
});
