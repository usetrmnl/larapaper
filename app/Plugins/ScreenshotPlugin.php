<?php

namespace App\Plugins;

use App\Models\Plugin;
use App\Plugins\Enums\PluginOutput;
use Bnussbau\TrmnlPipeline\Stages\BrowserStage;
use RuntimeException;

/**
 * Native plugin that captures an external URL via Browsershot through the same
 * BrowserStage + ImageStage pipeline used for recipe HTML rendering.
 *
 * Configuration:
 *   - url (required) : public URL to capture
 */
class ScreenshotPlugin extends PluginHandler
{
    public const KEY = 'screenshot';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Screenshot';
    }

    public function description(): string
    {
        return 'Screenshot a web page and display it on your device';
    }

    public function icon(): string
    {
        return 'camera';
    }

    public function output(): PluginOutput
    {
        return PluginOutput::Image;
    }

    public function defaultAttributes(): array
    {
        return [
            'data_strategy' => 'static',
            'data_stale_minutes' => 60,
        ];
    }

    public function fields(): array
    {
        return [
            [
                'key' => 'url',
                'label' => 'URL',
                'type' => 'url',
                'help' => 'HTTPS or HTTP URL Browsershot will capture. Needs to be public or accessible from LaraPaper.',
                'required' => true,
            ],
        ];
    }

    public function configureBrowserStage(BrowserStage $browserStage, string $markup, Plugin $plugin): void
    {
        $url = mb_trim((string) ($plugin->configuration['url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException("Plugin {$plugin->id}: missing 'url' configuration for image plugin.");
        }

        $browserStage->url($url);
    }
}
