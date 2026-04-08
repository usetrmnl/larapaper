<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use App\Services\PluginExportService;
use App\Services\PluginImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\TemporaryDirectory\TemporaryDirectory;

beforeEach(function (): void {
    Storage::fake('local');
});

it('exports plugin to zip file in correct format', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'trmnlp_id' => 'test-plugin-123',
        'data_stale_minutes' => 30,
        'data_strategy' => 'static',
        'markup_language' => 'liquid',
        'render_markup' => '<div>Hello {{ config.name }}</div>',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'name',
                    'field_type' => 'text',
                    'default' => 'World',
                ],
            ],
        ],
        'data_payload' => ['message' => 'Hello World'],
    ]);

    $exporter = app(PluginExportService::class);
    $response = $exporter->exportToZip($plugin, $user);

    expect($response)->toBeInstanceOf(Symfony\Component\HttpFoundation\BinaryFileResponse::class);
    expect($response->getFile()->getFilename())->toContain('test-plugin-123.zip');
});

it('exports plugin with polling configuration', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Polling Plugin',
        'trmnlp_id' => 'polling-plugin-456',
        'data_strategy' => 'polling',
        'polling_url' => 'https://api.example.com/data',
        'polling_verb' => 'post',
        'polling_header' => 'Authorization: Bearer token',
        'polling_body' => '{"key": "value"}',
        'markup_language' => 'blade',
        'render_markup' => '<div>Hello {{ $config["name"] }}</div>',
    ]);

    $exporter = app(PluginExportService::class);
    $response = $exporter->exportToZip($plugin, $user);

    expect($response)->toBeInstanceOf(Symfony\Component\HttpFoundation\BinaryFileResponse::class);
});

it('exports and imports plugin maintaining all data', function (): void {
    $user = User::factory()->create();
    $originalPlugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Round Trip Plugin',
        'trmnlp_id' => 'round-trip-789',
        'data_stale_minutes' => 45,
        'data_strategy' => 'static',
        'markup_language' => 'liquid',
        'render_markup' => '<div>Hello {{ config.name }}!</div>',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'name',
                    'field_type' => 'text',
                    'default' => 'Test User',
                ],
                [
                    'keyname' => 'color',
                    'field_type' => 'select',
                    'default' => 'blue',
                    'options' => ['red', 'green', 'blue'],
                ],
            ],
        ],
        'data_payload' => ['items' => [1, 2, 3]],
    ]);

    // Export the plugin
    $exporter = app(PluginExportService::class);
    $exportResponse = $exporter->exportToZip($originalPlugin, $user);

    // Get the exported file path
    $exportedFilePath = $exportResponse->getFile()->getPathname();

    // Create an UploadedFile from the exported ZIP
    $uploadedFile = new UploadedFile(
        $exportedFilePath,
        'plugin_round-trip-789.zip',
        'application/zip',
        null,
        true
    );

    // Import the plugin back
    $importer = app(PluginImportService::class);
    $importedPlugin = $importer->importFromZip($uploadedFile, $user);

    // Verify the imported plugin has the same data
    expect($importedPlugin->name)->toBe('Round Trip Plugin');
    expect($importedPlugin->trmnlp_id)->toBe('round-trip-789');
    expect($importedPlugin->data_stale_minutes)->toBe(45);
    expect($importedPlugin->data_strategy)->toBe('static');
    expect($importedPlugin->markup_language)->toBe('liquid');
    expect($importedPlugin->render_markup)->toContain('Hello {{ config.name }}!');
    expect($importedPlugin->configuration_template['custom_fields'])->toHaveCount(2);
    expect($importedPlugin->data_payload)->toBe(['items' => [1, 2, 3]]);
});

it('handles blade templates correctly', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Blade Plugin',
        'trmnlp_id' => 'blade-plugin-101',
        'markup_language' => 'blade',
        'render_markup' => '<div>Hello {{ $config["name"] }}!</div>',
    ]);

    $exporter = app(PluginExportService::class);
    $response = $exporter->exportToZip($plugin, $user);

    expect($response)->toBeInstanceOf(Symfony\Component\HttpFoundation\BinaryFileResponse::class);
});

