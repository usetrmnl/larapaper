<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use App\Services\PluginImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\TemporaryDirectory\TemporaryDirectory;

beforeEach(function (): void {
    Storage::fake('local');
});

it('imports plugin from valid zip file', function (): void {
    $user = User::factory()->create();

    // Create a mock ZIP file with the required structure
    $zipContent = createMockZipFile([
        'src/settings.yml' => getValidSettingsYaml(),
        'src/full.liquid' => getValidFullLiquid(),
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('test-plugin.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->user_id)->toBe($user->id)
        ->and($plugin->name)->toBe('Test Plugin')
        ->and($plugin->data_stale_minutes)->toBe(30)
        ->and($plugin->data_strategy)->toBe('static')
        ->and($plugin->markup_language)->toBe('liquid')
        ->and($plugin->configuration_template)->toHaveKey('custom_fields')
        ->and($plugin->configuration)->toHaveKey('api_key')
        ->and($plugin->configuration['api_key'])->toBe('default-api-key');
});

it('imports plugin with shared.liquid file', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'src/settings.yml' => getValidSettingsYaml(),
        'src/full.liquid' => getValidFullLiquid(),
        'src/shared.liquid' => '{% comment %}Shared styles{% endcomment %}',
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('test-plugin.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin->render_markup_shared)->toBe('{% comment %}Shared styles{% endcomment %}')
        ->and($plugin->render_markup)->toContain('<div class="view view--{{ size }}">')
        ->and($plugin->getMarkupForSize('full'))->toContain('{% comment %}Shared styles{% endcomment %}')
        ->and($plugin->getMarkupForSize('full'))->toContain('<div class="view view--{{ size }}">');
});

it('imports plugin with files in root directory', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'settings.yml' => getValidSettingsYaml(),
        'full.liquid' => getValidFullLiquid(),
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('test-plugin.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->name)->toBe('Test Plugin');
});

it('throws exception for invalid zip file', function (): void {
    $user = User::factory()->create();

    $zipFile = UploadedFile::fake()->createWithContent('invalid.zip', 'not a zip file');

    $pluginImportService = new PluginImportService();
    expect(fn (): Plugin => $pluginImportService->importFromZip($zipFile, $user))
        ->toThrow(Exception::class, 'Could not open the ZIP file.');
});

it('throws exception for missing settings.yml', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'src/full.liquid' => getValidFullLiquid(),
        // Missing settings.yml
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('test-plugin.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    expect(fn (): Plugin => $pluginImportService->importFromZip($zipFile, $user))
        ->toThrow(Exception::class, 'Invalid ZIP structure. Required file settings.yml is missing.');
});

it('throws exception for missing template files', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'src/settings.yml' => getValidSettingsYaml(),
        // Missing all template files
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('test-plugin.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    expect(fn (): Plugin => $pluginImportService->importFromZip($zipFile, $user))
        ->toThrow(Exception::class, 'Invalid ZIP structure. At least one of the following files is required: full.liquid, full.blade.php, shared.liquid, or shared.blade.php.');
});

it('sets default values when settings are missing', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'src/settings.yml' => "name: Minimal Plugin\n",
        'src/full.liquid' => getValidFullLiquid(),
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('test-plugin.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin->name)->toBe('Minimal Plugin')
        ->and($plugin->data_stale_minutes)->toBe(15) // default value
        ->and($plugin->data_strategy)->toBe('static') // default value
        ->and($plugin->polling_verb)->toBe('get'); // default value
});

it('handles blade markup language correctly', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'src/settings.yml' => getValidSettingsYaml(),
        'src/full.blade.php' => '<div>Blade template</div>',
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('test-plugin.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin->markup_language)->toBe('blade')
        ->and($plugin->render_markup)->not->toContain('<div class="view view--{{ size }}">')
        ->and($plugin->render_markup)->toBe('<div>Blade template</div>');
});

it('imports plugin from monorepo with zip_entry_path parameter', function (): void {
    $user = User::factory()->create();

    // Create a mock ZIP file with plugin in a subdirectory
    $zipContent = createMockZipFile([
        'example-plugin/settings.yml' => getValidSettingsYaml(),
        'example-plugin/full.liquid' => getValidFullLiquid(),
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('monorepo.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->user_id)->toBe($user->id)
        ->and($plugin->name)->toBe('Test Plugin');
});

it('imports plugin from monorepo with src subdirectory', function (): void {
    $user = User::factory()->create();

    // Create a mock ZIP file with plugin in a subdirectory with src folder
    $zipContent = createMockZipFile([
        'example-plugin/src/settings.yml' => getValidSettingsYaml(),
        'example-plugin/src/full.liquid' => getValidFullLiquid(),
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('monorepo.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->user_id)->toBe($user->id)
        ->and($plugin->name)->toBe('Test Plugin');
});

it('imports plugin from monorepo with shared.liquid in subdirectory', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'example-plugin/settings.yml' => getValidSettingsYaml(),
        'example-plugin/full.liquid' => getValidFullLiquid(),
        'example-plugin/shared.liquid' => '{% comment %}Monorepo shared styles{% endcomment %}',
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('monorepo.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin->render_markup_shared)->toBe('{% comment %}Monorepo shared styles{% endcomment %}')
        ->and($plugin->render_markup)->toContain('<div class="view view--{{ size }}">')
        ->and($plugin->getMarkupForSize('full'))->toContain('{% comment %}Monorepo shared styles{% endcomment %}')
        ->and($plugin->getMarkupForSize('full'))->toContain('<div class="view view--{{ size }}">');
});

it('imports plugin from URL with zip_entry_path parameter', function (): void {
    $user = User::factory()->create();

    // Create a mock ZIP file with plugin in a subdirectory
    $zipContent = createMockZipFile([
        'example-plugin/settings.yml' => getValidSettingsYaml(),
        'example-plugin/full.liquid' => getValidFullLiquid(),
    ]);

    // Mock the HTTP response
    Http::fake([
        'https://github.com/example/repo/archive/refs/heads/main.zip' => Http::response($zipContent, 200),
    ]);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromUrl(
        'https://github.com/example/repo/archive/refs/heads/main.zip',
        $user,
        'example-plugin'
    );

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->user_id)->toBe($user->id)
        ->and($plugin->name)->toBe('Test Plugin');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://github.com/example/repo/archive/refs/heads/main.zip');
});

it('imports plugin from URL with zip_entry_path and src subdirectory', function (): void {
    $user = User::factory()->create();

    // Create a mock ZIP file with plugin in a subdirectory with src folder
    $zipContent = createMockZipFile([
        'example-plugin/src/settings.yml' => getValidSettingsYaml(),
        'example-plugin/src/full.liquid' => getValidFullLiquid(),
    ]);

    // Mock the HTTP response
    Http::fake([
        'https://github.com/example/repo/archive/refs/heads/main.zip' => Http::response($zipContent, 200),
    ]);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromUrl(
        'https://github.com/example/repo/archive/refs/heads/main.zip',
        $user,
        'example-plugin'
    );

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->user_id)->toBe($user->id)
        ->and($plugin->name)->toBe('Test Plugin');
});

it('imports plugin from GitHub monorepo with repository-named directory', function (): void {
    $user = User::factory()->create();

    // Create a mock ZIP file that simulates GitHub's ZIP structure with repository-named directory
    $zipContent = createMockZipFile([
        'example-repo-main/another-plugin/src/settings.yml' => "name: Other Plugin\nrefresh_interval: 60\nstrategy: static\npolling_verb: get\nstatic_data: '{}'\ncustom_fields: []",
        'example-repo-main/another-plugin/src/full.liquid' => '<div>Other content</div>',
        'example-repo-main/example-plugin/src/settings.yml' => getValidSettingsYaml(),
        'example-repo-main/example-plugin/src/full.liquid' => getValidFullLiquid(),
    ]);

    // Mock the HTTP response
    Http::fake([
        'https://github.com/example/repo/archive/refs/heads/main.zip' => Http::response($zipContent, 200),
    ]);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromUrl(
        'https://github.com/example/repo/archive/refs/heads/main.zip',
        $user,
        'example-repo-main/example-plugin'
    );

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->user_id)->toBe($user->id)
        ->and($plugin->name)->toBe('Test Plugin'); // Should be from example-plugin, not other-plugin
});

it('finds required files in simple ZIP structure', function (): void {
    $user = User::factory()->create();

    // Create a simple ZIP file with just one plugin
    $zipContent = createMockZipFile([
        'example-repo-main/example-plugin/src/settings.yml' => getValidSettingsYaml(),
        'example-repo-main/example-plugin/src/full.liquid' => getValidFullLiquid(),
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('simple.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->user_id)->toBe($user->id)
        ->and($plugin->name)->toBe('Test Plugin');
});

it('finds required files in GitHub monorepo structure with zip_entry_path', function (): void {
    $user = User::factory()->create();

    // Create a mock ZIP file that simulates GitHub's ZIP structure
    $zipContent = createMockZipFile([
        'example-repo-main/example-plugin/src/settings.yml' => getValidSettingsYaml(),
        'example-repo-main/example-plugin/src/full.liquid' => getValidFullLiquid(),
        'example-repo-main/other-plugin/src/settings.yml' => "name: Other Plugin\nrefresh_interval: 60\nstrategy: static\npolling_verb: get\nstatic_data: '{}'\ncustom_fields: []",
        'example-repo-main/other-plugin/src/full.liquid' => '<div>Other content</div>',
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('monorepo.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user, 'example-repo-main/example-plugin');

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->user_id)->toBe($user->id)
        ->and($plugin->name)->toBe('Test Plugin'); // Should be from example-plugin, not other-plugin
});

it('imports specific plugin from monorepo zip with zip_entry_path parameter', function (): void {
    $user = User::factory()->create();

    // Create a mock ZIP file with 2 plugins in a monorepo structure
    $zipContent = createMockZipFile([
        'example-plugin/settings.yml' => getValidSettingsYaml(),
        'example-plugin/full.liquid' => getValidFullLiquid(),
        'example-plugin/shared.liquid' => '{% comment %}Monorepo shared styles{% endcomment %}',
        'example-plugin2/settings.yml' => "name: Example Plugin 2\nrefresh_interval: 45\nstrategy: static\npolling_verb: get\nstatic_data: '{}'\ncustom_fields: []",
        'example-plugin2/full.liquid' => '<div class="plugin2-content">Plugin 2 content</div>',
        'example-plugin2/shared.liquid' => '{% comment %}Plugin 2 shared styles{% endcomment %}',
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('monorepo.zip', $zipContent);

    $pluginImportService = new PluginImportService();

    // This test will fail because importFromZip doesn't support zip_entry_path parameter yet
    // The logic needs to be implemented to specify which plugin to import from the monorepo
    $plugin = $pluginImportService->importFromZip($zipFile, $user, 'example-plugin2');

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->user_id)->toBe($user->id)
        ->and($plugin->name)->toBe('Example Plugin 2') // Should import example-plugin2, not example-plugin
        ->and($plugin->render_markup_shared)->toBe('{% comment %}Plugin 2 shared styles{% endcomment %}')
        ->and($plugin->render_markup)->toContain('<div class="plugin2-content">Plugin 2 content</div>')
        ->and($plugin->getMarkupForSize('full'))->toContain('{% comment %}Plugin 2 shared styles{% endcomment %}')
        ->and($plugin->getMarkupForSize('full'))->toContain('<div class="plugin2-content">Plugin 2 content</div>');
});

it('sets icon_url when importing from URL with iconUrl parameter', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'src/settings.yml' => getValidSettingsYaml(),
        'src/full.liquid' => getValidFullLiquid(),
    ]);

    Http::fake([
        'https://example.com/plugin.zip' => Http::response($zipContent, 200),
    ]);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromUrl(
        'https://example.com/plugin.zip',
        $user,
        null,
        null,
        'https://example.com/icon.png'
    );

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->icon_url)->toBe('https://example.com/icon.png');
});

it('does not set icon_url when importing from URL without iconUrl parameter', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'src/settings.yml' => getValidSettingsYaml(),
        'src/full.liquid' => getValidFullLiquid(),
    ]);

    Http::fake([
        'https://example.com/plugin.zip' => Http::response($zipContent, 200),
    ]);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromUrl(
        'https://example.com/plugin.zip',
        $user
    );

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->icon_url)->toBeNull();
});

it('normalizes non-named select options to named values', function (): void {
    $user = User::factory()->create();

    $settingsYaml = <<<'YAML'
name: Test Plugin
refresh_interval: 30
strategy: static
polling_verb: get
static_data: '{}'
custom_fields:
  - keyname: display_incident
    field_type: select
    options:
      - true
      - false
    default: true
YAML;

    $zipContent = createMockZipFile([
        'src/settings.yml' => $settingsYaml,
        'src/full.liquid' => getValidFullLiquid(),
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('test-plugin.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    $customFields = $plugin->configuration_template['custom_fields'];
    $displayIncidentField = collect($customFields)->firstWhere('keyname', 'display_incident');

    expect($displayIncidentField)->not->toBeNull()
        ->and($displayIncidentField['options'])->toBe([
            ['true' => 'true'],
            ['false' => 'false'],
        ])
        ->and($displayIncidentField['default'])->toBe('true');
});

it('throws exception when multi_string default value contains a comma', function (): void {
    $user = User::factory()->create();

    // YAML with a comma in the 'default' field of a multi_string
    $invalidYaml = <<<'YAML'
name: Test Plugin
refresh_interval: 30
strategy: static
polling_verb: get
static_data: '{"test": "data"}'
custom_fields:
  - keyname: api_key
    field_type: multi_string
    default: default-api-key1,default-api-key2
    label: API Key
YAML;

    $zipContent = createMockZipFile([
        'src/settings.yml' => $invalidYaml,
        'src/full.liquid' => getValidFullLiquid(),
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('invalid-default.zip', $zipContent);
    $pluginImportService = new PluginImportService();

    expect(fn (): Plugin => $pluginImportService->importFromZip($zipFile, $user))
        ->toThrow(Exception::class, 'Validation Error: The default value for multistring fields like `api_key` cannot contain commas.');
});

it('throws exception when multi_string placeholder contains a comma', function (): void {
    $user = User::factory()->create();

    // YAML with a comma in the 'placeholder' field
    $invalidYaml = <<<'YAML'
name: Test Plugin
refresh_interval: 30
strategy: static
polling_verb: get
static_data: '{"test": "data"}'
custom_fields:
  - keyname: api_key
    field_type: multi_string
    default: default-api-key
    label: API Key
    placeholder: "value1, value2"
YAML;

    $zipContent = createMockZipFile([
        'src/settings.yml' => $invalidYaml,
        'src/full.liquid' => getValidFullLiquid(),
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('invalid-placeholder.zip', $zipContent);
    $pluginImportService = new PluginImportService();

    expect(fn (): Plugin => $pluginImportService->importFromZip($zipFile, $user))
        ->toThrow(Exception::class, 'Validation Error: The placeholder value for multistring fields like `api_key` cannot contain commas.');
});

it('imports plugin with only shared.liquid file', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'src/settings.yml' => getValidSettingsYaml(),
        'src/shared.liquid' => '<div class="shared-content">{{ data.title }}</div>',
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('test-plugin.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->markup_language)->toBe('liquid')
        ->and($plugin->render_markup_shared)->toBe('<div class="shared-content">{{ data.title }}</div>')
        ->and($plugin->render_markup)->toBeNull();
});

it('imports plugin with only shared.blade.php file', function (): void {
    $user = User::factory()->create();

    $zipContent = createMockZipFile([
        'src/settings.yml' => getValidSettingsYaml(),
        'src/shared.blade.php' => '<div class="shared-content">{{ $data["title"] }}</div>',
    ]);

    $zipFile = UploadedFile::fake()->createWithContent('test-plugin.zip', $zipContent);

    $pluginImportService = new PluginImportService();
    $plugin = $pluginImportService->importFromZip($zipFile, $user);

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->markup_language)->toBe('blade')
        ->and($plugin->render_markup_shared)->toBe('<div class="shared-content">{{ $data["title"] }}</div>')
        ->and($plugin->render_markup)->toBeNull();
});

// Helper methods
function createMockZipFile(array $files): string
{
    $zip = new ZipArchive();
    $temporaryDirectory = (new TemporaryDirectory)->deleteWhenDestroyed()->create();
    $tempFile = $temporaryDirectory->path('mock.zip');

    $zip->open($tempFile, ZipArchive::CREATE);

    foreach ($files as $path => $content) {
        $zip->addFromString($path, $content);
    }

    $zip->close();

    return file_get_contents($tempFile);
}

function getValidSettingsYaml(): string
{
    return <<<'YAML'
name: Test Plugin
refresh_interval: 30
strategy: static
polling_verb: get
static_data: '{"test": "data"}'
custom_fields:
  - keyname: api_key
    field_type: text
    default: default-api-key
    label: API Key
YAML;
}

function getValidFullLiquid(): string
{
    return <<<'LIQUID'
<div class="plugin-content">
  <h1>{{ data.title }}</h1>
  <p>{{ data.description }}</p>
</div>
LIQUID;
}
