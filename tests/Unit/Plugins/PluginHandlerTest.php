<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Plugins\Enums\PluginOutput;
use App\Plugins\PluginHandler;
use Bnussbau\TrmnlPipeline\Stages\BrowserStage;
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

    expect(fn () => $handler->produce($plugin))
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

test('configure browser stage sets html from markup', function (): void {
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

    $html = (new ReflectionClass(BrowserStage::class))->getProperty('html');
    $html->setAccessible(true);

    expect($html->getValue($stage))->toBe('<main>x</main>');
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
