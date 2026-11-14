<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use App\Services\PluginExportService;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    config([
        'trmnl-blade.highcharts_js_url' => 'https://assets.example.com/highcharts.js',
        'trmnl-blade.chartkick_js_url' => 'https://assets.example.com/chartkick.js',
    ]);
});

test('bundled and copied pollen recipes use configured chart scripts', function (bool $copied, bool $standalone): void {
    $hourly = ['time' => array_fill(0, 48, '2026-10-04T12:00')];
    $current = [];

    foreach (['birch', 'grass', 'alder', 'mugwort', 'ragweed'] as $pollen) {
        $hourly[$pollen.'_pollen'] = array_fill(0, 48, 0);
        $current[$pollen.'_pollen'] = 0;
    }

    $plugin = Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'render_markup' => null,
        'render_markup_view' => 'recipes.pollen-forecast-eu',
        'data_payload' => ['current' => $current, 'hourly' => $hourly],
    ]);

    if ($copied) {
        $plugin = $plugin->duplicate();
    }

    $html = $plugin->render(standalone: $standalone);
    preg_match_all('/<script src="([^\"]*(?:highcharts|chartkick)[^\"]*)"><\/script>/', $html, $matches);

    expect($matches[1])->toBe([
        'https://assets.example.com/highcharts.js',
        'https://assets.example.com/chartkick.js',
    ]);
})->with([
    'stock standalone' => [false, true],
    'stock partial' => [false, false],
    'copy standalone' => [true, true],
    'copy partial' => [true, false],
]);

test('exported pollen recipes retain chart scripts without an asset context', function (bool $copied): void {
    $plugin = Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'markup_language' => 'liquid',
        'render_markup' => null,
        'render_markup_view' => 'recipes.pollen-forecast-eu',
    ]);

    if ($copied) {
        $plugin = $plugin->duplicate();
    }

    $response = app(PluginExportService::class)->exportToZip($plugin, User::factory()->make());
    $zip = new ZipArchive;
    expect($zip->open($response->getFile()->getPathname()))->toBeTrue();
    $markup = $zip->getFromName('full.liquid');
    $zip->close();
    expect($markup)->toBeString();

    $scripts = implode("\n", array_slice(explode("\n", $markup), 0, 2));
    $environment = app('liquid.environment');
    $html = $environment->parseString($scripts)->render($environment->newRenderContext(data: [
        'trmnl' => ['user' => [], 'device' => [], 'system' => [], 'plugin_settings' => []],
    ]));

    expect($html)->toBe(
        '<script src="https://trmnl.com/js/highcharts/12.3.0/highcharts.js"></script>'."\n".
        '<script src="https://trmnl.com/js/chartkick/5.0.1/chartkick.min.js"></script>'
    );
})->with([
    'stock' => [false],
    'copy' => [true],
]);

test('inline markup receives the configured chart assets', function (string $language, string $markup): void {
    $plugin = Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'markup_language' => $language,
        'render_markup' => $markup,
    ]);

    expect($plugin->render(standalone: false))->toBe(
        '<script src="https://assets.example.com/highcharts.js"></script><script src="https://assets.example.com/chartkick.js"></script>'
    );
})->with([
    'liquid' => ['liquid', '<script src="{{ trmnl.assets.highcharts_js_url }}"></script><script src="{{ trmnl.assets.chartkick_js_url }}"></script>'],
    'blade' => ['blade', '<script src="{{ $trmnl[\'assets\'][\'highcharts_js_url\'] }}"></script><script src="{{ $trmnl[\'assets\'][\'chartkick_js_url\'] }}"></script>'],
]);

test('external Liquid renderer receives the configured chart assets', function (): void {
    config([
        'services.trmnl.liquid_enabled' => true,
        'services.trmnl.liquid_path' => '/usr/local/bin/trmnl-liquid-cli',
    ]);
    Process::fake(['*' => Process::result(output: 'rendered', exitCode: 0)]);

    $plugin = Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'markup_language' => 'liquid',
        'preferred_renderer' => 'trmnl-liquid',
        'render_markup' => '{{ trmnl.assets.chartkick_js_url }}',
    ]);

    expect($plugin->render(standalone: false))->toBe("rendered\n");

    Process::assertRan(function ($process): bool {
        $context = json_decode($process->command[4], true, flags: JSON_THROW_ON_ERROR);

        return ($context['trmnl']['assets'] ?? null) === [
            'highcharts_js_url' => 'https://assets.example.com/highcharts.js',
            'chartkick_js_url' => 'https://assets.example.com/chartkick.js',
        ];
    });
});
