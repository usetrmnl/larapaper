<?php

use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Services\Plugin\Transform\TransformException;
use App\Services\Plugin\Transform\TransformService;
use App\Services\PluginExportService;
use App\Support\UserBladeExecution;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Keepsuit\Liquid\Exceptions\LiquidException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new class extends Component
{
    /** Transform languages a recipe may store and edit; the configured provider decides which can run. */
    private const TRANSFORM_LANGUAGES = ['php', 'node', 'python', 'ruby'];

    public Plugin $plugin;

    public ?string $markup_code;

    public ?string $view_content;

    public ?string $markup_language;

    public string $name;

    public bool $no_bleed = false;

    public bool $dark_mode = false;

    public int $data_stale_minutes;

    public string $data_strategy;

    public ?string $polling_url;

    public string $polling_verb;

    public ?string $polling_header;

    public ?string $polling_body;

    public ?string $transform_code = null;

    /** Transform language; null means the recipe has no transform. */
    public ?string $transform_language = null;

    /**
     * Result of the last "Run" in the transform editor (never stored on the plugin).
     *
     * @var array{error: ?string, output: ?string, logs: string, duration_ms: ?int}|null
     */
    public ?array $transform_run = null;

    public $data_payload;

    public ?Carbon $data_payload_updated_at;

    public array $checked_devices = [];

    public array $device_playlists = [];

    public array $device_playlist_names = [];

    public array $device_weekdays = [];

    public array $device_active_from = [];

    public array $device_active_until = [];

    public string $mashup_layout = 'full';

    public array $mashup_plugins = [];

    public array $configuration_template = [];

    public ?int $preview_device_model_id = null;

    public ?string $preview_image_url = null;

    public string $preview_size = 'full';

    public array $markup_layouts = [];

    public array $active_tabs = [];

    public string $active_tab = 'full';

    public function mount(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);
        $this->blade_code = $this->plugin->render_markup;
        // required to render some stuff
        $this->configuration_template = $this->plugin->configuration_template ?? [];

        if ($this->plugin->render_markup_view) {
            $this->view_content = null;
            $detectedLanguage = 'blade';

            try {
                $basePath = resource_path('views/'.str_replace('.', '/', $this->plugin->render_markup_view));
                $candidates = [
                    $basePath.'.blade.php' => 'blade',
                    $basePath.'.liquid' => 'liquid',
                ];

                foreach ($candidates as $path => $language) {
                    if (file_exists($path)) {
                        $this->view_content = file_get_contents($path);
                        $detectedLanguage = $language;
                        break;
                    }
                }
            } catch (Exception $e) {
                $this->view_content = null;
            }

            $this->markup_language = $this->plugin->markup_language ?? $detectedLanguage;
        } else {
            // Initialize layout markups from plugin columns
            $this->markup_layouts = [
                'full' => $this->plugin->render_markup ?? '',
                'half_horizontal' => $this->plugin->render_markup_half_horizontal ?? '',
                'half_vertical' => $this->plugin->render_markup_half_vertical ?? '',
                'quadrant' => $this->plugin->render_markup_quadrant ?? '',
                'shared' => $this->plugin->render_markup_shared ?? '',
            ];

            // Set active tabs based on which layouts have content
            $this->active_tabs = ['full']; // Full is always active
            foreach (['half_horizontal', 'half_vertical', 'quadrant', 'shared'] as $layout) {
                if (! empty($this->markup_layouts[$layout])) {
                    $this->active_tabs[] = $layout;
                }
            }

            $this->markup_code = $this->markup_layouts['full'];
            $this->markup_language = $this->plugin->markup_language ?? 'blade';
        }

        // Initialize screen settings from the model
        $this->no_bleed = (bool) ($this->plugin->no_bleed ?? false);
        $this->dark_mode = (bool) ($this->plugin->dark_mode ?? false);

        $this->fillformFields();
        $this->data_payload_updated_at = $this->plugin->data_payload_updated_at;

        // Set default preview device model
        if ($this->preview_device_model_id === null) {
            $defaultModel = $this->getUserDeviceModels()->first()
                ?? DeviceModel::where('name', 'og_plus')->first()
                ?? DeviceModel::first();
            $this->preview_device_model_id = $defaultModel?->id;
        }
    }

    public function fillFormFields(): void
    {
        $this->name = $this->plugin->name;
        $this->data_stale_minutes = $this->plugin->data_stale_minutes;
        $this->data_strategy = $this->plugin->data_strategy;
        $this->polling_url = $this->plugin->polling_url;
        $this->polling_verb = $this->plugin->polling_verb;
        $this->polling_header = $this->plugin->polling_header;
        $this->polling_body = $this->plugin->polling_body;
        $this->transform_code = $this->plugin->transform_code;
        $this->transform_language = filled($this->plugin->transform_language) ? (string) $this->plugin->transform_language : null;
        $this->data_payload = json_encode($this->plugin->data_payload, JSON_PRETTY_PRINT);
    }

    public function userBladeIsAllowed(): bool
    {
        return UserBladeExecution::allowed();
    }

    public function showsBladeDisabledCallout(): bool
    {
        if ($this->userBladeIsAllowed()) {
            return false;
        }

        return ($this->markup_language ?? 'blade') !== 'liquid';
    }

    public function userBladeRenderingIsBlocked(): bool
    {
        if (UserBladeExecution::allowed() || filled($this->plugin->render_markup_view)) {
            return false;
        }

        $editorLanguage = $this->markup_language ?? 'blade';
        $storedLanguage = $this->plugin->markup_language ?? 'blade';

        return $editorLanguage !== 'liquid' || $storedLanguage !== 'liquid';
    }

    public function saveMarkup(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);
        $this->validate();

        if (! $this->validateTransform()) {
            return;
        }

        // Update markup_code for the active tab
        if (isset($this->markup_layouts[$this->active_tab])) {
            $this->markup_layouts[$this->active_tab] = $this->markup_code ?? '';
        }

        // Save all layout markups to respective columns
        $this->plugin->update([
            'render_markup' => $this->markup_layouts['full'] ?? null,
            'render_markup_half_horizontal' => ! empty($this->markup_layouts['half_horizontal']) ? $this->markup_layouts['half_horizontal'] : null,
            'render_markup_half_vertical' => ! empty($this->markup_layouts['half_vertical']) ? $this->markup_layouts['half_vertical'] : null,
            'render_markup_quadrant' => ! empty($this->markup_layouts['quadrant']) ? $this->markup_layouts['quadrant'] : null,
            'render_markup_shared' => ! empty($this->markup_layouts['shared']) ? $this->markup_layouts['shared'] : null,
            'markup_language' => $this->markup_language ?? null,
        ]);

        if ($this->transformIsSupported()) {
            $this->plugin->update([
                'transform_code' => filled($this->transform_code) ? $this->transform_code : null,
                'transform_language' => $this->transform_language,
            ]);
        }
    }

    /**
     * An enabled transform needs code in a language the configured provider can run.
     */
    private function validateTransform(): bool
    {
        if (! $this->showsTransformTab()) {
            return true;
        }

        if (blank($this->transform_code)) {
            $this->addError('transform_code', 'Transform code is required when the transform is enabled.');

            return false;
        }

        // Languages the provider cannot run may still be saved (e.g. imported Ruby); the editor shows a callout.
        return true;
    }

    public function addLayoutTab(string $layout): void
    {
        if (! in_array($layout, $this->active_tabs, true)) {
            $this->active_tabs[] = $layout;
            if (! isset($this->markup_layouts[$layout])) {
                $this->markup_layouts[$layout] = '';
            }
            $this->switchTab($layout);
        }
    }

    public function removeLayoutTab(string $layout): void
    {
        if ($layout !== 'full') {
            $this->active_tabs = array_values(array_filter($this->active_tabs, fn ($tab) => $tab !== $layout));
            if (isset($this->markup_layouts[$layout])) {
                $this->markup_layouts[$layout] = '';
            }
            if ($this->active_tab === $layout) {
                $this->active_tab = 'full';
                $this->markup_code = $this->markup_layouts['full'] ?? '';
            }
        }
    }

    public function switchTab(string $layout): void
    {
        $isTransformTab = $layout === 'transform' && $this->showsTransformTab();

        if (! $isTransformTab && ! in_array($layout, $this->active_tabs, true)) {
            return;
        }

        // Save current tab's content before switching
        if (isset($this->markup_layouts[$this->active_tab])) {
            $this->markup_layouts[$this->active_tab] = $this->markup_code ?? '';
        }

        $this->active_tab = $layout;

        if (! $isTransformTab) {
            $this->markup_code = $this->markup_layouts[$layout] ?? '';
        }
    }

    /**
     * Add or remove the recipe's transform from the code editor; applied on save.
     */
    public function toggleTransformTab(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);

        if (! $this->transformIsSupported()) {
            return;
        }

        $this->transform_run = null;

        if ($this->showsTransformTab()) {
            $this->transform_language = null;

            if ($this->active_tab === 'transform') {
                $this->switchTab('full');
            }

            return;
        }

        if (blank($this->transform_code)) {
            $this->transform_language = $this->transformLanguages()[0] ?? 'php';
            $this->transform_code = $this->transformExample($this->transform_language);
        } else {
            // Re-adding the tab keeps the previous code; recognise its language.
            $this->transform_language = $this->guessTransformLanguage((string) $this->transform_code);
        }

        $this->switchTab('transform');
    }

    private function guessTransformLanguage(string $code): string
    {
        return match (true) {
            str_contains($code, '<?php') => 'php',
            preg_match('/^\s*def\s+run\s*\(.*\)\s*:/m', $code) === 1 => 'python',
            preg_match('/^\s*def\s+run\b/m', $code) === 1 => 'ruby',
            default => 'node',
        };
    }

    public function toggleLayoutTab(string $layout): void
    {
        if ($layout === 'full') {
            return;
        }

        if (in_array($layout, $this->active_tabs, true)) {
            $this->removeLayoutTab($layout);
        } else {
            $this->addLayoutTab($layout);
        }
    }

    public function getAvailableLayouts(): array
    {
        return [
            'half_horizontal' => 'Half Horizontal',
            'half_vertical' => 'Half Vertical',
            'quadrant' => 'Quadrant',
            'shared' => 'Shared',
        ];
    }

    public function getLayoutLabel(string $layout): string
    {
        return match ($layout) {
            'full' => $this->getFullTabLabel(),
            'half_horizontal' => 'Half Horizontal',
            'half_vertical' => 'Half Vertical',
            'quadrant' => 'Quadrant',
            'shared' => 'Shared',
            default => ucfirst($layout),
        };
    }

    public function getFullTabLabel(): string
    {
        // Return "Full" if any layout-specific markup exists, otherwise "Responsive"
        if (! empty($this->markup_layouts['half_horizontal'])
            || ! empty($this->markup_layouts['half_vertical'])
            || ! empty($this->markup_layouts['quadrant'])) {
            return 'Full';
        }

        return 'Responsive';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'data_stale_minutes' => 'required|integer|min:1',
            'data_strategy' => 'required|string|in:polling,webhook,static',
            'polling_url' => 'required_if:data_strategy,polling|nullable',
            'polling_verb' => 'required|string|in:get,post',
            'polling_header' => 'nullable|string|max:10240',
            'polling_body' => 'nullable|string',
            'data_payload' => 'required_if:data_strategy,static|nullable|json',
            'markup_code' => 'nullable|string',
            'markup_language' => 'nullable|string|in:blade,liquid',
            'checked_devices' => 'array',
            'device_playlist_names' => 'array',
            'device_playlists' => 'array',
            'device_weekdays' => 'array',
            'device_active_from' => 'array',
            'device_active_until' => 'array',
            'no_bleed' => 'boolean',
            'dark_mode' => 'boolean',
            'transform_code' => 'nullable|string|max:200000',
            'transform_language' => ['nullable', 'string', Rule::in(self::TRANSFORM_LANGUAGES)],
        ];
    }

    public function editSettings()
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);

        // Custom validation for polling_url with Liquid variable resolution
        $this->validatePollingUrl();

        // The transform is saved together with the markup in the code editor.
        $validated = Arr::except($this->validate(), ['transform_code', 'transform_language']);

        $dataPayloadString = (string) Arr::get($validated, 'data_payload', '');
        $decodedPayload = json_decode($dataPayloadString, true);

        if ($this->data_strategy === 'static' && ! Plugin::staticDataPayloadWithinWireLimit($dataPayloadString, $decodedPayload)) {
            $this->addError('data_payload', 'Data payload exceeds maximum allowed size.');

            return;
        }

        $validated['data_payload'] = $decodedPayload;

        // A static transform produces the data payload on every refresh, so saving never overwrites its output.
        if ($this->data_strategy === 'static' && $this->plugin->runsStaticTransform()) {
            unset($validated['data_payload']);
        }

        $this->plugin->update($validated);

        foreach ($this->configuration_template as $fieldKey => $field) {
            if (($field['field_type'] ?? null) !== 'multi_string') {
                continue;
            }

            if (! isset($this->multiValues[$fieldKey])) {
                continue;
            }

            $validated[$fieldKey] = implode(',', array_filter(array_map('trim', $this->multiValues[$fieldKey])));
        }

    }

    protected function validatePollingUrl(): void
    {
        if ($this->data_strategy === 'polling' && ! empty($this->polling_url)) {
            try {
                $resolvedUrl = $this->plugin->resolveLiquidVariables($this->polling_url);

                if (! filter_var($resolvedUrl, FILTER_VALIDATE_URL)) {
                    $this->addError('polling_url', 'The polling URL must be a valid URL after resolving configuration variables.');
                }
            } catch (Exception $e) {
                $this->addError('polling_url', 'Error resolving Liquid variables: '.$e->getMessage().$e->getPrevious()?->getMessage());
            }
        }
    }

    public function updateData(): void
    {
        if ($this->plugin->data_strategy === 'polling' || $this->plugin->runsStaticTransform()) {
            try {
                $this->plugin->updateDataPayload();

                $this->data_payload = json_encode($this->plugin->data_payload, JSON_PRETTY_PRINT);
                $this->data_payload_updated_at = $this->plugin->data_payload_updated_at;

            } catch (Exception $e) {
                $this->dispatch('data-update-error', message: $e->getMessage().$e->getPrevious()?->getMessage());
            }
        }
    }

    public function getAvailablePlugins()
    {
        return auth()->user()->plugins()
            ->where('id', '!=', $this->plugin->id)
            ->where('plugin_type', 'recipe')
            ->get();
    }

    public function addToPlaylist()
    {
        $this->mashup_plugins = App\Models\PlaylistItem::takePluginIdsForLayout(
            $this->mashup_layout,
            $this->mashup_plugins,
            extrasOnly: true,
        );

        $requiredExtras = $this->mashup_layout === 'full'
            ? 0
            : App\Models\PlaylistItem::getRequiredPluginCountForLayout($this->mashup_layout) - 1;

        $this->validate([
            'checked_devices' => 'required|array|min:1',
            'checked_devices.*' => [Rule::exists('devices', 'id')->where('user_id', auth()->id())],
            'mashup_layout' => 'required|string',
            'mashup_plugins' => ['array', 'size:'.$requiredExtras],
            'mashup_plugins.*' => ['required', Rule::exists('plugins', 'id')->where('user_id', auth()->id())],
        ]);

        // Validate that each checked device has a playlist selected
        foreach ($this->checked_devices as $deviceId) {
            if (! isset($this->device_playlists[$deviceId]) || empty($this->device_playlists[$deviceId])) {
                $this->addError('device_playlists.'.$deviceId, 'Please select a playlist for each device.');

                return;
            }

            // If creating new playlist, validate required fields
            if ($this->device_playlists[$deviceId] === 'new') {
                if (! isset($this->device_playlist_names[$deviceId]) || empty($this->device_playlist_names[$deviceId])) {
                    $this->addError('device_playlist_names.'.$deviceId, 'Playlist name is required when creating a new playlist.');

                    return;
                }
            }
        }

        foreach ($this->checked_devices as $deviceId) {
            $playlist = null;

            if ($this->device_playlists[$deviceId] === 'new') {
                // Create new playlist
                $playlist = App\Models\Playlist::create([
                    'device_id' => $deviceId,
                    'name' => $this->device_playlist_names[$deviceId],
                    'weekdays' => ! empty($this->device_weekdays[$deviceId] ?? null) ? $this->device_weekdays[$deviceId] : null,
                    'active_from' => $this->device_active_from[$deviceId] ?? null,
                    'active_until' => $this->device_active_until[$deviceId] ?? null,
                ]);
            } else {
                $playlist = App\Models\Playlist::where('device_id', $deviceId)->findOrFail($this->device_playlists[$deviceId]);
            }

            // Add plugin to playlist
            $maxOrder = $playlist->items()->max('order') ?? 0;

            if ($this->mashup_layout === 'full') {
                $playlist->items()->create([
                    'plugin_id' => $this->plugin->id,
                    'order' => $maxOrder + 1,
                ]);
            } else {
                $pluginIds = array_merge(
                    [$this->plugin->id],
                    array_map(intval(...), $this->mashup_plugins),
                );
                App\Models\PlaylistItem::createMashup(
                    $playlist,
                    $this->mashup_layout,
                    $pluginIds,
                    $this->plugin->name.' Mashup',
                    $maxOrder + 1
                );
            }
        }

        $this->reset([
            'checked_devices',
            'device_playlists',
            'device_playlist_names',
            'device_weekdays',
            'device_active_from',
            'device_active_until',
            'mashup_layout',
            'mashup_plugins',
        ]);
        Flux::modal('add-to-playlist')->close();
        Flux::toast(variant: 'success', text: 'Added to playlist.');
    }

    public function getDevicePlaylists($deviceId)
    {
        return auth()->user()->devices()->findOrFail($deviceId)->playlists()->get();
    }

    public function getConfigurationValue($key, $default = null)
    {
        return $this->configuration[$key] ?? $default;
    }

    public function renderExample(string $example)
    {
        switch ($example) {
            case 'layoutTitle':
                $markup = $this->renderLayoutWithTitleBar();
                break;
            case 'layout':
                $markup = $this->renderLayoutBlank();
                break;
            default:
                $markup = '<h1>Hello World!</h1>';
                break;
        }
        $this->markup_code = $markup;
    }

    public function renderLayoutWithTitleBar(): string
    {
        if ($this->markup_language === 'liquid') {
            return <<<'HTML'
<div class="view view--{{ size }}">
    <div class="layout">
        <!-- ADD YOUR CONTENT HERE-->
    </div>
    <div class="title_bar">
        <span class="title">TRMNL BYOS</span>
    </div>
</div>
HTML;
        }

        return <<<'HTML'
@props(['size' => 'full'])
<x-trmnl::view size="{{$size}}">
    <x-trmnl::layout>
        <!-- ADD YOUR CONTENT HERE-->
    </x-trmnl::layout>
    <x-trmnl::title-bar/>
</x-trmnl::view>
HTML;
    }

    public function renderLayoutBlank(): string
    {
        if ($this->markup_language === 'liquid') {
            return <<<'HTML'
<div class="view view--{{ size }}">
    <div class="layout">
        <!-- ADD YOUR CONTENT HERE-->
    </div>
</div>
HTML;
        }

        return <<<'HTML'
@props(['size' => 'full'])
<x-trmnl::view size="{{$size}}">
    <x-trmnl::layout>
        <!-- ADD YOUR CONTENT HERE-->
    </x-trmnl::layout>
</x-trmnl::view>
HTML;
    }

    public function transformIsEnabled(): bool
    {
        return TransformService::isEnabled();
    }

    /**
     * Polled and static recipes can hold a transform, even without a configured provider.
     */
    public function transformIsSupported(): bool
    {
        return in_array($this->data_strategy, TransformService::strategies(), true);
    }

    /**
     * Transforms run on polled and static data once a transform provider is configured.
     */
    public function transformIsAvailable(): bool
    {
        return $this->transformIsEnabled() && $this->transformIsSupported();
    }

    /**
     * The last data update stored untransformed data because the transform failed.
     */
    public function showsTransformFailure(): bool
    {
        return filled($this->plugin->transform_error) && $this->transformIsAvailable();
    }

    public function showsTransformTab(): bool
    {
        return filled($this->transform_language) && $this->transformIsSupported();
    }

    /**
     * @return list<string>
     */
    public function transformLanguages(): array
    {
        if (! $this->transformIsEnabled()) {
            return [];
        }

        return app(TransformService::class)->languages();
    }

    public function transformEditorLanguage(): string
    {
        return match ($this->transform_language) {
            'node' => 'javascript',
            'python' => 'python',
            'ruby' => 'ruby',
            default => 'php',
        };
    }

    public function updatedTransformLanguage(?string $language): void
    {
        if ($language === null) {
            return;
        }

        $this->transform_run = null;

        // Swap an untouched example for the new language's example.
        $examples = array_map($this->transformExample(...), self::TRANSFORM_LANGUAGES);
        if (blank($this->transform_code) || in_array(mb_trim((string) $this->transform_code), array_map(mb_trim(...), $examples), true)) {
            $this->transform_code = $this->transformExample($language);
        }
    }

    public function renderTransformExample(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);

        $this->transform_code = $this->transformExample($this->transform_language);
    }

    public function transformExample(string $language): string
    {
        return match ($language) {
            'node' => <<<'JS'
function run(input) {
    return { ...input, transformed_at: new Date().toISOString() };
}
JS,
            'ruby' => <<<'RB'
def run(input)
  input.merge('transformed_at' => Time.now.utc.strftime('%Y-%m-%dT%H:%M:%SZ'))
end
RB,
            'python' => <<<'PY'
from datetime import datetime, timezone

def run(input):
    input["transformed_at"] = datetime.now(timezone.utc).isoformat()
    return input
PY,
            default => <<<'PHP'
<?php

function run(array $input): array
{
    $input['transformed_at'] = date(DATE_ATOM);

    return $input;
}
PHP,
        };
    }

    /**
     * Run the editor's (unsaved) transform code on freshly fetched polling data, or on
     * the static data in the editor. Nothing is stored; the output is shown below the editor.
     */
    public function runTransform(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);
        abort_unless($this->transformIsAvailable(), 403);

        $this->validate([
            'transform_code' => 'required|string|max:200000',
            'transform_language' => ['required', 'string', Rule::in(self::TRANSFORM_LANGUAGES)],
        ]);

        try {
            // A static transform receives only the trmnl context.
            $payload = $this->data_strategy === 'static'
                ? []
                : $this->plugin->fetchPollingPayload();
            $result = app(TransformService::class)->run(
                $this->plugin,
                $this->transform_language,
                (string) $this->transform_code,
                is_array($payload) ? $payload : [],
            );

            $this->transform_run = [
                'error' => null,
                'output' => (string) json_encode($result->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'logs' => $result->stderr,
                'duration_ms' => $result->durationMs,
            ];
        } catch (TransformException $e) {
            $this->transform_run = ['error' => $e->getMessage(), 'output' => null, 'logs' => $e->diagnostics ?? '', 'duration_ms' => null];
        } catch (Exception $e) {
            $this->transform_run = ['error' => 'Fetching the polling data failed: '.$e->getMessage(), 'output' => null, 'logs' => '', 'duration_ms' => null];
        }
    }

    /**
     * Every language a transform may be written in, including ones the provider cannot run.
     *
     * @return list<string>
     */
    public function transformLanguageOptions(): array
    {
        return self::TRANSFORM_LANGUAGES;
    }

    public function transformLanguageLabel(string $language): string
    {
        return match ($language) {
            'php' => 'PHP',
            'node' => 'JavaScript',
            'python' => 'Python',
            'ruby' => 'Ruby',
            default => $language,
        };
    }

    public function renderPreview($size = 'full'): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);

        if ($this->userBladeRenderingIsBlocked()) {
            $this->dispatch('preview-error', message: UserBladeExecution::DISABLED_MESSAGE);

            return;
        }

        $this->preview_size = $size;

        // If data strategy is polling and data_payload is null, fetch the data first
        if ($this->plugin->data_strategy === 'polling' && $this->plugin->data_payload === null) {
            $this->updateData();
        }

        try {
            $device = $this->createPreviewDevice();
            $previewMarkup = $this->plugin->render($size, true, $device);
            $dimensions = $this->previewScreenDimensionsForDevice($device);
            $this->dispatch(
                'preview-updated',
                preview: $previewMarkup,
                screenWidth: $dimensions['width'],
                screenHeight: $dimensions['height'],
            );
        } catch (LiquidException $e) {
            $this->dispatch('preview-error', message: $e->toLiquidErrorMessage());
        } catch (Exception $e) {
            $this->dispatch('preview-error', message: $e->getMessage());
        }
    }

    private function createPreviewDevice(): Device
    {
        $deviceModel = DeviceModel::with(['palette'])->find($this->preview_device_model_id)
            ?? DeviceModel::with(['palette'])->first();

        $device = new Device();
        $device->setRelation('deviceModel', $deviceModel);

        return $device;
    }

    /**
     * Native pixel dimensions for the preview iframe (matches TRMNL screen CSS variables).
     *
     * @return array{width: int, height: int}
     */
    private function previewScreenDimensionsForDevice(Device $device): array
    {
        return [
            'width' => $this->positiveIntOrFallback($device->deviceModel?->width, 800),
            'height' => $this->positiveIntOrFallback($device->deviceModel?->height, 480),
        ];
    }

    private function positiveIntOrFallback(?int $value, int $fallback): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return $fallback;
    }

    public function renderImage(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);

        if ($this->userBladeRenderingIsBlocked()) {
            $this->dispatch('preview-error', message: UserBladeExecution::DISABLED_MESSAGE);

            return;
        }

        if ($this->plugin->data_strategy === 'polling' && $this->plugin->data_payload === null) {
            $this->updateData();
        }

        try {
            $device = $this->createPreviewDevice();
            $markup = $this->plugin->render($this->preview_size, true, $device);

            $imageUuid = App\Services\ImageGenerationService::generateImageFromModel(
                markup: $markup,
                deviceModel: $device->deviceModel,
                user: auth()->user()
            );

            $fileExtension = $device->deviceModel?->mime_type === 'image/bmp' ? 'bmp' : 'png';
            $this->preview_image_url = Storage::disk('public')->url('images/generated/'.$imageUuid.'.'.$fileExtension);

            $dimensions = $this->previewScreenDimensionsForDevice($device);
            $this->dispatch(
                'preview-image-updated',
                imageUrl: $this->preview_image_url,
                screenWidth: $dimensions['width'],
                screenHeight: $dimensions['height'],
            );
        } catch (Exception $e) {
            $this->dispatch('preview-error', message: $e->getMessage());
        }
    }

    public function getDeviceModels()
    {
        $groups = [];
        $userDeviceModels = $this->getUserDeviceModels();
        $userDeviceModelIds = $userDeviceModels->pluck('id');

        if ($userDeviceModels->isNotEmpty()) {
            $groups['user_devices'] = [
                'label' => 'Your Devices',
                'models' => $userDeviceModels,
            ];
        }

        return $groups + [
            'trmnl' => [
                'label' => 'TRMNL',
                'models' => DeviceModel::whereKind('trmnl')
                    ->whereNotIn('id', $userDeviceModelIds)
                    ->orderBy('label')
                    ->get(),
            ],
            'byod' => [
                'label' => 'BYOD',
                'models' => DeviceModel::whereKind('byod')
                    ->whereNotIn('id', $userDeviceModelIds)
                    ->orderBy('label')
                    ->get(),
            ],
            'readers' => [
                'label' => 'eReaders',
                'models' => DeviceModel::whereKind('kindle')
                    ->whereNotIn('id', $userDeviceModelIds)
                    ->orderBy('label')
                    ->get(),
            ],
        ];
    }

    private function getUserDeviceModels()
    {
        return auth()->user()
            ->devices()
            ->with('deviceModel')
            ->whereNotNull('device_model_id')
            ->orderBy('id')
            ->get()
            ->pluck('deviceModel')
            ->filter()
            ->unique('id')
            ->values();
    }

    public function updatedPreviewDeviceModelId(): void
    {
        if ($this->userBladeRenderingIsBlocked()) {
            return;
        }

        $this->renderPreview($this->preview_size);
    }

    public function duplicatePlugin(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);

        // Use the model's duplicate method
        $newPlugin = $this->plugin->duplicate(auth()->id());

        // Redirect to the new plugin's detail page
        $this->redirect(route('plugins.recipe', ['plugin' => $newPlugin]));
    }

    public function exportPluginArchive(PluginExportService $exporter): BinaryFileResponse
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);

        return $exporter->exportToZip($this->plugin, auth()->user());
    }

    public function deletePlugin(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);
        $this->plugin->delete();
        $this->redirect(route('plugins.index'));
    }

    #[On('config-updated')]
    public function refreshPlugin(): void
    {
        $this->plugin = $this->plugin->fresh();
        $this->configuration_template = $this->plugin->configuration_template ?? [];
        $this->fillFormFields();
    }

    // Laravel Livewire computed property: access with $this->parsed_urls
    #[Computed]
    private function parsedUrls()
    {
        if (! isset($this->polling_url)) {
            return null;
        }

        try {
            return $this->plugin->resolveLiquidVariables($this->polling_url);

        } catch (Exception $e) {
            return 'PARSE_ERROR: '.$e->getMessage();
        }
    }
}
?>

