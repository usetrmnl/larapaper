<?php

use App\Services\PluginImportService;
use App\Settings\CatalogSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Stevebauman\Purify\Facades\Purify;
use Symfony\Component\Yaml\Yaml;

new
#[Lazy]
class extends Component
{
    public array $catalogPlugins = [];

    public string $installingPlugin = '';

    public string $previewingPlugin = '';

    public array $previewData = [];

    public bool $ossWarningDismissed = false;

    public function mount(CatalogSettings $catalogSettings): void
    {
        $this->ossWarningDismissed = $catalogSettings->oss_3rd_party_warning_dismissed;
        $this->loadCatalogPlugins();
    }

    public function dismissOssWarning(CatalogSettings $catalogSettings): void
    {
        abort_unless(auth()->user() !== null, 403);

        $catalogSettings->oss_3rd_party_warning_dismissed = true;
        $catalogSettings->save();

        $this->ossWarningDismissed = true;
    }

    public function placeholder()
    {
        return <<<'HTML'
         <div class="space-y-4">
            <div class="flex items-center justify-center py-12">
                <div class="flex items-center space-x-2">
                    <flux:icon.loading />
                    <flux:text>Loading recipes...</flux:text>
                </div>
            </div>
        </div>
        HTML;
    }

    private function loadCatalogPlugins(): void
    {
        $catalogUrl = config('app.catalog_url');

        $this->catalogPlugins = Cache::remember('catalog_plugins', 43200, function () use ($catalogUrl) {
            try {
                $response = Http::timeout(10)->get($catalogUrl);
                $catalogContent = $response->body();
                $catalog = Yaml::parse($catalogContent);

                $currentVersion = config('app.version');

                return collect($catalog)
                    ->filter(function ($plugin) use ($currentVersion) {
                        // Check if Laravel compatibility is true
                        if (! Arr::get($plugin, 'byos.byos_laravel.compatibility', false)) {
                            return false;
                        }

                        // Check minimum version if specified
                        $minVersion = Arr::get($plugin, 'byos.byos_laravel.min_version');
                        if ($minVersion && $currentVersion && version_compare($currentVersion, $minVersion, '<')) {
                            return false;
                        }

                        return true;
                    })
                    ->map(function ($plugin, $key) {
                        return [
                            'id' => $key,
                            'name' => Arr::get($plugin, 'name', 'Unknown Plugin'),
                            'description' => Purify::clean(Arr::get($plugin, 'author_bio.description')),
                            'author' => Arr::get($plugin, 'author.name', 'Unknown Author'),
                            'github' => Arr::get($plugin, 'author.github'),
                            'license' => Arr::get($plugin, 'license'),
                            'zip_url' => Arr::get($plugin, 'trmnlp.zip_url'),
                            'zip_entry_path' => Arr::get($plugin, 'trmnlp.zip_entry_path'),
                            'repo_url' => Arr::get($plugin, 'trmnlp.repo'),
                            'logo_url' => Arr::get($plugin, 'logo_url'),
                            'screenshot_url' => Arr::get($plugin, 'screenshot_url'),
                            'learn_more_url' => Arr::get($plugin, 'author_bio.learn_more_url'),
                            'preferred_renderer' => Arr::get($plugin, 'byos.byos_laravel.renderer'),
                        ];
                    })
                    ->sortBy('name')
                    ->toArray();
            } catch (Exception $e) {
                Log::error('Failed to load catalog from URL: '.$e->getMessage());

                return [];
            }
        });
    }

    public function installPlugin(string $pluginId, PluginImportService $pluginImportService): void
    {
        abort_unless(auth()->user() !== null, 403);

        $plugin = collect($this->catalogPlugins)->firstWhere('id', $pluginId);

        if (! $plugin || ! $plugin['zip_url']) {
            Flux::toast(variant: 'danger', text: 'Plugin not found or no download URL available.');

            return;
        }

        $this->installingPlugin = $pluginId;

        try {
            $importedPlugin = $pluginImportService->importFromUrl(
                $plugin['zip_url'],
                auth()->user(),
                $plugin['zip_entry_path'] ?? null,
                config('services.trmnl.liquid_enabled') ? $plugin['preferred_renderer'] : null,
                $plugin['logo_url'] ?? null,
                allowDuplicate: true
            );

            $this->dispatch('plugin-installed');
            Flux::toast(variant: 'success', text: "\"{$importedPlugin->name}\" installed successfully.");

        } catch (Exception $e) {
            Flux::toast(variant: 'danger', text: 'Error installing plugin: '.$e->getMessage());
        } finally {
            $this->installingPlugin = '';
        }
    }

