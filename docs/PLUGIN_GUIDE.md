# Native plugins developer guide

This document describes the **native plugin** system introduced alongside the registry-based handlers (`image_webhook`, `screenshot`, …). It complements **recipe** plugins (`plugin_type = recipe`), which use Liquid/Blade markup, polling, and the existing recipe editor.

## Concepts

| Piece | Role |
|--------|------|
| `PluginHandler` | Abstract contract: metadata, optional webhook handling, optional `produce()` for the display pipeline, configuration UI hooks. |
| `PluginRegistry` | Singleton map of `key()` → handler instance. Built in `PluginServiceProvider`. |
| `Plugin` model | `plugin_type` stores the handler key. `Plugin::handler()` resolves the handler from the registry (returns `null` for unregistered types such as `recipe`). |
| `PluginOutput` | Tells `GenerateScreenJob` how far the plugin’s asset is through the e-paper pipeline. |
| `PluginContent` | Immutable result type for `produce()` (HTML bytes, raw image, or processed image UUID). Not all plugins use `produce()` yet. |
| `PluginActionController` | Single HTTP entrypoint for plugin webhooks; delegates to the handler for the bound plugin’s `plugin_type`. |

Legacy **`PluginImageWebhookController`** was removed; the same behavior is handled generically by `PluginActionController`.

## Registration

1. Implement a concrete class extending `App\Plugins\PluginHandler`.
2. Register it in `App\Providers\PluginServiceProvider` inside the `PluginRegistry` singleton closure.

Handlers must return a **stable string** from `key()`; that value is persisted on `plugins.plugin_type` and used for routes, webhooks, and the job pipeline.

## `PluginHandler` API (summary)

Implement or override as needed:

- **`key()`**, **`name()`**, **`description()`**, **`icon()`** — Shown on the plugins index and type listing (`flux:` icon name).
- **`output(): PluginOutput`** — Default `Html`. Drives `GenerateScreenJob` (see below).
- **`defaultAttributes(): array`** — Merged into `Plugin::create()` for new instances.
- **`fields(): array`** — Schema for the shared **type instance** Livewire page (`plugins.type-instance`). Each field is persisted under `Plugin::$configuration['key']`.
- **`settingsPartial(): ?string`** — Optional Blade view (e.g. `plugins.image-webhook.settings`) appended to the instance page; receives `$plugin`.
- **`isDataStale(Plugin $plugin): ?bool`** — Return `null` to use the model’s default stale logic; return a boolean to override.
- **`handleWebhook(Request, Plugin): JsonResponse|array`** — Called for HTTP webhooks. Default is 404 JSON.
- **`produce(Plugin, Device|DeviceModel|null): PluginContent`** — Intended for display generation; default throws. Override when the pipeline should call into the handler (see **Pipeline**).

`hasInstances()` is currently `true` for all handlers; the plugins index only lists registry entries that pass this check (plus static “Markup” / “API” doc tiles).

## Display pipeline and `PluginOutput`

`App\Jobs\GenerateScreenJob` inspects `$plugin->handler()?->output()`:

| `PluginOutput` | Meaning in the job |
|----------------|-------------------|
| **`ProcessedImage`** | The plugin already stores a device-ready file under `images/generated/{uuid}.{ext}` on the public disk. The job copies `plugins.current_image` to the device’s `current_screen_image` and **skips** Browser/Image pipeline stages. |
| **`Image`** | Same **BrowserStage + ImageStage** path as HTML recipes, but the handler’s **`configureBrowserStage()`** hook binds the stage (e.g. **screenshot** calls `BrowserStage::url()` from `Plugin::$configuration` instead of `html()`). `GenerateScreenJob` uses `ImageGenerationService::generateImage(..., $plugin)`. |
| **`Html`** (default) | Markup is run through `ImageGenerationService::generateImage()` (full **BrowserStage + ImageStage** pipeline), then `plugins.current_image` / metadata are updated as today for recipes. |

