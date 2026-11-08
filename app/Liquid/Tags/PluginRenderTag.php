<?php

declare(strict_types=1);

namespace App\Liquid\Tags;

use Keepsuit\Liquid\Render\RenderContext;
use Keepsuit\Liquid\Support\MissingValue;
use Keepsuit\Liquid\Tags\RenderTag;

/**
 * Render tag that injects plugin context (trmnl, size, data, config) into partials
 * so shared templates can use variables like trmnl.user.name without passing them explicitly.
 */
class PluginRenderTag extends RenderTag
{
    /**
     * Root-level keys from the plugin render context that should be available in partials.
     *
     * @var list<string>
     */
    private const array PARENT_CONTEXT_KEYS = ['trmnl', 'size', 'data', 'config'];

    protected function buildPartialContext(RenderContext $rootContext, string $templateName, array $variables = []): RenderContext
    {
        $partialContext = $rootContext->newIsolatedSubContext($templateName);

        foreach (self::PARENT_CONTEXT_KEYS as $key) {
            $value = $rootContext->get($key);
            if ($value !== null && ! $value instanceof MissingValue) {
                $partialContext->set($key, $value);
            }
        }

        $partialContext->merge($variables);

        foreach ($this->attributes as $key => $value) {
            $partialContext->set($key, $rootContext->evaluate($value));
        }

        return $partialContext;
    }
}