    public function previewPlugin(string $pluginId): void
    {
        $plugin = collect($this->catalogPlugins)->firstWhere('id', $pluginId);

        if (! $plugin) {
            $this->addError('preview', 'Plugin not found.');

            return;
        }

        $this->previewingPlugin = $pluginId;
        $this->previewData = $plugin;
    }

    public function closePreview(): void
    {
        $this->previewingPlugin = '';
        $this->previewData = [];
    }
}; ?>

<div class="space-y-4">
    @unless($ossWarningDismissed)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>Recipes are not reviewed</flux:callout.heading>
            <flux:callout.text>
                Recipes in the OSS Catalog come from third parties and are not reviewed.
                They may contain malicious code. Review the code in the linked Git repository before installing.
            </flux:callout.text>
            <x-slot name="controls">
                <flux:button icon="x-mark" variant="ghost" wire:click="dismissOssWarning" />
            </x-slot>
        </flux:callout>
    @endunless

    @if(empty($catalogPlugins))
        <div class="text-center py-8">
            <flux:icon name="exclamation-triangle" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading class="mt-2">No plugins available</flux:heading>
            <flux:subheading>Catalog is empty</flux:subheading>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4">
            @foreach($catalogPlugins as $plugin)
                <div wire:key="plugin-{{ $plugin['id'] }}" class="bg-white dark:bg-white/10 border border-zinc-200 dark:border-white/10 [:where(&)]:p-6 [:where(&)]:rounded-xl space-y-6">
                    <div class="flex items-start space-x-4">
                        @if($plugin['logo_url'])
                            <img src="{{ $plugin['logo_url'] }}" loading="lazy" alt="{{ $plugin['name'] }}" class="w-12 h-12 rounded-lg object-cover">
                        @else
                            <div class="w-12 h-12 bg-zinc-200 dark:bg-zinc-700 rounded-lg flex items-center justify-center">
                                <flux:icon name="puzzle-piece" class="w-6 h-6 text-zinc-400" />
                            </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between">
                                <div>
                                    <flux:heading size="lg">{{ $plugin['name'] }}</flux:heading>
                                    @if ($plugin['github'])
                                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">by {{ $plugin['github'] }}</flux:text>
                                    @endif
                                </div>
                                <div class="flex items-center space-x-2">
                                    @if($plugin['license'])
                                        <flux:badge color="zinc" size="sm">{{ $plugin['license'] }}</flux:badge>
                                    @endif
                                    @if($plugin['repo_url'])
                                        <a href="{{ $plugin['repo_url'] }}" target="_blank" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                            <flux:icon name="github" class="w-5 h-5" />
                                        </a>
                                    @endif
                                </div>
                            </div>

                            @if($plugin['description'])
                                <flux:text class="mt-2" size="sm">{!! \Stevebauman\Purify\Facades\Purify::clean($plugin['description']) !!}</flux:text>
                            @endif

                            <div class="mt-4 flex items-center space-x-3">
                                <flux:button
                                    wire:click="installPlugin('{{ $plugin['id'] }}')"
                                    variant="primary">
                                    Install
                                </flux:button>

                                @if($plugin['screenshot_url'])
                                    <flux:modal.trigger name="catalog-preview">
                                        <flux:button
                                            wire:click="previewPlugin('{{ $plugin['id'] }}')"
                                            variant="subtle"
                                            icon="eye">
                                            Preview
                                        </flux:button>
                                    </flux:modal.trigger>
                                @endif



                                @if($plugin['learn_more_url'])
                                    <flux:button
                                        href="{{ $plugin['learn_more_url'] }}"
                                        target="_blank"
                                        variant="subtle">
                                        Learn More
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Preview Modal -->
    <flux:modal name="catalog-preview" class="min-w-[850px] min-h-[480px] space-y-6">
        @if($previewingPlugin && ! empty($previewData))
            <div>
                <flux:heading size="lg">Preview {{ $previewData['name'] ?? 'Plugin' }}</flux:heading>
            </div>

            <div class="space-y-4">
                <div class="bg-white dark:bg-zinc-900 rounded-lg overflow-hidden">
                    <img src="{{ $previewData['screenshot_url'] }}"
                         alt="Preview of {{ $previewData['name'] }}"
                         class="w-full h-auto max-h-[480px] object-contain">
                </div>

                @if($previewData['description'])
                    <div class="p-4 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
                        <flux:heading size="sm" class="mb-2">Description</flux:heading>
                        <flux:text size="sm">{!! \Stevebauman\Purify\Facades\Purify::clean($previewData['description']) !!}</flux:text>
                    </div>
                @endif

                <div class="flex items-center justify-end pt-4 border-t border-zinc-200 dark:border-zinc-700 space-x-3">
                    <flux:modal.close>
                        <flux:button
                            wire:click="installPlugin('{{ $previewingPlugin }}')"
                            variant="primary">
                            Install Plugin
                        </flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