**Image webhook** sets `output()` to `ProcessedImage` and updates `current_image` inside `handleWebhook()` when a client POSTs an image; it does not rely on `produce()`.

**Screenshot** declares **`Image`**, defines `url` in `fields()`, and overrides **`configureBrowserStage()`** to read that URL. Per-device cache invalidation uses `current_image_metadata` like recipes (`ImageGenerationService::resetIfNotCacheable`).

## Webhook HTTP API

Public routes (UUID route-model binding on `Plugin`):

| Method | Path | Name | Notes |
|--------|------|------|--------|
| `POST` | `/api/plugins/{plugin:uuid}/webhook` | `api.plugins.webhook` | Preferred. |
| `POST` | `/api/plugin_settings/{plugin:uuid}/image` | `api.plugin_settings.image` | Backward compatibility for older image-webhook clients; same controller. |

`PluginActionController` loads `$registry->get($plugin->plugin_type)` and returns **400** if no handler is registered (e.g. a `recipe` row hit by mistake).

### Image webhook behavior

Handler key: `image_webhook` (`ImageWebhookPlugin::KEY`).

- Accepts uploads via `App\Actions\Api\ProcessPluginImageUpload` (multipart `image`, raw body with `image/png` or `image/bmp`, base64 / data URI, etc.—see `tests/Feature/Api/ImageWebhookTest.php`).
- Persists **PNG or BMP** only to `storage/app/public/images/generated/{uuid}.{ext}` and sets `plugins.current_image` to that UUID.
- Triggers `ImageGenerationService::cleanupFolder()` after success.

## UI routes (authenticated)

From `routes/web.php`:

- `plugins.index` — Lists doc tiles + registered native types + user **recipe** plugins (native instances are managed per type).
- `plugins.type` / `plugins.type-instance` — `{type}` is the handler `key()`; instance page uses `fields()`, `settingsPartial()`, and shared Livewire views under `resources/views/livewire/plugins/`.

## Adding a new native plugin (checklist)

1. Add `app/Plugins/YourPlugin.php` extending `PluginHandler` with a unique `key()` (e.g. `my_feed`).
2. Register `new YourPlugin` in `PluginServiceProvider`.
3. Decide **`PluginOutput`** and implement **`handleWebhook`** and/or **`produce()`** + **`PluginContent`** factories as the feature requires.
4. If users need form fields, implement **`fields()`** and/or **`settingsPartial()`** Blade under `resources/views/plugins/...`.
5. Add a factory state or migration path so new `Plugin` rows get `plugin_type` = your key (and any **`defaultAttributes()`**).
6. Add Pest coverage under `tests/Feature/Plugins/` and/or `tests/Feature/Api/` for webhooks and pages.

## Related tests

- `tests/Feature/Plugins/PluginRegistryTest.php` — Registry wiring.
- `tests/Feature/Plugins/TypeInstancePageTest.php` — Type / instance Livewire pages.
- `tests/Feature/Api/ImageWebhookTest.php` — Webhook upload formats and BC route.

## File reference (current tree)

| File | Purpose |
|------|---------|
| `app/Plugins/PluginHandler.php` | Contract |
| `app/Plugins/PluginRegistry.php` | Registry |
| `app/Plugins/PluginContent.php` | `produce()` result |
| `app/Plugins/Enums/PluginOutput.php` | Pipeline stage enum |
| `app/Plugins/ImageWebhookPlugin.php` | Processed-image webhook plugin |
| `app/Plugins/ScreenshotPlugin.php` | Screenshot URL → BrowserStage + `PluginOutput::Image` |
| `app/Providers/PluginServiceProvider.php` | Registers handlers |
| `app/Http/Controllers/Api/PluginActionController.php` | Generic webhook controller |
| `routes/api.php` | Webhook routes |
| `app/Jobs/GenerateScreenJob.php` | Branching on `PluginOutput` |
