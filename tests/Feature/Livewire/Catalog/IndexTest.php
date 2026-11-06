<?php

use App\Models\User;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    Cache::flush();
});

it('can render catalog component', function (): void {
    // Mock empty catalog response
    Http::fake([
        config('app.catalog_url') => Http::response('', 200),
    ]);

    Livewire::withoutLazyLoading();

    $component = Livewire::test('catalog.index');

    $component->assertSee('No plugins available');
});

it('loads plugins from catalog URL', function (): void {
    // Clear cache first to ensure fresh data
    Cache::forget('catalog_plugins');

    // Mock the HTTP response for the catalog URL
    $catalogData = [
        'test-plugin' => [
            'name' => 'Test Plugin',
            'author' => ['name' => 'Test Author', 'github' => 'testuser'],
            'author_bio' => [
                'description' => 'A test plugin',
                'learn_more_url' => 'https://example.com',
            ],
            'license' => 'MIT',
            'trmnlp' => [
                'zip_url' => 'https://example.com/plugin.zip',
            ],
            'byos' => [
                'byos_laravel' => [
                    'compatibility' => true,
                ],
            ],
            'logo_url' => 'https://example.com/logo.png',
        ],
    ];

    $yamlContent = Yaml::dump($catalogData);

    // Override the default mock with specific data
    Http::fake([
        config('app.catalog_url') => Http::response($yamlContent, 200),
    ]);

    Livewire::withoutLazyLoading();

    $component = Livewire::test('catalog.index');

    $component->assertSee('Test Plugin');
    $component->assertSee('testuser');
    $component->assertSee('A test plugin');
    $component->assertSee('MIT');
    $component->assertSee('Preview');
    $component->assertSee('Learn More');
});

it('hides preview button when screenshot_url is missing', function (): void {
    // Clear cache first to ensure fresh data
    Cache::forget('catalog_plugins');

    // Mock the HTTP response for the catalog URL without screenshot_url
    $catalogData = [
        'test-plugin' => [
            'name' => 'Test Plugin Without Screenshot',
            'author' => ['name' => 'Test Author', 'github' => 'testuser'],
            'author_bio' => [
                'description' => 'A test plugin',
            ],
            'license' => 'MIT',
            'trmnlp' => [
                'zip_url' => 'https://example.com/plugin.zip',
            ],
            'byos' => [
                'byos_laravel' => [
                    'compatibility' => true,
                ],
            ],
            'logo_url' => 'https://example.com/logo.png',
            'screenshot_url' => null,
        ],
    ];

    $yamlContent = Yaml::dump($catalogData);

    Http::fake([
        config('app.catalog_url') => Http::response($yamlContent, 200),
    ]);

    Livewire::withoutLazyLoading();

    Livewire::test('catalog.index')
        ->assertSee('Test Plugin Without Screenshot')
        ->assertDontSeeHtml('variant="subtle" icon="eye"');
});

it('shows error when plugin not found', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::withoutLazyLoading();

    $component = Livewire::test('catalog.index');

    $component->call('installPlugin', 'non-existent-plugin');

    $component->assertDispatched('toast-show');
});

it('shows error when zip_url is missing', function (): void {
    $user = User::factory()->create();

    // Mock the HTTP response for the catalog URL without zip_url
    $catalogData = [
        'test-plugin' => [
            'name' => 'Test Plugin',
            'author' => ['name' => 'Test Author'],
            'author_bio' => ['description' => 'A test plugin'],
            'license' => 'MIT',
            'trmnlp' => [],
        ],
    ];

    $yamlContent = Yaml::dump($catalogData);

    Http::fake([
        config('app.catalog_url') => Http::response($yamlContent, 200),
    ]);

    $this->actingAs($user);

    Livewire::withoutLazyLoading();

    $component = Livewire::test('catalog.index');

    $component->call('installPlugin', 'test-plugin');

    $component->assertDispatched('toast-show');
});

it('can preview a plugin', function (): void {
    // Clear cache first to ensure fresh data
    Cache::forget('catalog_plugins');

    // Mock the HTTP response for the catalog URL
    $catalogData = [
        'test-plugin' => [
            'name' => 'Test Plugin',
            'author' => ['name' => 'Test Author', 'github' => 'testuser'],
            'author_bio' => [
                'description' => 'A test plugin description',
            ],
            'license' => 'MIT',
            'trmnlp' => [
                'zip_url' => 'https://example.com/plugin.zip',
            ],
            'byos' => [
                'byos_laravel' => [
                    'compatibility' => true,
                ],
            ],
            'logo_url' => 'https://example.com/logo.png',
            'screenshot_url' => 'https://example.com/screenshot.png',
        ],
    ];

    $yamlContent = Yaml::dump($catalogData);

    Http::fake([
        config('app.catalog_url') => Http::response($yamlContent, 200),
    ]);

    Livewire::withoutLazyLoading();

    Livewire::test('catalog.index')
        ->assertSee('Test Plugin')
        ->call('previewPlugin', 'test-plugin')
        ->assertSet('previewingPlugin', 'test-plugin')
        ->assertSet('previewData.name', 'Test Plugin')
        ->assertSee('Preview Test Plugin')
        ->assertSee('A test plugin description');
});

it('purifies catalog author bio html', function (): void {
    Cache::forget('catalog_plugins');

    $catalogData = [
        'test-plugin' => [
            'name' => 'Test Plugin',
            'author' => ['name' => 'Test Author', 'github' => 'testuser'],
            'author_bio' => [
                'description' => '<strong>Hello</strong> <script>alert(1)</script>',
            ],
            'license' => 'MIT',
            'trmnlp' => [
                'zip_url' => 'https://example.com/plugin.zip',
            ],
            'byos' => [
                'byos_laravel' => [
                    'compatibility' => true,
                ],
            ],
            'screenshot_url' => 'https://example.com/screenshot.png',
        ],
    ];

    Http::fake([
        config('app.catalog_url') => Http::response(Yaml::dump($catalogData), 200),
    ]);

    Livewire::withoutLazyLoading();

    Livewire::test('catalog.index')
        ->assertSet('catalogPlugins.test-plugin.description', '<strong>Hello</strong> ')
        ->assertSee('<strong>Hello</strong>', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->call('previewPlugin', 'test-plugin')
        ->assertSee('<strong>Hello</strong>', false);
});

it('shows the 3rd party warning until it is dismissed', function (): void {
    Http::fake([
        config('app.catalog_url') => Http::response('', 200),
    ]);

    $this->actingAs(User::factory()->create());

    Livewire::withoutLazyLoading();

    Livewire::test('catalog.index')
        ->assertSee('Recipes are not reviewed')
        ->call('dismissOssWarning')
        ->assertDontSee('Recipes are not reviewed');

    expect(app(CatalogSettings::class)->oss_3rd_party_warning_dismissed)->toBeTrue();

    $this->actingAs(User::factory()->create());

    Livewire::test('catalog.index')
        ->assertDontSee('Recipes are not reviewed');
});
