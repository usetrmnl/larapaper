<?php

use App\Plugins\Enums\PluginOutput;
use App\Plugins\ImageWebhookPlugin;
use App\Plugins\PluginRegistry;
use App\Plugins\ScreenshotPlugin;

test('registry resolves image webhook handler', function (): void {
    $registry = app(PluginRegistry::class);

    expect($registry->get('image_webhook'))
        ->toBeInstanceOf(ImageWebhookPlugin::class);
});

test('registry resolves screenshot handler', function (): void {
    $registry = app(PluginRegistry::class);

    expect($registry->get('screenshot'))
        ->toBeInstanceOf(ScreenshotPlugin::class);
});

test('registry returns null for unknown plugin types', function (): void {
    expect(app(PluginRegistry::class)->get('nonexistent'))->toBeNull();
});

test('image webhook handler declares ProcessedImage output', function (): void {
    $handler = app(PluginRegistry::class)->get('image_webhook');

    expect($handler->output())->toBe(PluginOutput::ProcessedImage);
});

test('screenshot handler declares Image output', function (): void {
    $handler = app(PluginRegistry::class)->get('screenshot');

    expect($handler->output())->toBe(PluginOutput::Image);
});

test('unknown plugin type webhook returns 400', function (): void {
    $user = App\Models\User::factory()->create();
    $plugin = App\Models\Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
    ]);

    $response = $this->postJson("/api/plugins/{$plugin->uuid}/webhook", []);

    $response->assertStatus(400);
});
