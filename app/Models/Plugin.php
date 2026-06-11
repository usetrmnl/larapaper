<?php

namespace App\Models;

use App\Liquid\Filters\StandardFilters;
use App\Liquid\PluginLiquidEnvironment;
use App\Plugins\PluginHandler;
use App\Plugins\PluginRegistry;
use App\Services\Plugin\Parsers\ResponseParserRegistry;
use App\Services\Plugin\Transform\TransformException;
use App\Services\Plugin\Transform\TransformService;
use App\Services\PluginImportService;
use App\Support\UserBladeExecution;
use Carbon\Carbon;
use Closure;
use DateTimeInterface;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Keepsuit\Liquid\Exceptions\LiquidException;
use Stevebauman\Purify\Facades\Purify;
use Symfony\Component\Yaml\Yaml;

class Plugin extends Model
{
    use HasFactory;

    /** Maximum length of the stored transform error (message plus script output). */
    private const MAX_TRANSFORM_ERROR_LENGTH = 8192;

    protected $guarded = ['id'];

    protected $casts = [
        'data_payload' => 'json',
        'data_payload_updated_at' => 'datetime',
        'is_native' => 'boolean',
        'markup_language' => 'string',
        'configuration' => 'json',
        'configuration_template' => 'json',
        'no_bleed' => 'boolean',
        'dark_mode' => 'boolean',
        'preferred_renderer' => 'string',
        'framework_version' => 'string',
        'plugin_type' => 'string',
        'alias' => 'boolean',
        'current_image_metadata' => 'array',
        'transform_language' => 'string',
        'transform_failed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model): void {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid();
            }
        });

        static::updating(function ($model): void {
            // Reset image cache when any markup or render-affecting settings change
            if ($model->isDirty([
                'render_markup',
                'render_markup_half_horizontal',
                'render_markup_half_vertical',
                'render_markup_quadrant',
                'render_markup_shared',
                'render_markup_view',
                'markup_language',
                'no_bleed',
                'dark_mode',
                'configuration',
                'configuration_template',
                'data_strategy',
                'polling_url',
                'polling_verb',
                'polling_header',
                'polling_body',
                'data_payload',
                'preferred_renderer',
                'framework_version',
                'transform_code',
                'transform_language',
            ])) {
                $model->current_image = null;
                $model->current_image_metadata = null;
            }
        });

        // Sanitize configuration template on save
        static::saving(function ($model): void {
            $model->sanitizeTemplate();
        });

        static::deleting(function (Plugin $model): void {
            PlaylistItem::query()
                ->whereJsonContains('mashup->plugin_ids', $model->id)
                ->delete();
        });
    }

    public const CUSTOM_FIELDS_KEY = 'custom_fields';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Clear the cached generated image UUID and metadata so the next render can regenerate.
     */
    public function clearCurrentImage(): void
    {
        $this->update([
            'current_image' => null,
            'current_image_metadata' => null,
        ]);
    }

    public function resolvedFrameworkVersion(): string
    {
        return $this->framework_version ?? config('trmnl-blade.framework_version');
    }

    public static function validateFrameworkVersion(mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! preg_match('/^\d+\.\d+\.\d+$/', $value)) {
            $fail('Framework version must be in X.Y.Z format.');

            return;
        }

        if (version_compare($value, '2.0.0', '<')) {
            $fail('Framework version must be at least 2.0.0.');

            return;
        }

        if (version_compare($value, '4.0.0', '>=')) {
            $fail('Framework version must be lower than 4.0.0.');
        }
    }

    /**
     * YAML for the custom_fields editor
     */
    public function getCustomFieldsEditorYaml(): string
    {
        $template = $this->configuration_template;
        $list = $template[self::CUSTOM_FIELDS_KEY] ?? null;
        if ($list === null || $list === []) {
            return '';
        }

        return Yaml::dump($list, 4, 2);
    }

    /**
     * Parse editor YAML and return configuration_template for DB (custom_fields key). Returns null when empty.
     */
    public static function configurationTemplateFromCustomFieldsYaml(string $yaml, ?array $existingTemplate): ?array
    {
        $list = $yaml !== '' ? Yaml::parse($yaml) : [];
        if ($list === null || (is_array($list) && $list === [])) {
            return null;
        }

        $template = $existingTemplate ?? [];
        $template[self::CUSTOM_FIELDS_KEY] = is_array($list) ? $list : [];

        return $template;
    }

    /**
     * Validate that each custom field entry has field_type and name. For use with parsed editor YAML.
     *
     * @param  array<int, array<string, mixed>>  $list
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validateCustomFieldsList(array $list): void
    {
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['custom_fields' => $list],
            [
                'custom_fields' => ['required', 'array'],
                'custom_fields.*.field_type' => ['required', 'string'],
                'custom_fields.*.name' => ['required', 'string'],
            ],
            [
                'custom_fields.*.field_type.required' => 'Each custom field must have a field_type.',
                'custom_fields.*.name.required' => 'Each custom field must have a name.',
            ]
        );

        $validator->validate();
    }

    // sanitize configuration template descriptions and help texts (since they allow HTML rendering)
    protected function sanitizeTemplate(): void
    {
        $template = $this->configuration_template;

        if (isset($template['custom_fields']) && is_array($template['custom_fields'])) {
            foreach ($template['custom_fields'] as &$field) {
                if (isset($field['description'])) {
                    $field['description'] = Purify::clean($field['description']);
                }
                if (isset($field['help_text'])) {
                    $field['help_text'] = Purify::clean($field['help_text']);
                }
            }
            unset($field);

            $this->configuration_template = $template;
        }
    }

    public function hasMissingRequiredConfigurationFields(): bool
    {
        if (! isset($this->configuration_template['custom_fields']) || empty($this->configuration_template['custom_fields'])) {
            return false;
        }

        foreach ($this->configuration_template['custom_fields'] as $field) {
            // Skip fields as they are informational only
            if ($field['field_type'] === 'author_bio') {
                continue;
            }

            if ($field['field_type'] === 'copyable') {
                continue;
            }

            if ($field['field_type'] === 'copyable_webhook_url') {
                continue;
            }

            $fieldKey = $field['keyname'] ?? $field['key'] ?? $field['name'];

            // Check if field is required (not marked as optional)
            $isRequired = ! isset($field['optional']) || $field['optional'] !== true;

            if ($isRequired) {
                $currentValue = $this->configuration[$fieldKey] ?? null;

                // If the field has a default value and no current value is set, it's not missing
                if ((in_array($currentValue, [null, '', []], true)) && ! isset($field['default'])) {
                    return true; // Found a required field that is not set and has no default
                }
            }
        }

        return false; // All required fields are set
    }

    /**
     * Resolve the registered PluginHandler for this plugin's plugin_type, if any.
     *
     * Returns null for 'recipe' and any unregistered types.
     */
    public function handler(): ?PluginHandler
    {
        if (empty($this->plugin_type)) {
            return null;
        }

        return App::make(PluginRegistry::class)->get($this->plugin_type);
    }

    /**
     * Dashboard URL for editing this plugin or recipe.
     */
    public function managementUrl(): ?string
    {
        if ($this->plugin_type === 'recipe') {
            return route('plugins.recipe', $this);
        }

        if (filled($this->plugin_type)) {
            return route('plugins.type-instance', ['type' => $this->plugin_type, 'plugin' => $this]);
        }

        return null;
    }

    public function isDataStale(): bool
    {
        $handlerStale = $this->handler()?->isDataStale($this);
        if ($handlerStale !== null) {
            return $handlerStale;
        }

        if ($this->data_strategy === 'webhook') {
            // Treat as stale if any webhook event has occurred in the past hour
            return $this->data_payload_updated_at && $this->data_payload_updated_at->gt(now()->subHour());
        }
        if (! $this->data_payload_updated_at || ! $this->data_stale_minutes) {
            return true;
        }

        return $this->data_payload_updated_at->addMinutes($this->data_stale_minutes)->isPast();
    }

    /** Bytes reserved below livewire.payload.max_size for other component state in the request. */
    private const int WIRE_HEADROOM_BYTES = 512;

    /** Extra reserve for the recipe form body (markup, views, other properties). */
    private const RECIPE_STATIC_FIELD_RESERVE_BYTES = 1024 * 1024;

    public const WEBHOOK_MERGE_STRATEGY_DEEP_MERGE = 'deep_merge';

    public const WEBHOOK_MERGE_STRATEGY_STREAM = 'stream';

    /** Max pretty-encoded data_payload size for Livewire; null when unlimited. */
    public static function maxDataPayloadBytesForWire(): ?int
    {
        $maxSize = config('livewire.payload.max_size');

        if (! is_int($maxSize) || $maxSize <= 0) {
            return null;
        }

        return max(0, $maxSize - self::WIRE_HEADROOM_BYTES);
    }

    /** Stricter cap for the static JSON field in the recipe editor. */
    public static function maxDataPayloadBytesForRecipeStaticField(): ?int
    {
        $base = self::maxDataPayloadBytesForWire();

        if ($base === null) {
            return null;
        }

        return max(0, $base - self::RECIPE_STATIC_FIELD_RESERVE_BYTES);
    }

    /** Size of JSON as sent on the wire (matches recipe hydration with JSON_PRETTY_PRINT). */
    public static function encodedDataPayloadWireBytes(mixed $payload): int
    {
        return mb_strlen((string) json_encode($payload, JSON_PRETTY_PRINT), '8bit');
    }

    public static function dataPayloadWithinWireLimit(mixed $payload): bool
    {
        $limit = self::maxDataPayloadBytesForWire();

        if ($limit === null) {
            return true;
        }

        return self::encodedDataPayloadWireBytes($payload) <= $limit;
    }

    /** Ensures raw editor JSON and pretty-encoded array both fit the recipe static-field budget. */
    public static function staticDataPayloadWithinWireLimit(string $jsonString, mixed $decoded): bool
    {
        $limit = self::maxDataPayloadBytesForRecipeStaticField();

        if ($limit === null) {
            return true;
        }

        if (mb_strlen($jsonString, '8bit') > $limit) {
            return false;
        }

        return ! is_array($decoded) || self::encodedDataPayloadWireBytes($decoded) <= $limit;
    }

    /** Stored when an incoming payload exceeds the Livewire-safe byte budget. */
    public static function oversizedDataPayloadErrorPayload(): array
    {
        return ['error' => 'Data payload exceeds maximum allowed size'];
    }

    /**
     * Run the recipe's transform on polled or static data once transforms are enabled
     * and the recipe has a transform language (null means no transform).
     * Throws so the caller can fall back to the untransformed payload.
     *
     * @throws TransformException
     */
    private function applyTransform(mixed $payload): mixed
    {
        if (
            ! is_array($payload)
            || blank($this->transform_language)
            || ! TransformService::isEnabled()
            || ! in_array($this->data_strategy, TransformService::strategies(), true)
        ) {
            return $payload;
        }

        return app(TransformService::class)->transform($this, $payload);
    }

    /**
     * Transform the payload and build the attributes to store. A failing transform
     * stores the untransformed payload and records the error on the plugin.
     *
     * @return array{data_payload: mixed, data_payload_updated_at: DateTimeInterface, transform_error: ?string, transform_failed_at: ?DateTimeInterface}
     */
    private function transformedPayloadAttributes(mixed $rawPayload): array
    {
        $transformError = null;

        try {
            $finalPayload = $this->applyTransform($rawPayload);
        } catch (TransformException $e) {
            Log::warning('Transform failed; storing the untransformed payload', ['plugin_id' => $this->id, 'error' => $e->getMessage()]);
            $finalPayload = $rawPayload;
            $transformError = mb_substr(mb_trim($e->getMessage()."\n".($e->diagnostics ?? '')), 0, self::MAX_TRANSFORM_ERROR_LENGTH);
        }

        if (! self::dataPayloadWithinWireLimit($finalPayload)) {
            Log::warning("Plugin {$this->id} data_payload exceeded wire size limit; storing error placeholder");
            $finalPayload = self::oversizedDataPayloadErrorPayload();
        }

        return [
            'data_payload' => $finalPayload,
            'data_payload_updated_at' => now(),
            'transform_error' => $transformError,
            'transform_failed_at' => $transformError === null ? null : ($this->transform_failed_at ?? now()),
        ];
    }

    /**
     * Store static data, replacing it with the transform output when the recipe has a transform.
     */
    public function storeStaticDataPayload(mixed $payload): void
    {
        $this->update($this->transformedPayloadAttributes($payload));
    }

    /** Supported TRMNL webhook merge strategies for recipe plugins. */
    public static function webhookMergeStrategies(): array
    {
        return [
            self::WEBHOOK_MERGE_STRATEGY_DEEP_MERGE,
            self::WEBHOOK_MERGE_STRATEGY_STREAM,
        ];
    }

    public static function mergeWebhookPayload(
        mixed $currentPayload,
        mixed $incomingPayload,
        ?string $mergeStrategy = null,
        ?int $streamLimit = null,
    ): mixed {
        return match ($mergeStrategy) {
            null, '' => $incomingPayload,
            self::WEBHOOK_MERGE_STRATEGY_DEEP_MERGE => self::deepMergeWebhookPayload($currentPayload, $incomingPayload),
            self::WEBHOOK_MERGE_STRATEGY_STREAM => self::streamWebhookPayload($currentPayload, $incomingPayload, $streamLimit),
            default => throw new InvalidArgumentException("Unsupported webhook merge strategy [{$mergeStrategy}]"),
        };
    }

    private static function deepMergeWebhookPayload(mixed $currentPayload, mixed $incomingPayload): mixed
    {
        if (! is_array($currentPayload) || ! is_array($incomingPayload)) {
            return $incomingPayload;
        }

        $mergedPayload = $currentPayload;

        foreach ($incomingPayload as $key => $value) {
            if (
                array_key_exists($key, $mergedPayload)
                && self::isAssociativeArray($mergedPayload[$key])
                && self::isAssociativeArray($value)
            ) {
                $mergedPayload[$key] = self::deepMergeWebhookPayload($mergedPayload[$key], $value);

                continue;
            }

            $mergedPayload[$key] = $value;
        }

        return $mergedPayload;
    }

    private static function streamWebhookPayload(mixed $currentPayload, mixed $incomingPayload, ?int $streamLimit): mixed
    {
        if (! is_array($incomingPayload)) {
            return $incomingPayload;
        }

        $mergedPayload = is_array($currentPayload) ? $currentPayload : [];

        foreach ($incomingPayload as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $existingValue = $mergedPayload[$key] ?? [];
                $streamedValue = is_array($existingValue) && array_is_list($existingValue)
                    ? [...$existingValue, ...$value]
                    : $value;

                if ($streamLimit !== null) {
                    $streamedValue = array_slice($streamedValue, -$streamLimit);
                }

                $mergedPayload[$key] = $streamedValue;

                continue;
            }

            $mergedPayload[$key] = $value;
        }

        return $mergedPayload;
    }

    private static function isAssociativeArray(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }

    public function updateDataPayload(): void
    {
        if ($this->data_strategy !== 'polling' || ! $this->polling_url) {
            return;
        }

        $this->update($this->transformedPayloadAttributes($this->fetchPollingPayload()));
    }

    /**
     * Fetch and parse the polling URLs without transforming or storing the result.
     * One URL yields its parsed response; several yield `IDX_n` keys.
     */
    public function fetchPollingPayload(): mixed
    {
        $headers = ['User-Agent' => 'usetrmnl/larapaper', 'Accept' => 'application/json'];

        // resolve headers
        if ($this->polling_header) {
            $resolvedHeader = $this->resolveLiquidVariables($this->polling_header);
            $headerLines = explode("\n", mb_trim($resolvedHeader));
            foreach ($headerLines as $line) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[mb_trim($parts[0])] = mb_trim($parts[1]);
                }
            }
        }

        // resolve and clean URLs
        $resolvedPollingUrls = $this->resolveLiquidVariables($this->polling_url);
        $urls = array_values(array_filter( // array_values ensures 0, 1, 2...
            array_map(trim(...), explode("\n", $resolvedPollingUrls)),
            filled(...)
        ));

        $resolvedBody = ($this->polling_verb === 'post' && $this->polling_body)
            ? $this->resolveLiquidVariables($this->polling_body)
            : null;
        $contentType = $headers['Content-Type'] ?? 'application/json';

        $isPost = $this->polling_verb === 'post';
        $httpClientTimeout = config('app.http_client_timeout');
        $responses = Http::pool(function (Pool $pool) use ($urls, $headers, $resolvedBody, $contentType, $isPost, $httpClientTimeout): array {
            $requests = [];
            foreach ($urls as $url) {
                $request = $pool->withHeaders($headers)->timeout($httpClientTimeout);
                if ($isPost && $resolvedBody !== null) {
                    $request = $request->withBody($resolvedBody, $contentType);
                }
                $requests[] = $isPost ? $request->post($url) : $request->get($url);
            }

            return $requests;
        });

        $combinedResponse = [];
        foreach ($urls as $index => $url) {
            try {
                $httpResponse = $responses[$index];
                if ($httpResponse instanceof Exception) {
                    throw $httpResponse;
                }
                if ($httpResponse->failed()) {
                    Log::warning("Plugin {$this->id} got HTTP {$httpResponse->status()} from {$url}");
                    $combinedResponse["IDX_{$index}"] = ['error' => 'Failed to fetch data'];

                    continue;
                }

                $response = $this->parseResponse($httpResponse);
                $isSequential = array_keys($response) === range(0, count($response) - 1);
                $combinedResponse["IDX_{$index}"] = $isSequential ? ['data' => $response] : $response;
            } catch (Exception $e) {
                Log::warning("Plugin {$this->id} failed to fetch/parse {$url}: ".$e->getMessage());
                $combinedResponse["IDX_{$index}"] = ['error' => 'Failed to fetch data'];
            }
        }

        // unwrap IDX_0 if only one URL
        return (count($urls) === 1) ? reset($combinedResponse) : $combinedResponse;
    }

    private function parseResponse(Response $httpResponse): array
    {
        $parsers = app(ResponseParserRegistry::class)->getParsers();

        foreach ($parsers as $parser) {
            $parserName = class_basename($parser);

            try {
                $result = $parser->parse($httpResponse);

                if ($result !== null) {
                    return $result;
                }
            } catch (Exception $e) {
                Log::warning("Failed to parse {$parserName} response: ".$e->getMessage());
            }
        }

        return ['error' => 'Failed to parse response'];
    }

    /**
     * Apply Liquid template replacements (converts 'with' syntax to comma syntax)
     */
    private function applyLiquidReplacements(string $template): string
    {

        $replacements = [];

        // Apply basic replacements
        $template = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Convert l_date formats from strftime to PHP date tokens.
        $template = $this->convertDateFormats($template);

        // Convert {% render "template" with %} syntax to {% render "template", %} syntax
        $template = preg_replace(
            '/{%\s*render\s+([^}]+?)\s+with\s+/i',
            '{% render $1, ',
            $template
        );

        // Convert for loops with filters to use temporary variables
        // This handles: {% for item in collection | filter: "key", "value" %}
        // Converts to: {% assign temp_filtered = collection | filter: "key", "value" %}{% for item in temp_filtered %}
        $template = preg_replace_callback(
            '/{%\s*for\s+(\w+)\s+in\s+([^|%}]+)\s*\|\s*([^%}]+)%}/',
            function (array $matches): string {
                $variableName = mb_trim($matches[1]);
                $collection = mb_trim($matches[2]);
                $filter = mb_trim($matches[3]);
                $tempVarName = '_temp_'.uniqid();

                return "{% assign {$tempVarName} = {$collection} | {$filter} %}{% for {$variableName} in {$tempVarName} %}";
            },
            (string) $template
        );

        return $template;
    }

    /**
     * Convert l_date formats from strftime to PHP date tokens.
     *
     * The built-in date filter speaks strftime natively. l_date still formats with Carbon.
     */
    private function convertDateFormats(string $template): string
    {
        $template = preg_replace_callback(
            '/l_date:\s*(["\'])([^"\']+)\1/',
            function (array $matches): string {
                $quote = $matches[1];
                $format = $matches[2];
                $convertedFormat = \App\Liquid\Utils\ExpressionUtils::strftimeToPhpFormat($format);

                return 'l_date: '.$quote.$convertedFormat.$quote;
            },
            (string) $template
        );

        return $template;
    }

    /**
     * Check if a template contains a Liquid for loop pattern
     *
     * @param  string  $template  The template string to check
     * @return bool True if the template contains a for loop pattern
     */
    private function containsLiquidForLoop(string $template): bool
    {
        return preg_match('/{%-?\s*for\s+/i', $template) === 1;
    }

    /**
     * Resolve Liquid variables in a template string using the Liquid template engine
     *
     * Uses the external trmnl-liquid renderer when:
     * - preferred_renderer is 'trmnl-liquid'
     * - External renderer is enabled in config
     * - Template contains a Liquid for loop pattern
     *
     * Otherwise uses the internal PHP-based Liquid renderer.
     *
     * @param  string  $template  The template string containing Liquid variables
     * @return string The resolved template with variables replaced with their values
     *
     * @throws LiquidException
     * @throws Exception
     */
    public function resolveLiquidVariables(string $template): string
    {
        // Get configuration variables - make them available at root level
        $variables = $this->configuration ?? [];

        // Check if external renderer should be used
        $useExternalRenderer = $this->preferred_renderer === 'trmnl-liquid'
            && config('services.trmnl.liquid_enabled')
            && $this->containsLiquidForLoop($template);

        if ($useExternalRenderer) {
            // Use external Ruby liquid renderer
            return $this->renderWithExternalLiquidRenderer($template, $variables);
        }

        // Use the Liquid template engine to resolve variables
        $environment = PluginLiquidEnvironment::make();
        $environment->filterRegistry->register(StandardFilters::class);
        $liquidTemplate = $environment->parseString($template);
        $context = $environment->newRenderContext(data: $variables);

        return $liquidTemplate->render($context);
    }

    /**
     * Render template using external Ruby liquid renderer
     *
     * @param  string  $template  The liquid template string
     * @param  array  $context  The render context data
     * @return string The rendered HTML
     *
     * @throws Exception
     */
    private function renderWithExternalLiquidRenderer(string $template, array $context): string
    {
        $liquidPath = config('services.trmnl.liquid_path');

        if (empty($liquidPath)) {
            throw new Exception('External liquid renderer path is not configured');
        }

        // HTML encode the template
        $encodedTemplate = htmlspecialchars($template, ENT_QUOTES, 'UTF-8');

        // Encode context as JSON
        $jsonContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($jsonContext === false) {
            throw new Exception('Failed to encode render context as JSON: '.json_last_error_msg());
        }

        // Validate argument sizes
        app(PluginImportService::class)->validateExternalRendererArguments($encodedTemplate, $jsonContext, $liquidPath);

        // Execute the external renderer
        $process = Process::run([
            $liquidPath,
            '--template',
            $encodedTemplate,
            '--context',
            $jsonContext,
        ]);

        if (! $process->successful()) {
            $errorOutput = $process->errorOutput() ?: $process->output();
            throw new Exception('External liquid renderer failed: '.$errorOutput);
        }

        return $process->output();
    }

    /**
     * `trmnl` context passed to transforms. No device is bound while data is transformed,
     * and system/sensor values are render-time only.
     *
     * @return array<string, mixed>
     */
    public function transformContext(): array
    {
        $context = $this->trmnlContext();
        unset($context['system'], $context['sensors']);

        return $context;
    }

    /**
     * TRMNL template variables shared by Liquid, Blade, and view rendering.
     *
     * @return array<string, mixed>
     */
    private function trmnlContext(?Device $device = null): array
    {
        $timezone = $this->user->timezone ?? config('app.timezone');
        $utcOffset = (string) Carbon::now($timezone)->getOffset();

        return [
            'system' => [
                'timestamp_utc' => now()->utc()->timestamp,
            ],
            'user' => [
                'utc_offset' => $utcOffset,
                'name' => $this->user->name ?? 'Unknown User',
                'locale' => 'en',
                'time_zone_iana' => $timezone,
            ],
            'device' => [
                'friendly_id' => $device?->friendly_id,
                'percent_charged' => $device?->battery_percent,
                'wifi_strength' => $device?->wifi_strength,
                'height' => $device?->height,
                'width' => $device?->width,
                'model' => $device?->deviceModel?->name,
                'bit_depth' => $device?->deviceModel?->bit_depth,
                'refresh_interval_seconds' => $device === null ? null : (int) $device->default_refresh_interval,
                'sleep_mode_enabled' => $device?->sleep_mode_enabled,
                'sleep_start_time' => $device?->sleepStartMinutes(),
                'sleep_end_time' => $device?->sleepEndMinutes(),
            ],
            'sensors' => $device ? $device->sensorContext() : ['latest' => [], 'all' => []],
            'plugin_settings' => [
                'instance_name' => $this->name,
                'strategy' => $this->data_strategy,
                'dark_mode' => $this->dark_mode ? 'yes' : 'no',
                'no_screen_padding' => $this->no_bleed ? 'yes' : 'no',
                'polling_headers' => $this->polling_header,
                'polling_url' => $this->polling_url,
                'custom_fields_values' => [
                    ...(is_array($this->configuration) ? $this->configuration : []),
                ],
                'refresh_interval_minutes' => $this->data_stale_minutes === null ? null : (int) $this->data_stale_minutes,
                'data_fetched_utc' => $this->data_payload_updated_at?->getTimestamp(),
            ],
        ];
    }

    /**
     * Render the plugin's markup
     *
     * @throws LiquidException
     */
    public function render(string $size = 'full', bool $standalone = true, ?Device $device = null): string
    {
        if ($this->plugin_type !== 'recipe') {
            throw new InvalidArgumentException('Render method is only applicable for recipe plugins.');
        }

        $markup = $this->getMarkupForSize($size);

        if ($markup) {
            $renderedContent = '';

            if ($this->markup_language === 'liquid') {
                $context = [
                    'size' => $size,
                    'data' => $this->data_payload,
                    'config' => $this->configuration ?? [],
                    ...(is_array($this->data_payload) ? $this->data_payload : []),
                    'trmnl' => $this->trmnlContext($device),
                ];

                // Check if external renderer should be used
                if ($this->preferred_renderer === 'trmnl-liquid' && config('services.trmnl.liquid_enabled')) {
                    // Use external Ruby renderer - pass raw template without preprocessing
                    $renderedContent = $this->renderWithExternalLiquidRenderer($markup, $context);
                } else {
                    // Use PHP keepsuit/liquid renderer
                    $environment = PluginLiquidEnvironment::make();

                    // Apply Liquid replacements (including 'with' syntax conversion)
                    $processedMarkup = $this->applyLiquidReplacements($markup);

                    $template = $environment->parseString($processedMarkup);
                    $liquidContext = $environment->newRenderContext(data: $context);
                    $renderedContent = $template->render($liquidContext);
                }
            } else {
                if (! UserBladeExecution::allowed()) {
                    throw new InvalidArgumentException(UserBladeExecution::DISABLED_MESSAGE);
                }

                $renderedContent = Blade::render($markup, [
                    'size' => $size,
                    'data' => $this->data_payload,
                    'config' => $this->configuration ?? [],
                    'trmnl' => $this->trmnlContext($device),
                ]);
            }

            if ($standalone) {
                if ($size === 'full') {
                    return view('trmnl-layouts.single', [
                        'colorDepth' => $device?->colorDepth(),
                        'deviceVariant' => $device?->deviceModel->css_name ?? $device?->deviceVariant() ?? 'og',
                        'noBleed' => $this->no_bleed,
                        'darkMode' => $this->dark_mode,
                        'scaleLevel' => $device?->scaleLevel(),
                        'paletteClass' => $device?->paletteClass(),
                        'cssVariables' => $device?->deviceModel?->css_variables,
                        'frameworkVersion' => $this->resolvedFrameworkVersion(),
                        'slot' => $renderedContent,
                    ])->render();
                }

                return view('trmnl-layouts.mashup', [
                    'mashupLayout' => $this->getPreviewMashupLayoutForSize($size),
                    'colorDepth' => $device?->colorDepth(),
                    'deviceVariant' => $device?->deviceModel->css_name ?? $device?->deviceVariant() ?? 'og',
                    'darkMode' => $this->dark_mode,
                    'scaleLevel' => $device?->scaleLevel(),
                    'paletteClass' => $device?->paletteClass(),
                    'cssVariables' => $device?->deviceModel?->css_variables,
                    'frameworkVersion' => $this->resolvedFrameworkVersion(),
                    'slot' => $renderedContent,
                ])->render();

            }

            return $renderedContent;
        }

        if ($this->render_markup_view) {
            if ($standalone) {
                $renderedView = view($this->render_markup_view, [
                    'size' => $size,
                    'data' => $this->data_payload,
                    'config' => $this->configuration ?? [],
                    'trmnl' => $this->trmnlContext($device),
                ])->render();

                if ($size === 'full') {
                    return view('trmnl-layouts.single', [
                        'colorDepth' => $device?->colorDepth(),
                        'deviceVariant' => $device?->deviceModel->css_name ?? $device?->deviceVariant() ?? 'og',
                        'noBleed' => $this->no_bleed,
                        'darkMode' => $this->dark_mode,
                        'scaleLevel' => $device?->scaleLevel(),
                        'paletteClass' => $device?->paletteClass(),
                        'cssVariables' => $device?->deviceModel?->css_variables,
                        'frameworkVersion' => $this->resolvedFrameworkVersion(),
                        'slot' => $renderedView,
                    ])->render();
                }

                return view('trmnl-layouts.mashup', [
                    'mashupLayout' => $this->getPreviewMashupLayoutForSize($size),
                    'colorDepth' => $device?->colorDepth(),
                    'deviceVariant' => $device?->deviceModel->css_name ?? $device?->deviceVariant() ?? 'og',
                    'darkMode' => $this->dark_mode,
                    'scaleLevel' => $device?->scaleLevel(),
                    'paletteClass' => $device?->paletteClass(),
                    'cssVariables' => $device?->deviceModel?->css_variables,
                    'frameworkVersion' => $this->resolvedFrameworkVersion(),
                    'slot' => $renderedView,
                ])->render();
            }

            return view($this->render_markup_view, [
                'size' => $size,
                'data' => $this->data_payload,
                'config' => $this->configuration ?? [],
                'trmnl' => $this->trmnlContext($device),
            ])->render();

        }

        return '<p>No render markup yet defined for this plugin.</p>';
    }

    /**
     * Get a configuration value by key
     */
    public function getConfiguration(string $key, $default = null)
    {
        return $this->configuration[$key] ?? $default;
    }

    /**
     * Get the appropriate markup for a given size, including shared prepending logic
     *
     * @param  string  $size  The layout size (full, half_horizontal, half_vertical, quadrant)
     * @return string|null The markup code for the given size, with shared prepended if available
     */
    public function getMarkupForSize(string $size): ?string
    {
        $markup = match ($size) {
            'full' => $this->render_markup,
            'half_horizontal' => $this->render_markup_half_horizontal ?? $this->render_markup,
            'half_vertical' => $this->render_markup_half_vertical ?? $this->render_markup,
            'quadrant' => $this->render_markup_quadrant ?? $this->render_markup,
            default => $this->render_markup,
        };

        // Prepend shared markup if it exists
        if ($markup && $this->render_markup_shared) {
            return $this->render_markup_shared."\n".$markup;
        }

        return $markup;
    }

    public function getPreviewMashupLayoutForSize(string $size): string
    {
        return match ($size) {
            'half_vertical' => '1Lx1R',
            'quadrant' => '2x2',
            default => '1Tx1B',
        };
    }

    /**
     * Duplicate the plugin, copying all attributes and handling render_markup_view
     *
     * @param  int|null  $userId  Optional user ID for the duplicate. If not provided, uses the original plugin's user_id.
     * @return Plugin The newly created duplicate plugin
     */
    public function duplicate(?int $userId = null): self
    {
        // Get all attributes except id and uuid
        // Use toArray() to get cast values (respects JSON casts)
        $attributes = $this->toArray();
        unset($attributes['id'], $attributes['uuid'], $attributes['trmnlp_id'], $attributes['transform_error'], $attributes['transform_failed_at']);

        // Handle render_markup_view - copy file content to render_markup
        if ($this->render_markup_view) {
            try {
                $basePath = resource_path('views/'.str_replace('.', '/', $this->render_markup_view));
                $paths = [
                    $basePath.'.blade.php',
                    $basePath.'.liquid',
                ];

                $fileContent = null;
                $markupLanguage = null;
                foreach ($paths as $path) {
                    if (file_exists($path)) {
                        $fileContent = file_get_contents($path);
                        // Determine markup language based on file extension
                        $markupLanguage = str_ends_with($path, '.liquid') ? 'liquid' : 'blade';
                        break;
                    }
                }

                if ($fileContent !== null) {
                    $attributes['render_markup'] = $fileContent;
                    $attributes['markup_language'] = $markupLanguage;
                    $attributes['render_markup_view'] = null;
                } else {
                    // File doesn't exist, remove the view reference
                    $attributes['render_markup_view'] = null;
                }
            } catch (Exception) {
                // If file reading fails, remove the view reference
                $attributes['render_markup_view'] = null;
            }
        }

        // Append "_copy" to the name
        $attributes['name'] = $this->name.'_copy';

        // Set user_id - use provided userId or fall back to original plugin's user_id
        $attributes['user_id'] = $userId ?? $this->user_id;

        // Create and return the new plugin
        return self::create($attributes);
    }
}
