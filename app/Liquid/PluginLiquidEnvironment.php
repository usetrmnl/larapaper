<?php

namespace App\Liquid;

use App\Liquid\FileSystems\InlineTemplatesFileSystem;
use App\Liquid\Filters\Data;
use App\Liquid\Filters\Date;
use App\Liquid\Filters\Localization;
use App\Liquid\Filters\Numbers;
use App\Liquid\Filters\StringMarkup;
use App\Liquid\Filters\Uniqueness;
use App\Liquid\Tags\PluginRenderTag;
use App\Liquid\Tags\TemplateTag;
use Keepsuit\LaravelLiquid\Filters\DebugFilters;
use Keepsuit\LaravelLiquid\Filters\TranslatorFilters;
use Keepsuit\LaravelLiquid\Filters\UrlFilters;
use Keepsuit\Liquid\Environment;
use Keepsuit\Liquid\Extensions\StandardExtension;

/**
 * Builds the Liquid environment used for user-authored markup and polling templates.
 *
 * The Laravel Liquid extension is intentionally not loaded: its tags (session, csrf, error,
 * env, auth, guest, vite) expose request/session state to user templates. Only its filter
 * providers are registered. Partials are resolved from inline templates only, never from disk.
 */
class PluginLiquidEnvironment
{
    public static function make(?InlineTemplatesFileSystem $fileSystem = null): Environment
    {
        $environment = new Environment(
            fileSystem: $fileSystem ?? new InlineTemplatesFileSystem(),
            extensions: [new StandardExtension()],
        );

        $environment->filterRegistry->register(DebugFilters::class);
        $environment->filterRegistry->register(UrlFilters::class);
        $environment->filterRegistry->register(TranslatorFilters::class);

        $environment->filterRegistry->register(Data::class);
        $environment->filterRegistry->register(Date::class);
        $environment->filterRegistry->register(Localization::class);
        $environment->filterRegistry->register(Numbers::class);
        $environment->filterRegistry->register(StringMarkup::class);
        $environment->filterRegistry->register(Uniqueness::class);

        $environment->tagRegistry->register(TemplateTag::class);
        $environment->tagRegistry->register(PluginRenderTag::class);

        return $environment;
    }
}
