<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Database\Seeders\ExampleRecipesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.puppeteer_window_size_strategy', 'v2');
    Config::set('services.trmnl.base_url', 'https://trmnl.test');
});

test('resolvedFrameworkVersion returns config default when framework_version is null', function (): void {
    Config::set('trmnl-blade.framework_version', '3.1.1');

    $plugin = Plugin::factory()->create([
        'framework_version' => null,
    ]);

    expect($plugin->resolvedFrameworkVersion())->toBe('3.1.1');
});

test('resolvedFrameworkVersion returns pinned version when set', function (): void {
    Config::set('trmnl-blade.framework_version', '3.1.1');

    $plugin = Plugin::factory()->create([
        'framework_version' => '2.3.7',
    ]);

    expect($plugin->resolvedFrameworkVersion())->toBe('2.3.7');
});

test('validateFrameworkVersion rejects versions below 2.0.0', function (): void {
    $errors = [];
    $fail = function (string $message) use (&$errors): void {
        $errors[] = $message;
    };

    Plugin::validateFrameworkVersion('1.2.3', $fail);

    expect($errors)->toContain('Framework version must be at least 2.0.0.');
});

test('validateFrameworkVersion rejects versions 4.0.0 and above', function (): void {
    $errors = [];
    $fail = function (string $message) use (&$errors): void {
        $errors[] = $message;
    };

    Plugin::validateFrameworkVersion('4.0.0', $fail);

    expect($errors)->toContain('Framework version must be lower than 4.0.0.');
});

test('validateFrameworkVersion accepts versions from 2.0.0 up to 3.x.x', function (): void {
    $errors = [];
    $fail = function (string $message) use (&$errors): void {
        $errors[] = $message;
    };

    Plugin::validateFrameworkVersion('2.3.7', $fail);
    Plugin::validateFrameworkVersion('3.1.1', $fail);

    expect($errors)->toBeEmpty();
});

test('migration backfill sets legacy plugins to 2.3.7 but skips example recipe uuids', function (): void {
    $exampleUuid = ExampleRecipesSeeder::exampleUuids()[0];

    $legacy = Plugin::factory()->create([
        'uuid' => 'legacy-user-recipe-uuid',
        'framework_version' => null,
    ]);

    $example = Plugin::factory()->create([
        'uuid' => $exampleUuid,
        'framework_version' => null,
    ]);

    Plugin::query()
        ->whereNotIn('uuid', ExampleRecipesSeeder::exampleUuids())
        ->update(['framework_version' => '2.3.7']);

    expect($legacy->fresh()->framework_version)->toBe('2.3.7')
        ->and($example->fresh()->framework_version)->toBeNull();
});

test('example recipes seeder sets framework_version to null', function (): void {
    $user = User::factory()->create();

    (new ExampleRecipesSeeder())->run($user->id);

    foreach (ExampleRecipesSeeder::exampleUuids() as $uuid) {
        $plugin = Plugin::where('uuid', $uuid)->first();

        expect($plugin)->not->toBeNull()
            ->and($plugin->framework_version)->toBeNull();
    }
});

test('plugin render includes pinned framework version in css url', function (): void {
    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'blade',
        'render_markup' => '<div>Hello</div>',
        'framework_version' => '2.3.7',
    ]);

    $html = $plugin->render();

    expect($html)->toContain('https://trmnl.test/css/2.3.7/plugins.css')
        ->and($html)->toContain('https://trmnl.test/js/2.3.7/plugins.js');
});

test('plugin render uses global default framework version when null', function (): void {
    Config::set('trmnl-blade.framework_version', '3.1.1');

    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'blade',
        'render_markup' => '<div>Hello</div>',
        'framework_version' => null,
    ]);

    $html = $plugin->render();

    expect($html)->toContain('https://trmnl.test/css/3.1.1/plugins.css')
        ->and($html)->toContain('https://trmnl.test/js/3.1.1/plugins.js');
});