<div
    class="py-12"
    x-init="
        const maxClearBytes = @js(Plugin::maxDataPayloadBytesForRecipeStaticField());
        $wire.$watch('data_strategy', (value, old) => {
            if (old == null) {
                return;
            }
            if (old !== 'static' || value === 'static') {
                return;
            }
            const raw = $wire.data_payload ?? '';
            if (maxClearBytes !== null && new TextEncoder().encode(raw).length > maxClearBytes) {
                $wire.$set('data_payload', '{}', false);
            }
        });
    "
>
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center justify-between">
            <h2 class="text-2xl font-semibold dark:text-gray-100">
                {{ $plugin->name }}
                <flux:badge size="sm" class="ml-2">Recipe</flux:badge>
            </h2>

            <flux:button.group>
                <flux:modal.trigger name="preview-plugin">
                    <flux:button
                        icon="eye"
                        wire:click="renderPreview"
                        :disabled="$plugin->hasMissingRequiredConfigurationFields() || $this->userBladeRenderingIsBlocked()"
                    >Preview</flux:button>
                </flux:modal.trigger>
                <flux:dropdown>
                    <flux:button
                        icon="chevron-down"
                        :disabled="$plugin->hasMissingRequiredConfigurationFields() || $this->userBladeRenderingIsBlocked()"
                    ></flux:button>
                    <flux:menu>
                        <flux:modal.trigger name="preview-plugin">
                            <flux:menu.item
                                icon="mashup-1Tx1B"
                                wire:click="renderPreview('half_horizontal')"
                                :disabled="$plugin->hasMissingRequiredConfigurationFields() || $this->userBladeRenderingIsBlocked()"
                            >
                                Half-Horizontal
                            </flux:menu.item>
                        </flux:modal.trigger>

                        <flux:modal.trigger name="preview-plugin">
                            <flux:menu.item
                                icon="mashup-1Lx1R"
                                wire:click="renderPreview('half_vertical')"
                                :disabled="$plugin->hasMissingRequiredConfigurationFields() || $this->userBladeRenderingIsBlocked()"
                            >
                                Half-Vertical
                            </flux:menu.item>
                        </flux:modal.trigger>

                        <flux:modal.trigger name="preview-plugin">
                            <flux:menu.item
                                icon="mashup-2x2"
                                wire:click="renderPreview('quadrant')"
                                :disabled="$plugin->hasMissingRequiredConfigurationFields() || $this->userBladeRenderingIsBlocked()"
                            >
                                Quadrant</flux:menu.item>
                        </flux:modal.trigger>
                    </flux:menu>
                </flux:dropdown>
            </flux:button.group>
            <flux:button.group>
                <flux:modal.trigger name="add-to-playlist">
                    <flux:button
                        icon="play"
                        variant="primary"
                        :disabled="$plugin->hasMissingRequiredConfigurationFields()"
                    >Add to Playlist</flux:button>
                </flux:modal.trigger>

                <flux:dropdown>
                    <flux:button icon="chevron-down" variant="primary"></flux:button>
                    <flux:menu>
                        <flux:modal.trigger name="trmnlp-settings">
                            <flux:menu.item icon="cog">Recipe Settings</flux:menu.item>
                        </flux:modal.trigger>
                        <flux:menu.separator />
                        <flux:menu.item icon="document-duplicate" wire:click="duplicatePlugin">
                            Duplicate Plugin</flux:menu.item>
                        <flux:modal.trigger name="delete-plugin">
                            <flux:menu.item icon="trash" variant="danger">Delete Plugin</flux:menu.item>
                        </flux:modal.trigger>
                        <flux:menu.separator />
                        <flux:menu.item icon="archive-box" wire:click="exportPluginArchive">
                            Export Recipe Archive</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </flux:button.group>
        </div>

        <flux:modal name="add-to-playlist" class="min-w-2xl">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Add to Playlist</flux:heading>
                </div>

                <form wire:submit="addToPlaylist">
                    <flux:separator text="Device(s)" />
                    <div class="mt-4 mb-4">
                        <flux:checkbox.group wire:model.live="checked_devices">
                            @foreach (auth()->user()->devices as $device)
                                <flux:checkbox label="{{ $device->name }}" value="{{ $device->id }}" />
                            @endforeach
                        </flux:checkbox.group>
                    </div>

                    @if (count($checked_devices) > 0)
                        <flux:separator text="Playlist Selection" />
                        <div class="mt-4 mb-4 space-y-6">
                            @foreach ($checked_devices as $deviceId)
                                @php
                                    $device = auth()->user()->devices->find($deviceId);
                                @endphp
                                @continue(! $device)
                                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                                    <div class="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $device->name }}
                                    </div>

                                    <div class="mb-4">
                                        <flux:select wire:model.live.debounce="device_playlists.{{ $deviceId }}">
                                            <option value="">Select Playlist or Create New</option>
                                            @foreach ($this->getDevicePlaylists($deviceId) as $playlist)
                                                <option value="{{ $playlist->id }}">{{ $playlist->name }}</option>
                                            @endforeach
                                            <option value="new">Create New Playlist</option>
                                        </flux:select>
                                    </div>

                                    @if (isset($device_playlists[$deviceId]) && $device_playlists[$deviceId] === 'new')
                                        <div class="space-y-4">
                                            <div>
                                                <flux:input
                                                    label="Playlist Name"
                                                    wire:model="device_playlist_names.{{ $deviceId }}"
                                                />
                                            </div>
                                            <div>
                                                <flux:checkbox.group
                                                    wire:model="device_weekdays.{{ $deviceId }}"
                                                    label="Active Days (optional)"
                                                >
                                                    <flux:checkbox label="Monday" value="1" />
                                                    <flux:checkbox label="Tuesday" value="2" />
                                                    <flux:checkbox label="Wednesday" value="3" />
                                                    <flux:checkbox label="Thursday" value="4" />
                                                    <flux:checkbox label="Friday" value="5" />
                                                    <flux:checkbox label="Saturday" value="6" />
                                                    <flux:checkbox label="Sunday" value="0" />
                                                </flux:checkbox.group>
                                            </div>
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <flux:input
                                                        type="time"
                                                        label="Active From (optional)"
                                                        wire:model="device_active_from.{{ $deviceId }}"
                                                    />
                                                </div>
                                                <div>
                                                    <flux:input
                                                        type="time"
                                                        label="Active Until (optional)"
                                                        wire:model="device_active_until.{{ $deviceId }}"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div
                        x-show="
                            ($wire.checked_devices ?? []).length > 0 &&
                            ($wire.checked_devices ?? []).some((id) => {
                                const v = ($wire.device_playlists ?? {})[id];
                                return v !== undefined && v !== null && v !== '';
                            })
                        "
                        style="display: none"
                    >
                        <x-mashup-layout-fields
                            :layout="$mashup_layout"
                            :plugins="$this->getAvailablePlugins()"
                            :locked-plugin="$plugin"
                        />
                    </div>

                    <div class="flex">
                        <flux:spacer />
                        <flux:button
                            type="submit"
                            variant="primary"
                            :disabled="$plugin->hasMissingRequiredConfigurationFields()"
                        >Add to Playlist</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        <flux:modal name="webhook-instructions" class="md:max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Webhook</flux:heading>
                </div>

                <flux:field>
                    <flux:label>Webhook URL</flux:label>
                    <flux:input
                        :value="route('api.custom_plugins.webhook', ['plugin' => $plugin->uuid])"
                        class="mt-1 block w-full font-mono"
                        readonly
                        copyable
                    />
                </flux:field>

                <flux:text>
                    POST JSON with <code>merge_variables</code> to replace data, or add <code>merge_strategy</code> of
                    <code>deep_merge</code> or <code>stream</code>. GET the same URL to retrieve the latest stored
                    <code>merge_variables</code>.
                    <a
                        href="https://docs.trmnl.com/go/private-plugins/webhooks#update-existing-content"
                        target="_blank"
                        class="underline"
                    >Docs</a>
                </flux:text>

                <flux:separator />

                <flux:callout variant="secondary" icon="device-phone-mobile">
                    <flux:callout.heading>TRMNL Companion app</flux:callout.heading>
                    <flux:callout.text>
                        Create an
                        <a href="{{ route('settings.api-tokens') }}" wire:navigate class="underline">API Token</a>. In
                        the
                        <a
                            href="https://help.trmnl.com/en/articles/12294875-trmnl-companion-for-ios"
                            target="_blank"
                            class="underline"
                            >TRMNL Companion App</a
                        >, enter that token as the Permanent API key and the base URL below.
                    </flux:callout.text>
                </flux:callout>

                <flux:field>
                    <flux:label>Companion API Base URL</flux:label>
                    <flux:input :value="url('/api/companion')" class="mt-1 block w-full font-mono" readonly copyable />
                </flux:field>

                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button variant="primary">Close</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="delete-plugin" class="min-w-[22rem] space-y-6">
            <div>
                <flux:heading size="lg">Delete {{ $plugin->name }}?</flux:heading>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    This will remove this plugin from your account.
                </p>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="deletePlugin" variant="danger">Delete plugin</flux:button>
            </div>
        </flux:modal>

        <flux:modal name="preview-plugin" class="max-h-[90vh] min-w-[850px]">
            <div class="flex max-h-[90vh] min-h-0 flex-col gap-4">
                <div class="flex shrink-0 items-center gap-4">
                    <flux:heading size="lg">Preview {{ $plugin->name }}</flux:heading>
                    <flux:field class="w-48">
                        <flux:select wire:model.live="preview_device_model_id">
                            @foreach ($this->getDeviceModels() as $group)
                                <optgroup label="{{ $group['label'] }}">
                                    @foreach ($group['models'] as $model)
                                        <option value="{{ $model->id }}">{{ $model->label ?? $model->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:button icon="camera" wire:click="renderImage" :disabled="$this->userBladeRenderingIsBlocked()"
                        >Render Image</flux:button>
                </div>

                <div
                    id="preview-stage"
                    class="flex min-h-[50vh] w-full flex-1 items-center justify-center overflow-hidden rounded-lg bg-white dark:bg-zinc-900"
                >
                    <div id="preview-layout" class="overflow-hidden">
                        <div id="preview-scaler" class="origin-top-left">
                            <iframe
                                id="preview-frame"
                                class="block border-0"
                                x-show="! $wire.preview_image_url"
                            ></iframe>
                            <img
                                id="preview-image"
                                class="block"
                                x-show="$wire.preview_image_url"
                                :src="$wire.preview_image_url"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </flux:modal>

        <livewire:plugins.recipes.settings :plugin="$plugin" />

        <livewire:plugins.config-modal :plugin="$plugin" />

        <div class="mt-5 mb-5">
            <h3 class="text-xl font-semibold dark:text-gray-100">Settings</h3>
        </div>
        <div class="grid lg:grid-cols-2 lg:gap-8">
            <div>
                @php
                    $dataPayloadMaxWireBytes = Plugin::maxDataPayloadBytesForRecipeStaticField();
                @endphp
                <form
                    class="mb-6"
                    x-data="{
                        localPayloadError: null,
                        maxWireBytes: @js($dataPayloadMaxWireBytes),
                        submitSettings() {
                            this.localPayloadError = null;
                            if (this.maxWireBytes !== null && $wire.data_strategy === 'static') {
                                const raw = $wire.data_payload ?? '';
                                const rawBytes = new TextEncoder().encode(raw).length;
                                let prettyBytes = 0;
                                try {
                                    const parsed = JSON.parse(raw);
                                    prettyBytes = new TextEncoder().encode(JSON.stringify(parsed, null, 4)).length;
                                } catch (e) {
                                    // Invalid JSON: let Livewire validate; avoid blocking submit on parse errors.
                                }
                                const effective = Math.max(rawBytes, prettyBytes);
                                if (effective > this.maxWireBytes) {
                                    this.localPayloadError = 'Data payload is too large to save (' + effective.toLocaleString() + ' bytes; maximum ' + this.maxWireBytes.toLocaleString() + ' bytes including formatting). Reduce the JSON size before saving.';

                                    return;
                                }
                            }
                            $wire.editSettings();
                        },
                    }"
                    @submit.prevent="submitSettings()"
                >
                    <p
                        x-cloak
                        x-show="localPayloadError"
                        class="mb-3 text-sm text-red-600 dark:text-red-400"
                        x-text="localPayloadError"
                    ></p>
                    <div class="mb-4">
                        <flux:input
                            label="Name"
                            wire:model="name"
                            id="name"
                            class="mt-1 block w-full"
                            type="text"
                            name="name"
                            autofocus
                        />
                    </div>

                    @php
                        $authorField = null;
                        if (isset($configuration_template['custom_fields'])) {
                            foreach ($configuration_template['custom_fields'] as $field) {
                                if ($field['field_type'] === 'author_bio') {
                                    $authorField = $field;
                                    break;
                                }
                            }
                        }
                    @endphp

                    @if ($authorField)
                        <div class="mb-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                            <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                {!! \Stevebauman\Purify\Facades\Purify::clean($authorField['description']) !!}
                            </div>

                            @if (isset($authorField['github_url']) || isset($authorField['learn_more_url']) || isset($authorField['email_address']))
                                <div class="mt-4 flex flex-wrap gap-2">
                                    @if (isset($authorField['github_url']))
                                        @php
                                            $githubUrl = $authorField['github_url'];
                                            $githubUsername = null;

                                            // Extract username from various GitHub URL formats
                                            if (preg_match('/github\.com\/([^\/\?]+)/', $githubUrl, $matches)) {
                                                $githubUsername = $matches[1];
                                            }
                                        @endphp
                                        @if ($githubUsername)
                                            <flux:label badge="{{ $githubUsername }}" />
                                        @endif
                                    @endif
                                    @if (isset($authorField['learn_more_url']))
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon:trailing="arrow-up-right"
                                            href="{{ $authorField['learn_more_url'] }}"
                                            target="_blank"
                                        >
                                            Learn More
                                        </flux:button>
                                    @endif

                                    @if (isset($authorField['github_url']))
                                        <flux:button
                                            size="sm"
                                            icon="github"
                                            variant="ghost"
                                            href="{{ $authorField['github_url'] }}"
                                            target="_blank"
                                        >
                                        </flux:button>
                                    @endif

                                    @if (isset($authorField['email_address']))
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="envelope"
                                            href="mailto:{{ $authorField['email_address'] }}"
                                        >
                                        </flux:button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif

                    @if (isset($configuration_template['custom_fields']) && ! empty($configuration_template['custom_fields']))
                        @if ($plugin->hasMissingRequiredConfigurationFields())
                            <flux:callout
                                class="mb-2"
                                variant="warning"
                                icon="exclamation-circle"
                                heading="Please set required configuration fields."
                            />
                        @endif
                        <div class="mb-4">
                            <flux:modal.trigger name="configuration-modal">
                                <flux:button icon="variable" class="mt-1 block w-full"
                                    >Configuration Fields</flux:button>
                            </flux:modal.trigger>
                        </div>
                    @endif
                    <div class="mb-4">
                        <flux:radio.group wire:model.live="data_strategy" label="Data Strategy" variant="segmented">
                            <flux:radio value="polling" label="Polling" />
                            <flux:radio value="webhook" label="Webhook" />
                            <flux:radio value="static" label="Static" />
                        </flux:radio.group>
                    </div>

                    @if ($data_strategy === 'polling')
                        <flux:label>Polling URL</flux:label>

                        <div x-data="{ subTab: 'urls' }" class="mt-2 mb-4">
                            <div class="flex">
                                <button
                                    @click="subTab = 'urls'"
                                    class="tab-button"
                                    :class="subTab === 'urls' ? 'is-active' : ''"
                                >
                                    <flux:icon.link class="size-4" />
                                    URLs
                                </button>

                                <button
                                    @click="subTab = 'settings'"
                                    class="tab-button"
                                    :class="subTab === 'settings' ? 'is-active' : ''"
                                >
                                    <flux:icon.cog-6-tooth class="size-4" />
                                    Settings
                                </button>

                            </div>

                            <div class="styled-container flex-col rounded-tl-none bg-transparent p-4">
                                {{-- URLs tab --}}
                                <div x-show="subTab === 'urls'">
                                    <flux:field>
                                        <flux:description>Enter the URL(s) to poll for data:</flux:description>
                                        <flux:textarea
                                            wire:model.live="polling_url"
                                            placeholder="https://example.com/api"
                                            rows="5"
                                        />
                                        <flux:description>
                                            {!! 'Hint: Supports multiple requests via line break separation. You can also use configuration variables with <a href="https://help.usetrmnl.com/en/articles/12689499-dynamic-polling-urls">Liquid syntax</a>. ' !!}
                                        </flux:description>
                                    </flux:field>

                                    <div class="mt-3">
                                        <flux:field>
                                            <flux:description>Preview computed URLs here (readonly):</flux:description>
                                            <flux:textarea readonly placeholder="Nothing to show..." rows="3">
                                                {{ $this->parsed_urls }}
                                            </flux:textarea>
                                        </flux:field>
                                    </div>

                                    <flux:button icon="cloud-arrow-down" wire:click="updateData" class="mt-4 w-full">
                                        Fetch data now
                                    </flux:button>
                                </div>

                                {{-- Settings tab --}}
                                <div x-show="subTab === 'settings'" x-cloak>
                                    <div class="mb-4">
                                        <flux:radio.group
                                            wire:model.live="polling_verb"
                                            label="Polling Verb"
                                            variant="segmented"
                                        >
                                            <flux:radio value="get" label="GET" />
                                            <flux:radio value="post" label="POST" />
                                        </flux:radio.group>
                                    </div>

                                    <div class="mb-4">
                                        <flux:textarea
                                            label="Polling Headers (one per line, format: Header: Value)"
                                            wire:model="polling_header"
                                            id="polling_header"
                                            class="mt-1 block w-full font-mono"
                                            name="polling_header"
                                            rows="3"
                                            placeholder="Authorization: Bearer ey.*******&#10;Content-Type: application/json"
                                        />
                                    </div>

                                    @if ($polling_verb === 'post')
                                        <div class="mb-4">
                                            <flux:textarea
                                                label="Polling Body (e.g. for GraphQL queries)"
                                                wire:model="polling_body"
                                                id="polling_body"
                                                class="mt-1 block w-full font-mono"
                                                name="polling_body"
                                                rows="6"
                                            />
                                        </div>
                                    @endif

                                    <div class="mb-4">
                                        <flux:input
                                            label="Data is stale after minutes"
                                            wire:model="data_stale_minutes"
                                            id="data_stale_minutes"
                                            class="mt-1 block w-full"
                                            type="number"
                                            name="data_stale_minutes"
                                        />
                                    </div>
                                </div>

                            </div>
                        </div>
                    @elseif ($data_strategy === 'webhook')
                        <div class="mb-4">
                            <flux:field>
                                <flux:label>Webhook URL</flux:label>
                                <flux:input
                                    :value="route('api.custom_plugins.webhook', ['plugin' => $plugin->uuid])"
                                    class="mt-1 block w-full font-mono"
                                    readonly
                                    copyable
                                />
                            </flux:field>
                            <flux:modal.trigger name="webhook-instructions">
                                <flux:button class="mt-3 w-full" icon="information-circle">
                                    How to push data?
                                </flux:button>
                            </flux:modal.trigger>
                        </div>
                    @elseif ($data_strategy === 'static' && $plugin->runsStaticTransform())
                        <flux:text class="mb-2">Data comes from the transform on every refresh.</flux:text>
                        <flux:button icon="arrow-path" wire:click="updateData" class="mb-4 w-full">
                            Run transform now
                        </flux:button>
                    @elseif ($data_strategy === 'static')
                        <flux:text class="mb-2">Enter static JSON data in the Data Payload field.</flux:text>
                    @endif

                    <div class="mb-4">
                        <flux:label>Screen Settings</flux:label>
                        <div class="mt-2 space-y-2">
                            <flux:checkbox
                                wire:model="no_bleed"
                                label="Remove bleed margin?"
                                description="If selected, padding around your markup will be removed."
                            />
                            <flux:checkbox
                                wire:model="dark_mode"
                                label="Enable Dark Mode?"
                                description="Inverts black/white pixels for the entire screen. Add class 'image' to img tags as needed."
                            />
                        </div>
                    </div>

                    <div class="flex">
                        <flux:spacer />
                        <flux:button type="submit" variant="primary" class="w-full">Save</flux:button>
                    </div>
                </form>
            </div>
            <div>
                <div class="mb-1">
                    <flux:label>Data Payload</flux:label>
                    @isset($this->data_payload_updated_at)
                        <flux:badge
                            icon="clock"
                            size="sm"
                            variant="pill"
                            class="ml-2"
                        >{{ $this->data_payload_updated_at?->diffForHumans() ?? 'Never' }}</flux:badge>
                    @endisset
                    @if ($this->showsTransformFailure())
                        <flux:badge icon="exclamation-triangle" size="sm" variant="pill" color="red" class="ml-2">
                            {{ $plugin->runsStaticTransform() ? 'Stale' : 'Untransformed' }}
                        </flux:badge>
                    @endif
                </div>
                @if ($this->showsTransformFailure())
                    <flux:callout variant="danger" icon="exclamation-triangle" class="mb-3">
                        <flux:callout.heading>
                            Transform failing since {{ $plugin->transform_failed_at?->diffForHumans() }}
                        </flux:callout.heading>
                        <flux:callout.text>
                            @if ($plugin->runsStaticTransform())
                                The data payload holds the last successful transform output.
                            @else
                                The data payload holds the untransformed data. If the template needs the transformed data,
                                rendering fails and the device shows the error screen.
                            @endif
                        </flux:callout.text>
                        <pre class="mt-2 max-h-48 overflow-auto font-mono text-xs whitespace-pre-wrap wrap-anywhere text-red-800 dark:text-red-200">{{ $plugin->transform_error }}</pre>
                    </flux:callout>
                @endif
                <flux:error name="data_payload" />
                <flux:field>
                    @php
                        $textareaId = 'payload-'.uniqid();
                    @endphp
                    <flux:textarea
                        wire:model="data_payload"
                        id="{{ $textareaId }}"
                        placeholder="Enter your HTML code here..."
                        rows="12"
                        hidden
                    />
                    <div
                        x-data="codeEditorFormComponent({
                                isDisabled: @js($data_strategy !== 'static' || $plugin->runsStaticTransform()),
                                language: 'json',
                                state: $wire.entangle('data_payload'),
                                textareaId: @js($textareaId)
                            })"
                        wire:ignore
                        wire:key="cm-{{ $textareaId }}"
                        class="h-[565px] min-h-[300px] max-w-2xl resize-y overflow-hidden"
                    >
                        <!-- Loading state -->
                        <div x-show="isLoading" class="flex h-full items-center justify-center">
                            <div class="flex items-center space-x-2">
                                <flux:icon.loading />
                            </div>
                        </div>

                        <!-- Editor container -->
                        <div x-show="! isLoading" x-ref="editor" class="h-full"></div>
                    </div>
                    <flux:description class="mt-2">
                        In the markup, the data payload can be accessed using the <code>data</code> variable.
                    </flux:description>
                </flux:field>
            </div>
        </div>
        <flux:separator class="my-5" />
        <div>
            <h3 class="text-xl font-semibold dark:text-gray-100">Markup</h3>
            @if ($this->showsBladeDisabledCallout())
                <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4 mb-4">
                    <flux:callout.heading>Blade markup is disabled</flux:callout.heading>
                    <flux:callout.text>
                        Preview and device rendering of Blade templates are disabled because Blade executes PHP. To
                        compile user authored Blade, set <code>INSECURE_BLADE_ALLOW_PHP_EXECUTION=true</code> in the
                        environment. This should only be allowed if all users on the server are trusted, as PHP code can
                        access data across users.
                    </flux:callout.text>
                </flux:callout>
            @endif
            @if ($plugin->render_markup_view)
                <div>
                    Edit view
                    <span class="text-accent mb-4 font-mono">{{ $plugin->render_markup_view }}</span> to update.
                </div>
                <div class="mt-4 mb-4 flex items-center gap-6">
                    <div class="flex flex-1 items-center">
                        <span class="pr-2">Template language</span>
                        <span>{{ $markup_language === 'liquid' ? 'Liquid' : 'Blade' }}</span>
                    </div>
                </div>
                <div class="mt-4 mb-4">
                    <flux:field>
                        @php
                            $textareaId = 'code-view-'.uniqid();
                        @endphp
                        <flux:textarea
                            wire:model="view_content"
                            id="{{ $textareaId }}"
                            placeholder="Enter your HTML code here..."
                            rows="25"
                            hidden
                        />
                        <div
                            x-data="codeEditorFormComponent({
                                isDisabled: @js((bool) $plugin->render_markup_view),
                                language: @js($markup_language === 'liquid' ? 'liquid' : 'html'),
                                state: $wire.entangle('view_content'),
                                textareaId: @js($textareaId)
                            })"
                            wire:ignore
                            wire:key="cm-{{ $textareaId }}-{{ $markup_language }}"
                            class="h-[300px] min-h-[300px] resize-y overflow-hidden"
                        >
                            <!-- Loading state -->
                            <div x-show="isLoading" class="flex h-full items-center justify-center">
                                <div class="flex items-center space-x-2">
                                    <flux:icon.loading />
                                </div>
                            </div>

                            <!-- Editor container -->
                            <div x-show="! isLoading" x-ref="editor" class="h-full"></div>
                        </div>
                    </flux:field>
                </div>
            @else
                <div class="mt-4 mb-4 flex items-center gap-6">
                    <div class="flex flex-1 items-center">
                        <span class="pr-2">Template language</span>
                        <flux:radio.group wire:model.live="markup_language" variant="segmented">
                            <flux:radio value="blade" label="Blade" />
                            <flux:radio value="liquid" label="Liquid" />
                        </flux:radio.group>
                    </div>
                    <div class="text-accent flex items-center gap-2">
                        <span class="pr-2">Getting started</span>
                        <flux:button wire:click="renderExample('layoutTitle')" class="text-xl"
                            >Responsive Layout with Title Bar</flux:button>
                        <flux:button wire:click="renderExample('layout')" class="text-xl"
                            >Responsive Layout</flux:button>
                    </div>
                </div>
            @endif
        </div>
        @if (! $plugin->render_markup_view)
            <form wire:submit="saveMarkup">
                <div class="mb-4">
                    <div>
                        <div class="flex items-end">
                            @foreach ($active_tabs as $tab)
                                <button
                                    type="button"
                                    wire:click="switchTab('{{ $tab }}')"
                                    class="tab-button {{ $active_tab === $tab ? 'is-active' : '' }}"
                                    wire:key="tab-{{ $tab }}"
                                >
                                    {{ $this->getLayoutLabel($tab) }}
                                </button>
                            @endforeach

                            @if ($this->showsTransformTab())
                                <button
                                    type="button"
                                    wire:click="switchTab('transform')"
                                    class="tab-button {{ $active_tab === 'transform' ? 'is-active' : '' }}"
                                    wire:key="tab-transform"
                                >
                                    <flux:icon.bolt class="size-4" />
                                    Transform
                                </button>
                            @endif

                            <flux:dropdown>
                                <flux:button icon="plus" variant="ghost" size="sm" class="m-0.5"></flux:button>
                                <flux:menu>
                                    @foreach ($this->getAvailableLayouts() as $layout => $label)
                                        <flux:menu.item wire:click="toggleLayoutTab('{{ $layout }}')">
                                            <div class="flex items-center gap-2">
                                                @if (in_array($layout, $active_tabs, true))
                                                    <flux:icon.check class="size-4" />
                                                @else
                                                    <span class="inline-block h-4 w-4"></span>
                                                @endif
                                                <span>{{ $label }}</span>
                                            </div>
                                        </flux:menu.item>
                                    @endforeach
                                    @if ($this->transformIsSupported())
                                        <flux:menu.separator />
                                        <flux:menu.item wire:click="toggleTransformTab">
                                            <div class="flex items-center gap-2">
                                                @if ($this->showsTransformTab())
                                                    <flux:icon.check class="size-4" />
                                                @else
                                                    <span class="inline-block h-4 w-4"></span>
                                                @endif
                                                <span>Transform</span>
                                            </div>
                                        </flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        </div>

                        <div class="styled-container flex-col rounded-tl-none bg-transparent p-4">
                            @if ($active_tab === 'transform' && $this->showsTransformTab())
                                <div class="space-y-4" wire:key="transform-editor">
                                    @php
                                        $runnableTransformLanguages = $this->transformLanguages();
                                        $transformTextareaId = 'transform-code-'.$plugin->id;
                                    @endphp

                                    @if ($this->showsTransformFailure())
                                        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-3">
                                            <flux:callout.heading>
                                                Transform failing since {{ $plugin->transform_failed_at?->diffForHumans() }}
                                            </flux:callout.heading>
                                            <flux:callout.text>
                                                The data payload holds the untransformed data. If the template needs the transformed data,
                                                rendering fails and the device shows the error screen.
                                            </flux:callout.text>
                                            <pre class="mt-2 max-h-48 overflow-auto font-mono text-xs whitespace-pre-wrap wrap-anywhere text-red-800 dark:text-red-200">{{ $plugin->transform_error }}</pre>
                                        </flux:callout>
                                    @endif

                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <flux:radio.group wire:model.live="transform_language" variant="segmented" size="sm">
                                            @foreach ($this->transformLanguageOptions() as $language)
                                                <flux:radio value="{{ $language }}" label="{{ $this->transformLanguageLabel($language) }}" />
                                            @endforeach
                                        </flux:radio.group>
                                        <flux:button type="button" wire:click="renderTransformExample" size="sm" variant="ghost">
                                            Insert example
                                        </flux:button>
                                    </div>
                                    <flux:error name="transform_language" />

                                    @if (! $this->transformIsEnabled())
                                        <flux:callout variant="warning" icon="exclamation-triangle">
                                            <flux:callout.heading>No Transform Provider available</flux:callout.heading>
                                            <flux:callout.text>
                                                Transform code can be edited and saved, but it does not run until a Transform
                                                Provider is configured. See the
                                                <a href="https://usetrmnl.github.io/larapaper/usage/transform" class="underline" target="_blank" rel="noopener noreferrer">Transform documentation</a>.
                                            </flux:callout.text>
                                        </flux:callout>
                                    @elseif ($runnableTransformLanguages === [])
                                        <flux:callout variant="warning" icon="exclamation-triangle">
                                            <flux:callout.heading>The transform runner is not available</flux:callout.heading>
                                            <flux:callout.text>
                                                The runner at <code class="text-xs">TRANSFORM_RUNNER_URL</code> did not answer
                                                <code class="text-xs">GET /manifest</code> with its language capabilities, so
                                                transforms cannot run and the untransformed data is stored.
                                            </flux:callout.text>
                                        </flux:callout>
                                    @elseif (! in_array($transform_language, $runnableTransformLanguages, true))
                                        <flux:callout variant="warning" icon="exclamation-triangle">
                                            <flux:callout.heading>
                                                {{ $this->transformLanguageLabel($transform_language) }} is not supported by the
                                                transform runner
                                            </flux:callout.heading>
                                            <flux:callout.text>
                                                You can edit and save this transform, but it cannot run: the untransformed
                                                data is stored. Supported here:
                                                {{ collect($runnableTransformLanguages)->map(fn (string $language): string => $this->transformLanguageLabel($language))->join(', ') }}.
                                            </flux:callout.text>
                                        </flux:callout>
                                    @endif

                                    <flux:field>
                                        <flux:label>Transform script</flux:label>
                                        <flux:textarea wire:model="transform_code" id="{{ $transformTextareaId }}" rows="16" hidden />
                                        <div
                                            x-data="codeEditorFormComponent({
                                                isDisabled: false,
                                                language: @js($this->transformEditorLanguage()),
                                                state: $wire.entangle('transform_code'),
                                                textareaId: @js($transformTextareaId)
                                            })"
                                            wire:ignore
                                            wire:key="transform-cm-{{ $plugin->id }}-{{ $transform_language }}"
                                            class="h-[300px] min-h-[200px] resize-y overflow-hidden"
                                        >
                                            <div x-show="isLoading" class="flex h-full items-center justify-center">
                                                <flux:icon.loading />
                                            </div>
                                            <div x-show="! isLoading" x-ref="editor" class="h-full"></div>
                                        </div>
                                        <flux:error name="transform_code" />
                                        <flux:description>
                                            @if ($data_strategy === 'static')
                                                <code class="text-xs">run(input)</code> runs on every refresh instead of polling and receives only
                                            @else
                                                <code class="text-xs">run(input)</code> receives the polled JSON (several URLs
                                                arrive as <code class="text-xs">IDX_0</code>, <code class="text-xs">IDX_1</code>, …) plus
                                            @endif
                                            <code class="text-xs">input.trmnl</code> with <code class="text-xs">user</code> and
                                            <code class="text-xs">plugin_settings.custom_fields_values</code>. The returned object
                                            replaces the data payload.
                                            @if ($data_strategy === 'static')
                                                If it fails, the last output is kept.
                                            @else
                                                Save to apply; if the transform fails, the untransformed data is stored.
                                            @endif
                                            See the
                                            <a href="https://help.trmnl.com/en/articles/14130649-serverless" class="underline" target="_blank" rel="noopener noreferrer">TRMNL serverless documentation</a>.
                                        </flux:description>
                                    </flux:field>

                                    <div class="flex items-center gap-3">
                                        <flux:button
                                            type="button"
                                            icon="play"
                                            size="sm"
                                            wire:click="runTransform"
                                            wire:target="runTransform"
                                            :disabled="! in_array($transform_language, $runnableTransformLanguages, true)"
                                        >
                                            Run
                                        </flux:button>
                                        <flux:text class="text-sm">
                                            {{ $data_strategy === 'static' ? 'Runs the code above without saving.' : 'Fetches the polling URLs and runs the code above without saving.' }}
                                        </flux:text>
                                    </div>

                                    @if ($transform_run)
                                        <div class="space-y-2" wire:key="transform-run-result">
                                            <div class="flex items-center gap-2">
                                                <flux:heading size="sm">Result</flux:heading>
                                                @if ($transform_run['error'] === null)
                                                    <flux:badge size="sm" color="green">OK</flux:badge>
                                                    <flux:badge size="sm" icon="clock">{{ $transform_run['duration_ms'] }} ms</flux:badge>
                                                @else
                                                    <flux:badge size="sm" color="red">Failed</flux:badge>
                                                @endif
                                            </div>
                                            @if ($transform_run['error'] !== null)
                                                <flux:callout variant="danger" icon="x-circle">
                                                    <flux:callout.text>{{ $transform_run['error'] }}</flux:callout.text>
                                                </flux:callout>
                                            @else
                                                @php
                                                    $transformResultId = 'transform-result-'.md5((string) $transform_run['output']);
                                                @endphp
                                                <textarea id="{{ $transformResultId }}" hidden>{{ $transform_run['output'] }}</textarea>
                                                <div
                                                    x-data="codeEditorFormComponent({
                                                        isDisabled: true,
                                                        language: 'json',
                                                        state: @js($transform_run['output']),
                                                        textareaId: @js($transformResultId)
                                                    })"
                                                    wire:ignore
                                                    wire:key="cm-{{ $transformResultId }}"
                                                    class="h-[300px] min-h-[150px] resize-y overflow-hidden"
                                                >
                                                    <div x-show="isLoading" class="flex h-full items-center justify-center">
                                                        <flux:icon.loading />
                                                    </div>
                                                    <div x-show="! isLoading" x-ref="editor" class="h-full"></div>
                                                </div>
                                            @endif
                                            @if (filled($transform_run['logs']))
                                                <flux:text class="text-sm">Console output</flux:text>
                                                <pre class="max-h-48 overflow-auto rounded-lg bg-zinc-50 p-3 font-mono text-xs whitespace-pre-wrap wrap-anywhere text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">{{ $transform_run['logs'] }}</pre>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @else
                            <flux:field>
                                @php
                                    $textareaId = 'code-'.$plugin->id;
                                @endphp
                                <flux:label>{{ $markup_language === 'liquid' ? 'Liquid Code' : 'Blade Code' }}</flux:label>
                                <flux:textarea
                                    wire:model="markup_code"
                                    id="{{ $textareaId }}"
                                    placeholder="Enter your HTML code here..."
                                    rows="25"
                                    hidden
                                />
                                <div
                                    x-data="codeEditorFormComponent({
                                        isDisabled: false,
                                        language: @js($markup_language === 'liquid' ? 'liquid' : 'html'),
                                        state: $wire.entangle('markup_code'),
                                        textareaId: @js($textareaId)
                                    })"
                                    wire:ignore
                                    wire:key="cm-{{ $textareaId }}-{{ $markup_language }}"
                                    class="h-[300px] min-h-[300px] resize-y overflow-hidden"
                                >
                                    <!-- Loading state -->
                                    <div x-show="isLoading" class="flex h-full items-center justify-center">
                                        <div class="flex items-center space-x-2">
                                            <flux:icon.loading />
                                        </div>
                                    </div>

                                    <!-- Editor container -->
                                    <div x-show="! isLoading" x-ref="editor" class="h-full"></div>
                                </div>
                            </flux:field>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex">
                    <flux:button type="submit" variant="primary"> Save </flux:button>
                </div>
            </form>
        @endif
    </div>
