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

test('flat and nested transform archives import the source with the language of its file extension', function (string $prefix, string $extension, string $language): void {
    $archive = transformArchive([
        $prefix.'settings.yml' => "name: Example\nstrategy: polling\npolling_url: https://example.test\n",
        $prefix.'full.liquid' => '<div>{{ answer }}</div>',
        $prefix.'transform.'.$extension => 'fixture source',
    ]);
    $plugin = app(PluginImportService::class)->importFromZip($archive, User::factory()->create());
    expect($plugin->transform_code)->toBe('fixture source')->and($plugin->transform_language)->toBe($language);
})->with([['', 'php', 'php'], ['src/', 'py', 'python'], ['src/', 'js', 'node'], ['', 'rb', 'ruby']]);

test('the file extension wins over a conflicting serverless_language', function (): void {
    $archive = transformArchive(['settings.yml' => "name: Example\nstrategy: polling\nserverless_language: python\n", 'full.liquid' => '<div>Hi</div>', 'transform.js' => 'function run(input) { return input; }']);
    $plugin = app(PluginImportService::class)->importFromZip($archive, User::factory()->create());
    expect($plugin->transform_language)->toBe('node');
});

test('serverless_language picks one of several transform files', function (): void {
    $archive = transformArchive(['settings.yml' => "name: Example\nstrategy: polling\nserverless_language: python\n", 'full.liquid' => '<div>Hi</div>', 'transform.php' => 'php', 'transform.py' => 'python']);
    $plugin = app(PluginImportService::class)->importFromZip($archive, User::factory()->create());
    expect($plugin->transform_language)->toBe('python')->and($plugin->transform_code)->toBe('python');
});

test('several transform files without serverless_language are rejected', function (): void {
    $archive = transformArchive(['settings.yml' => "name: Example\nstrategy: static\n", 'full.liquid' => '<div>Hi</div>', 'transform.php' => 'php', 'transform.py' => 'python']);
    expect(fn () => app(PluginImportService::class)->importFromZip($archive, User::factory()->create()))
        ->toThrow(InvalidArgumentException::class);
});

test('archive export writes the extension of the transform language and import restores it', function (string $language, string $extension): void {
    $owner = User::factory()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'markup_language' => 'liquid', 'render_markup' => '<div>{{ n }}</div>',
        'transform_language' => $language, 'transform_code' => 'fixture source']);
    $response = app(PluginExportService::class)->exportToZip($plugin, $owner);

    $zip = new ZipArchive;
    $zip->open($response->getFile()->getPathname());
    expect($zip->locateName('transform.'.$extension))->not->toBeFalse();
    $zip->close();

    $archive = new UploadedFile($response->getFile()->getPathname(), 'export.zip', 'application/zip', null, true);
    $imported = app(PluginImportService::class)->importFromZip($archive, User::factory()->create());
    expect($imported->transform_code)->toBe('fixture source')->and($imported->transform_language)->toBe($language);
})->with([['php', 'php'], ['node', 'js'], ['python', 'py'], ['ruby', 'rb']]);

test('removed transforms are not exported', function (): void {
    $owner = User::factory()->create();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'transform_language' => null, 'transform_code' => 'kept source']);
    $response = app(PluginExportService::class)->exportToZip($plugin, $owner);

    $zip = new ZipArchive;
    $zip->open($response->getFile()->getPathname());
    expect($zip->locateName('transform.php'))->toBeFalse()->and($zip->getFromName('settings.yml'))->not->toContain('serverless_language');
    $zip->close();
});
