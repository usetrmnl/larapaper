<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use App\Services\PluginExportService;
use App\Services\PluginImportService;
use Illuminate\Http\UploadedFile;

function transformArchive(array $files): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'transform-archive-').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    return new UploadedFile($path, 'transform.zip', 'application/zip', null, true);
}

test('flat and nested transform archives import as inactive source', function (string $prefix, string $language, string $extension): void {
    $owner = User::factory()->create();
    $archive = transformArchive([
        $prefix.'settings.yml' => "name: Example\nstrategy: polling\npolling_url: https://example.test\nserverless_language: {$language}\ntransform_enabled: true\n",
        $prefix.'full.liquid' => '<div>{{ answer }}</div>',
        $prefix.'transform.'.$extension => 'fixture source',
    ]);
    $plugin = app(PluginImportService::class)->importFromZip($archive, $owner);
    expect($plugin->transform_code)->toBe('fixture source')->and($plugin->transform_language)->toBe($language)
        ->and($plugin->transform_enabled)->toBeFalse();
})->with([['', 'php', 'php'], ['src/', 'python', 'py'], ['src/', 'node', 'js']]);

test('an unambiguous transform file can declare its language by extension', function (): void {
    $archive = transformArchive(['settings.yml' => "name: Example\nstrategy: static\n", 'full.liquid' => '<div>Hi</div>', 'transform.php' => 'function run($input) { return $input; }']);
    $plugin = app(PluginImportService::class)->importFromZip($archive, User::factory()->create());
    expect($plugin->transform_language)->toBe('php')->and($plugin->transform_enabled)->toBeFalse();
});

test('ambiguous and missing transform files cannot silently lose source', function (array $extra, string $setting): void {
    $archive = transformArchive(['settings.yml' => "name: Example\nstrategy: static\n".$setting, 'full.liquid' => '<div>Hi</div>', ...$extra]);
    expect(fn () => app(PluginImportService::class)->importFromZip($archive, User::factory()->create()))
        ->toThrow(InvalidArgumentException::class);
})->with([
    [['transform.php' => 'php', 'transform.py' => 'python'], ''],
    [[], "serverless_language: php\n"],
]);

test('archive export and import preserve transform source without activating it', function (): void {
    $owner = User::factory()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'markup_language' => 'liquid', 'render_markup' => '<div>{{ n }}</div>',
        'transform_enabled' => true, 'transform_language' => 'php', 'transform_code' => 'function run($input) { return ["n"=>42]; }']);
    $response = app(PluginExportService::class)->exportToZip($plugin, $owner);
    $archive = new UploadedFile($response->getFile()->getPathname(), 'export.zip', 'application/zip', null, true);
    $imported = app(PluginImportService::class)->importFromZip($archive, User::factory()->create());
    expect($imported->transform_code)->toBe($plugin->transform_code)->and($imported->transform_language)->toBe('php')
        ->and($imported->transform_enabled)->toBeFalse();
});