</div>

@script
    <script>
        let previewNativeWidth = 800;
        let previewNativeHeight = 480;
        let previewResizeObserver = null;

        function positiveNumberOrFallback(value, fallback) {
            const n = Number(value);

            return n > 0 ? n : fallback;
        }

        function fitPreviewToStage() {
            const stage = document.getElementById('preview-stage');
            const layout = document.getElementById('preview-layout');
            const scaler = document.getElementById('preview-scaler');
            const frame = document.getElementById('preview-frame');

            if (!stage || !layout || !scaler || !frame) {
                return;
            }

            const w = previewNativeWidth;
            const h = previewNativeHeight;

            if (w <= 0 || h <= 0) {
                return;
            }

            const stageW = stage.clientWidth;
            const stageH = stage.clientHeight;

            if (stageW <= 0 || stageH <= 0) {
                return;
            }

            const scale = Math.min(1, stageW / w, stageH / h);

            layout.style.width = `${w * scale}px`;
            layout.style.height = `${h * scale}px`;
            scaler.style.width = `${w}px`;
            scaler.style.height = `${h}px`;
            scaler.style.transform = `scale(${scale})`;
        }

        function ensurePreviewResizeObserver() {
            const stage = document.getElementById('preview-stage');

            if (!stage || previewResizeObserver) {
                return;
            }

            previewResizeObserver = new ResizeObserver(() => fitPreviewToStage());
            previewResizeObserver.observe(stage);
        }

        $wire.on('preview-updated', ({ preview, screenWidth, screenHeight }) => {
            $wire.preview_image_url = null;
            previewNativeWidth = positiveNumberOrFallback(screenWidth, 800);
            previewNativeHeight = positiveNumberOrFallback(screenHeight, 480);

            const frame = document.getElementById('preview-frame');

            if (!frame) {
                return;
            }

            frame.setAttribute('width', String(previewNativeWidth));
            frame.setAttribute('height', String(previewNativeHeight));

            const frameDoc = frame.contentDocument || frame.contentWindow.document;

            // Reset the iframe state before writing new content
            frame.src = 'about:blank';

            // Re-populating the iframe
            const writeToFrame = () => {
                const newFrameDoc = frame.contentDocument || frame.contentWindow.document;
                newFrameDoc.open();
                newFrameDoc.write(preview);
                newFrameDoc.close();

                requestAnimationFrame(() => {
                    fitPreviewToStage();
                    ensurePreviewResizeObserver();
                });
            };

            // If iframe is already on about:blank, we can write immediately,
            // otherwise wait for the load event once.
            if (frame.contentWindow.location.href === 'about:blank') {
                writeToFrame();
            } else {
                frame.onload = () => {
                    frame.onload = null; // Remove listener
                    writeToFrame();
                };
            }
        });

        $wire.on('preview-image-updated', ({ imageUrl, screenWidth, screenHeight }) => {
            previewNativeWidth = positiveNumberOrFallback(screenWidth, 800);
            previewNativeHeight = positiveNumberOrFallback(screenHeight, 480);

            const img = document.getElementById('preview-image');

            if (!img) {
                return;
            }

            img.setAttribute('width', String(previewNativeWidth));
            img.setAttribute('height', String(previewNativeHeight));

            requestAnimationFrame(() => {
                fitPreviewToStage();
                ensurePreviewResizeObserver();
            });
        });

        $wire.on('preview-error', ({ message }) => {
            alert('Preview Error: ' + message);
        });

        $wire.on('data-update-error', ({ message }) => {
            alert('Data Update Error: ' + message);
        });
    </script>
@endscript
