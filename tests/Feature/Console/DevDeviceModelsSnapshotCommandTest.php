<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

test('dev device models snapshot command writes catalog json files', function (): void {
    Http::preventStrayRequests();

    $baseUrl = config('services.trmnl.base_url');
    $directory = sys_get_temp_dir().'/device-catalog-'.uniqid();

    Http::fake([
        $baseUrl.'/api/palettes' => Http::response([
            'data' => [
                ['id' => 'gray-16', 'name' => '16 Grays'],
            ],
        ], 200),
        $baseUrl.'/api/models' => Http::response([
            'data' => [
                ['name' => 'v2', 'default_palette_id' => 'gray-16'],
            ],
        ], 200),
    ]);

    $this->artisan('dev:device-models:snapshot', ['--path' => $directory])
        ->expectsOutput('Updated device catalog snapshots in '.$directory.'.')
        ->assertExitCode(0);

    $models = json_decode((string) file_get_contents($directory.'/models.json'), true);
    $palettes = json_decode((string) file_get_contents($directory.'/palettes.json'), true);

    expect($models['data'][0]['name'])->toBe('v2')
        ->and($models['data'][0]['default_palette_id'])->toBe('gray-16')
        ->and($palettes['data'][0]['id'])->toBe('gray-16');

    File::deleteDirectory($directory);
});

test('dev device models snapshot command refuses to run in production', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $directory = sys_get_temp_dir().'/device-catalog-'.uniqid();
    mkdir($directory);

    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('dev:device-models:snapshot', ['--path' => $directory])
        ->expectsOutput('This command can only be run in the local environment.')
        ->assertExitCode(1);

    Http::assertNothingSent();

    expect(file_exists($directory.'/models.json'))->toBeFalse()
        ->and(file_exists($directory.'/palettes.json'))->toBeFalse();

    rmdir($directory);
});
