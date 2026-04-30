<?php

declare(strict_types=1);

use App\Plugins\PluginHandler;
use App\Plugins\PluginRegistry;

test('registry registers handlers and resolves by key', function (): void {
    $handler = new class extends PluginHandler
    {
        public function key(): string
        {
            return 'alpha';
        }

        public function name(): string
        {
            return 'Alpha';
        }

        public function description(): string
        {
            return 'Alpha plugin';
        }

        public function icon(): string
        {
            return 'star';
        }
    };

    $registry = new PluginRegistry;
    $registry->register($handler);

    expect($registry->has('alpha'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse()
        ->and($registry->get('alpha'))->toBe($handler)
        ->and($registry->get('missing'))->toBeNull()
        ->and($registry->all())->toBe(['alpha' => $handler]);
});
