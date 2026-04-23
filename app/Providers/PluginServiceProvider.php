<?php

namespace App\Providers;

use App\Plugins\ImageWebhookPlugin;
use App\Plugins\PluginRegistry;
use App\Plugins\ScreenshotPlugin;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginRegistry::class, function (): PluginRegistry {
            $registry = new PluginRegistry;

            $registry->register(new ImageWebhookPlugin);
            $registry->register(new ScreenshotPlugin);

            return $registry;
        });
    }
}
