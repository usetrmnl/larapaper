<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\TemporaryDirectory\TemporaryDirectory;

it('accepts a plugin settings archive and updates the plugin', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'uuid' => (string) Str::uuid(),
    ]);

    // Authenticate via Sanctum (endpoint requires auth:sanctum)
    Sanctum::actingAs($user);

    $temporaryDirectory = (new TemporaryDirectory)->deleteWhenDestroyed()->create();
    $srcDir = $temporaryDirectory->path('src');

    $settingsYaml = <<<'YAML'
name: Sample Imported
strategy: static
refresh_interval: 10
custom_fields:
  - keyname: title
    default: "Hello"
static_data: '{"message":"world"}'
YAML;

    $fullLiquid = <<<'LIQUID'
<h1>{{ config.title }}</h1>
<div>{{ data.message }}</div>
LIQUID;

    file_put_contents($srcDir.'/settings.yml', $settingsYaml);
    file_put_contents($srcDir.'/full.liquid', $fullLiquid);

    $zipPath = $temporaryDirectory->path('plugin.zip');
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFile($srcDir.'/settings.yml', 'src/settings.yml');
    $zip->addFile($srcDir.'/full.liquid', 'src/full.liquid');
    $zip->close();

    // Prepare UploadedFile
    $uploaded = new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);

    // Make request (multipart form-data)
    $response = $this->post('/api/plugin_settings/'.$plugin->uuid.'/archive', [
        'file' => $uploaded,
    ], ['Accept' => 'application/json']);

    $response->assertSuccessful();

    $imported = Plugin::query()
        ->where('user_id', $user->id)
        ->where('name', 'Sample Imported')
        ->first();

    expect($imported)->not->toBeNull();
    expect($imported->markup_language)->toBe('liquid');
    expect($imported->render_markup)->toContain('<h1>{{ config.title }}</h1>');
    // Configuration should have default for title (set on create)
    expect($imported->configuration['title'] ?? null)->toBe('Hello');
});
