<?php

// @php-cs-fixer-ignore final_public_method_for_abstract_class

namespace App\Plugins;

use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Plugins\Enums\PluginOutput;
use Bnussbau\TrmnlPipeline\Stages\BrowserStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Contract for a native plugin type.
 *
 * Plugins register themselves with the PluginRegistry via a service provider.
 * The key() is persisted in plugins.plugin_type and drives:
 *   - which tile shows up on the plugins index page,
 *   - which listing/instance Livewire views are rendered,
 *   - which handler a webhook POST resolves to,
 *   - how GenerateScreenJob / ImageGenerationService treat produce() output,
 *   - optional BrowserStage binding via configureBrowserStage().
 */
abstract class PluginHandler
{
    /** Unique identifier stored in plugins.plugin_type (e.g. 'image_webhook'). */
    abstract public function key(): string;

    /** Human-readable label shown on the plugins index tile and create modal. */
    abstract public function name(): string;

    /** Short description rendered under the heading on create/list pages. */
    abstract public function description(): string;

    /** Flux icon name used on the plugins index tile. */
    abstract public function icon(): string;

    /**
     * Does this plugin produce instance-backed content (tied to a Plugin row)?
     *
     * Docs-only tiles (like 'markup' or 'api' reference pages) return false
     * and are linked directly to their own static route via listRoute().
     */
    final public function hasInstances(): bool
    {
        return true;
    }

    /**
     * Tells the pipeline how far through the image stages the output has come.
     */
    public function output(): PluginOutput
    {
        return PluginOutput::Html;
    }

    /**
     * Attributes merged into Plugin::create() when a new instance is added.
     *
     * @return array<string, mixed>
     */
    public function defaultAttributes(): array
    {
        return [];
    }

    /**
     * Schema-driven inputs rendered by the shared instance page.
     *
     * Each entry: ['key' => string, 'label' => string, 'type' => 'text'|'number'|'textarea', 'help' => ?string].
     * Values are persisted into Plugin::$configuration (JSON) under the given key.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Optional Blade view rendered at the bottom of the instance page for custom UI.
     *
     * Receives the $plugin variable. Return null to skip.
     */
    public function settingsPartial(): ?string
    {
        return null;
    }

    /**
     * Override the default Plugin::isDataStale() logic. Return null to defer.
     */
    public function isDataStale(Plugin $plugin): ?bool
    {
        return null;
    }

    /**
     * Bind BrowserStage to the document source before the TRMNL pipeline runs.
     *
     * Default: render the provided HTML markup. Native plugins may override
     * (e.g. screenshot uses configuration URL and calls {@see BrowserStage::url()}).
     */
    public function configureBrowserStage(BrowserStage $browserStage, string $markup, Plugin $plugin): void
    {
        $browserStage->html($markup);
    }

    /**
     * Handle an inbound POST to /api/plugins/{plugin:uuid}/webhook.
     *
     * Return either a JsonResponse or an array payload (converted to JSON).
     * Default: 404.
     */
    public function handleWebhook(Request $request, Plugin $plugin): JsonResponse|array
    {
        return new JsonResponse(['error' => 'Plugin does not accept webhooks'], 404);
    }

    /**
     * Produce content for the device display pipeline.
     *
     * Called from GenerateScreenJob / display routes. The returned PluginContent's
     * $type must match $this->output().
     */
    public function produce(Plugin $plugin, Device|DeviceModel|null $context = null): PluginContent
    {
        throw new RuntimeException(sprintf(
            'Plugin handler [%s] does not implement produce().',
            static::class,
        ));
    }
}