it('removes wrapper div from exported markup', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Wrapped Plugin',
        'trmnlp_id' => 'wrapped-plugin-202',
        'markup_language' => 'liquid',
        'render_markup' => '<div class="view view--{{ size }}">Hello World</div>',
    ]);

    $exporter = app(PluginExportService::class);
    $response = $exporter->exportToZip($plugin, $user);

    expect($response)->toBeInstanceOf(Symfony\Component\HttpFoundation\BinaryFileResponse::class);
});

it('converts polling headers correctly', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Header Plugin',
        'trmnlp_id' => 'header-plugin-303',
        'data_strategy' => 'polling',
        'polling_header' => 'Authorization: Bearer token',
    ]);

    $exporter = app(PluginExportService::class);
    $response = $exporter->exportToZip($plugin, $user);

    expect($response)->toBeInstanceOf(Symfony\Component\HttpFoundation\BinaryFileResponse::class);
});

it('api route returns zip file for authenticated user', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'API Test Plugin',
        'trmnlp_id' => 'api-test-404',
        'markup_language' => 'liquid',
        'render_markup' => '<div>API Test</div>',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/plugin_settings/{$plugin->trmnlp_id}/archive");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/zip');
    $response->assertHeader('Content-Disposition', 'attachment; filename=plugin_api-test-404.zip');
});

it('api route returns 404 for non-existent plugin', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/plugin_settings/non-existent-id/archive');

    $response->assertStatus(404);
});

it('api route returns 401 for unauthenticated user', function (): void {
    $response = $this->getJson('/api/plugin_settings/test-id/archive');

    $response->assertStatus(401);
});

it('api route returns 404 for plugin belonging to different user', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user1->id,
        'trmnlp_id' => 'other-user-plugin',
    ]);

    $response = $this->actingAs($user2)
        ->getJson("/api/plugin_settings/{$plugin->trmnlp_id}/archive");

    $response->assertStatus(404);
});

it('exports zip with files in root directory', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Root Directory Test',
        'trmnlp_id' => 'root-test-123',
        'markup_language' => 'liquid',
        'render_markup' => '<div>Test content</div>',
    ]);

    $exporter = app(PluginExportService::class);
    $response = $exporter->exportToZip($plugin, $user);

    $zipPath = $response->getFile()->getPathname();
    $zip = new ZipArchive();
    $zip->open($zipPath);

    // Check that files are in the root, not in src/
    expect($zip->locateName('settings.yml'))->not->toBeFalse();
    expect($zip->locateName('full.liquid'))->not->toBeFalse();
    expect($zip->locateName('src/settings.yml'))->toBeFalse();
    expect($zip->locateName('src/full.liquid'))->toBeFalse();

    $zip->close();
});

it('maintains correct yaml field order', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'YAML Order Test',
        'trmnlp_id' => 'yaml-order-test',
        'data_strategy' => 'polling',
        'polling_url' => 'https://api.example.com/data',
        'polling_verb' => 'post',
        'data_stale_minutes' => 30,
        'markup_language' => 'liquid',
        'render_markup' => '<div>Test</div>',
    ]);

    $exporter = app(PluginExportService::class);
    $response = $exporter->exportToZip($plugin, $user);

    $zipPath = $response->getFile()->getPathname();
    $zip = new ZipArchive();
    $zip->open($zipPath);

    $temporaryDirectory = (new TemporaryDirectory)->deleteWhenDestroyed()->create();
    $tempDir = $temporaryDirectory->path();
    $zip->extractTo($tempDir, 'settings.yml');
    $yamlContent = file_get_contents($tempDir.'/settings.yml');
    $zip->close();

    // Check that the YAML content has the expected field order
    $expectedOrder = [
        'name:',
        'no_screen_padding:',
        'dark_mode:',
        'strategy:',
        'static_data:',
        'polling_verb:',
        'polling_url:',
        'refresh_interval:',
        'id:',
        'custom_fields:',
    ];

    $lines = explode("\n", $yamlContent);
    $fieldLines = [];

    foreach ($lines as $line) {
        $line = mb_trim($line);
        if (preg_match('/^([a-zA-Z_]+):/', $line, $matches)) {
            $fieldLines[] = $matches[1].':';
        }
    }

    // Verify that the fields appear in the expected order (allowing for missing optional fields)
    $currentIndex = 0;
    foreach ($expectedOrder as $expectedField) {
        $foundIndex = array_search($expectedField, $fieldLines);
        if ($foundIndex !== false) {
            expect($foundIndex)->toBeGreaterThanOrEqual($currentIndex);
            $currentIndex = $foundIndex;
        }
    }
});
