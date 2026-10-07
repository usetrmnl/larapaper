<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Plugins\Enums\PluginOutput;
use App\Plugins\PluginContent;
use App\Plugins\PluginHandler;
use Bnussbau\EpaperPipeline\Stages\BrowserStage;
use Illuminate\Http\Request;

test('default produce throws runtime exception', function (): void {
    $handler = new class extends PluginHandler
    {
        public function key(): string
        {
            return 'stub';
        }

        public function name(): string
        {
            return 'Stub';
        }

        public function description(): string
        {
            return 'Stub handler';
        }

        public function icon(): string
        {
            return 'cube';
        }
    };

    $plugin = Plugin::factory()->make();

    expect(fn (): PluginContent => $handler->produce($plugin))
        ->toThrow(RuntimeException::class, 'does not implement produce()');
});

test('default handle webhook returns 404 json', function (): void {
    $handler = new class extends PluginHandler
    {
        public function key(): string
        {
            return 'stub';
        }

        public function name(): string
        {
            return 'Stub';
        }

        public function description(): string
        {
            return 'Stub handler';
        }

        public function icon(): string
        {
            return 'cube';
        }
    };

    $response = $handler->handleWebhook(Request::create('/', 'GET'), Plugin::factory()->make());

    expect($response->getStatusCode())->toBe(404);
});

test('configure browser stage keeps the file origin', function (): void {
    config(['app.url' => 'https://larapaper.example.com']);
    $handler = new class extends PluginHandler
    {
        public function key(): string
        {
            return 'stub';
        }

        public function name(): string
        {
            return 'Stub';
        }

        public function description(): string
        {
            return 'Stub handler';
        }

        public function icon(): string
        {
            return 'cube';
        }
    };

    $stage = new BrowserStage;
    $handler->configureBrowserStage($stage, '<main>x</main>', Plugin::factory()->make());

    $html = new ReflectionClass(BrowserStage::class)->getProperty('html');

    $options = new ReflectionClass(BrowserStage::class)->getProperty('browsershotOptions');

    expect([
        'html' => $html->getValue($stage),
        'options' => $options->getValue($stage),
    ])->toBe([
        'html' => '<main>x</main>',
        'options' => [],
    ]);
});

test('default output is html', function (): void {
    $handler = new class extends PluginHandler
    {
        public function key(): string
        {
            return 'stub';
        }

        public function name(): string
        {
            return 'Stub';
        }

        public function description(): string
        {
            return 'Stub handler';
        }

        public function icon(): string
        {
            return 'cube';
        }
    };

    expect($handler->output())->toBe(PluginOutput::Html);
});

test('screenshot browser stage retains the external page origin', function (): void {
    config(['app.url' => 'https://larapaper.example.com']);

    $stage = new BrowserStage;
    (new App\Plugins\ScreenshotPlugin)->configureBrowserStage($stage, '', Plugin::factory()->make([
        'configuration' => ['url' => 'https://example.com/page'],
    ]));

    $reflection = new ReflectionClass(BrowserStage::class);

    expect([
        'url' => $reflection->getProperty('url')->getValue($stage),
        'options' => $reflection->getProperty('browsershotOptions')->getValue($stage),
    ])->toBe([
        'url' => 'https://example.com/page',
        'options' => [],
    ]);
});
