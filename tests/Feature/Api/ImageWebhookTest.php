<?php

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
});

test('can upload image to image webhook plugin via multipart form', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create([
        'user_id' => $user->id,
    ]);

    $image = UploadedFile::fake()->image('test.png', 800, 480);

    $response = $this->post("/api/plugins/{$plugin->uuid}/webhook", [
        'image' => $image,
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'message',
            'image_url',
        ]);

    $plugin->refresh();
    expect($plugin->current_image)
        ->not->toBeNull()
        ->not->toBe($plugin->uuid); // Should be a new UUID, not the plugin's UUID

    // File should exist with the new UUID
    Storage::disk('public')->assertExists("images/generated/{$plugin->current_image}.png");

    // Image URL should contain the new UUID
    expect($response->json('image_url'))
        ->toContain($plugin->current_image);
});

test('can upload image to image webhook plugin via raw binary', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create([
        'user_id' => $user->id,
    ]);

    // Create a simple PNG image binary
    $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $response = $this->call('POST', "/api/plugins/{$plugin->uuid}/webhook", [], [], [], [
        'CONTENT_TYPE' => 'image/png',
    ], $pngData);

    $response->assertOk()
        ->assertJsonStructure([
            'message',
            'image_url',
        ]);

    $plugin->refresh();
    expect($plugin->current_image)
        ->not->toBeNull()
        ->not->toBe($plugin->uuid); // Should be a new UUID, not the plugin's UUID

    // File should exist with the new UUID
    Storage::disk('public')->assertExists("images/generated/{$plugin->current_image}.png");

    // Image URL should contain the new UUID
    expect($response->json('image_url'))
        ->toContain($plugin->current_image);
});

test('can upload image to image webhook plugin via base64 data URI', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create([
        'user_id' => $user->id,
    ]);

    // Create a simple PNG image as base64 data URI
    $base64Image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    $response = $this->postJson("/api/plugins/{$plugin->uuid}/webhook", [
        'image' => $base64Image,
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'message',
            'image_url',
        ]);

    $plugin->refresh();
    expect($plugin->current_image)
        ->not->toBeNull()
        ->not->toBe($plugin->uuid); // Should be a new UUID, not the plugin's UUID

    // File should exist with the new UUID
    Storage::disk('public')->assertExists("images/generated/{$plugin->current_image}.png");

    // Image URL should contain the new UUID
    expect($response->json('image_url'))
        ->toContain($plugin->current_image);
});

test('returns 404 for non-handler plugin type (recipe has no webhook handler)', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
    ]);

    $image = UploadedFile::fake()->image('test.png', 800, 480);

    $response = $this->post("/api/plugins/{$plugin->uuid}/webhook", [
        'image' => $image,
    ]);

    // Recipe is not registered in PluginRegistry, so the generic controller
    // returns 400 for unknown plugin types.
    $response->assertStatus(400);
});

test('returns 404 for non-existent plugin', function (): void {
    $image = UploadedFile::fake()->image('test.png', 800, 480);

    $response = $this->post('/api/plugins/'.Str::uuid().'/webhook', [
        'image' => $image,
    ]);

    $response->assertNotFound();
});

test('returns 400 for unsupported image format', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create([
        'user_id' => $user->id,
    ]);

    // Create a fake GIF file (not supported)
    $gifData = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

    $response = $this->call('POST', "/api/plugins/{$plugin->uuid}/webhook", [], [], [], [
        'CONTENT_TYPE' => 'image/gif',
    ], $gifData);

    $response->assertStatus(400)
        ->assertJson(['error' => 'Unsupported image format. Expected PNG or BMP.']);
});

test('returns 400 for JPG image format', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create([
        'user_id' => $user->id,
    ]);

    // Create a fake JPG file (not supported)
    $jpgData = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A8A');

    $response = $this->call('POST', "/api/plugins/{$plugin->uuid}/webhook", [], [], [], [
        'CONTENT_TYPE' => 'image/jpeg',
    ], $jpgData);

    $response->assertStatus(400)
        ->assertJson(['error' => 'Unsupported image format. Expected PNG or BMP.']);
});

test('returns 400 when no image data provided', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->postJson("/api/plugins/{$plugin->uuid}/webhook", []);

    $response->assertStatus(400)
        ->assertJson(['error' => 'No image data provided']);
});

test('image webhook plugin isDataStale returns false', function (): void {
    $plugin = Plugin::factory()->imageWebhook()->create();

    expect($plugin->isDataStale())->toBeFalse();
});

test('image webhook plugin factory creates correct plugin type', function (): void {
    $plugin = Plugin::factory()->imageWebhook()->create();

    expect($plugin)
        ->plugin_type->toBe('image_webhook')
        ->data_strategy->toBe('static');
});

test('legacy /plugin_settings/{uuid}/image route still works (BC shim)', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->imageWebhook()->create([
        'user_id' => $user->id,
    ]);

    $image = UploadedFile::fake()->image('test.png', 800, 480);

    $response = $this->post("/api/plugin_settings/{$plugin->uuid}/image", [
        'image' => $image,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'image_url']);

    $plugin->refresh();
    expect($plugin->current_image)->not->toBeNull();
    Storage::disk('public')->assertExists("images/generated/{$plugin->current_image}.png");
});
