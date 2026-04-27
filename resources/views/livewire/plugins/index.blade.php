<?php

use App\Console\Commands\ExampleRecipesSeederCommand;
use App\Services\PluginImportService;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public string $name;

    public int $data_stale_minutes = 60;

    public string $data_strategy = 'polling';

    public string $polling_url;

    public string $polling_verb = 'get';

    public $polling_header;

    public $polling_body;

    public array $plugins;

    public $zipFile;

    public string $sortBy = 'date_asc';

    public array $native_plugins = [
        'markup' => ['name' => 'Markup', 'flux_icon_name' => 'code-bracket', 'detail_view_route' => 'plugins.markup'],
        'api' => ['name' => 'API', 'flux_icon_name' => 'braces', 'detail_view_route' => 'plugins.api'],
        'image-webhook' => ['name' => 'Image Webhook', 'flux_icon_name' => 'photo', 'detail_view_route' => 'plugins.image-webhook'],
    ];

    protected $rules = [
        'name' => 'required|string|max:255',
        'data_stale_minutes' => 'required|integer|min:1',
        'data_strategy' => 'required|string|in:polling,webhook,static',
        'polling_url' => 'required_if:data_strategy,polling|nullable|url',
        'polling_verb' => 'required|string|in:get,post',
        'polling_header' => 'nullable|string|max:10240',
        'polling_body' => 'nullable|string',
    ];

    public function refreshPlugins(): void
    {
        // Only show recipe plugins in the main list (image_webhook has its own management page)
        $userPlugins = auth()->user()?->plugins()
            ->where('plugin_type', 'recipe')
            ->get()
            ->makeHidden(['render_markup', 'data_payload'])
            ->toArray();
        $allPlugins = array_merge($this->native_plugins, $userPlugins ?? []);
        $allPlugins = array_values($allPlugins);
        $allPlugins = $this->sortPlugins($allPlugins);
        $this->plugins = $allPlugins;
    }

    protected function sortPlugins(array $plugins): array
    {
        $pluginsToSort = array_values($plugins);

        switch ($this->sortBy) {
            case 'name_asc':
                usort($pluginsToSort, function ($a, $b) {
                    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
                });
                break;

            case 'name_desc':
                usort($pluginsToSort, function ($a, $b) {
                    return strcasecmp($b['name'] ?? '', $a['name'] ?? '');
                });
                break;

            case 'date_desc':
                usort($pluginsToSort, function ($a, $b) {
                    $aDate = $a['created_at'] ?? '1970-01-01';
                    $bDate = $b['created_at'] ?? '1970-01-01';

                    return strcmp($bDate, $aDate);
                });
                break;

            case 'date_asc':
                usort($pluginsToSort, function ($a, $b) {
                    $aDate = $a['created_at'] ?? '1970-01-01';
                    $bDate = $b['created_at'] ?? '1970-01-01';

                    return strcmp($aDate, $bDate);
                });
                break;
        }

        return $pluginsToSort;
    }

    public function mount(): void
    {
        $this->refreshPlugins();
    }

    public function updatedSortBy(): void
    {
        $this->refreshPlugins();
    }

    public function getListeners(): array
    {
        return [
            'plugin-installed' => 'refreshPlugins',
        ];
    }

    public function addPlugin(): void
    {
        abort_unless(auth()->user() !== null, 403);
        $this->validate();

        App\Models\Plugin::create([
            'uuid' => Str::uuid(),
            'user_id' => auth()->id(),
            'name' => $this->name,
            'data_stale_minutes' => $this->data_stale_minutes,
            'data_strategy' => $this->data_strategy,
            'polling_url' => $this->polling_url ?? null,
            'polling_verb' => $this->polling_verb,
            'polling_header' => $this->polling_header,
            'polling_body' => $this->polling_body,
        ]);

        $this->reset(['name', 'data_stale_minutes', 'data_strategy', 'polling_url', 'polling_verb', 'polling_header', 'polling_body']);
        $this->refreshPlugins();

        Flux::modal('add-plugin')->close();
    }

    public function seedExamplePlugins(): void
    {
        Artisan::call(ExampleRecipesSeederCommand::class, ['user_id' => auth()->id()]);
        $this->refreshPlugins();
    }

    public function importZip(PluginImportService $pluginImportService): void
    {
        abort_unless(auth()->user() !== null, 403);

        $this->validate([
            'zipFile' => 'required|file|mimes:zip|max:10240', // 10MB max
        ]);

        try {
            $plugin = $pluginImportService->importFromZip($this->zipFile, auth()->user());

            $this->refreshPlugins();
            $this->reset(['zipFile']);

            Flux::modal('import-zip')->close();
        } catch (Exception $e) {
            $this->addError('zipFile', 'Error installing plugin: '.$e->getMessage());
        }
    }
};
?>

<div class="py-12" x-data="{
    searchTerm: '',
    showFilters: false,
    filterPlugins(plugins) {
        if (this.searchTerm.length <= 1) return plugins;
        const search = this.searchTerm.toLowerCase();
        return plugins.filter(p => p.name.toLowerCase().includes(search));
    }
}">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-semibold dark:text-gray-100">Plugins &amp; Recipes</h2>
            <div class="flex items-center space-x-2">
                <flux:button icon="funnel" variant="ghost" @click="showFilters = !showFilters"></flux:button>
                <flux:button.group>
                    <flux:modal.trigger name="add-plugin">
                        <flux:button icon="plus" variant="primary">Add Recipe</flux:button>
                    </flux:modal.trigger>

                    <flux:dropdown>
                        <flux:button icon="chevron-down" variant="primary"></flux:button>
                        <flux:menu>
                            <flux:modal.trigger name="import-from-catalog">
                                <flux:menu.item icon="book-open">Import from OSS Catalog</flux:menu.item>
                            </flux:modal.trigger>
                            @if(config('services.trmnl.liquid_enabled'))
                                <flux:modal.trigger name="import-from-trmnl-catalog">
                                    <flux:menu.item icon="book-open">Import from TRMNL Catalog</flux:menu.item>
                                </flux:modal.trigger>
                            @endif
                            <flux:separator />
                            <flux:modal.trigger name="import-zip">
                                <flux:menu.item icon="archive-box">Import Recipe Archive</flux:menu.item>
                            </flux:modal.trigger>
                            <flux:separator />
                            <flux:menu.item icon="beaker" wire:click="seedExamplePlugins">Seed Example Recipes</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </flux:button.group>
            </div>
        </div>

        <div x-show="showFilters" class="mb-6 flex flex-col sm:flex-row gap-4" style="display: none;">
            <div class="flex-1">
                <flux:input
                    x-model="searchTerm"
                    placeholder="Search plugins by name (min. 2 characters)..."
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-64">
                <flux:select wire:model.live="sortBy">
                    <option value="date_asc">Oldest First</option>
                    <option value="date_desc">Newest First</option>
                    <option value="name_asc">Name (A-Z)</option>
                    <option value="name_desc">Name (Z-A)</option>
                </flux:select>
            </div>
        </div>

        <div x-show="searchTerm.length > 1" class="mb-4" style="display: none;">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                <span x-text="'Showing results for: ' + searchTerm"></span>
            </p>
        </div>

        <flux:modal name="import-zip" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Import Recipe
                        <flux:badge color="blue" class="ml-2">Beta</flux:badge>
                    </flux:heading>
                    <flux:subheading>Upload a ZIP archive containing a TRMNL recipe — either exported from the cloud service or structured using the <a href="https://github.com/usetrmnl/trmnlp" target="_blank" class="underline">trmnlp</a> project structure.</flux:subheading>
                </div>

                <div class="mb-4">
                    <flux:text>The archive must at least contain <code>settings.yml</code> and <code>full.liquid</code> files.</flux:text>
{{--                    <p>The ZIP file should contain the following structure:</p>--}}
{{--                    <pre class="mt-2 p-2 bg-gray-100 dark:bg-gray-800 rounded text-xs overflow-auto">--}}
{{--.--}}
{{--├── src--}}
{{--│   ├── full.liquid (required)--}}
{{--│   ├── settings.yml (required)--}}
{{--│   └── ...--}}
{{--└── ...--}}
{{--                    </pre>--}}
                </div>

                <div class="mb-4">
                    <flux:heading size="sm">Limitations</flux:heading>
                    <ul class="list-disc pl-5 mt-2">
                        <li><flux:text>Some Liquid filters may be not supported or behave differently</flux:text></li>
                        <li><flux:text>API responses in formats other than JSON are not yet supported</flux:text></li>
{{--                        <ul class="list-disc pl-5 mt-2">--}}
{{--                            <li><flux:text><code>date: "%N"</code> is unsupported. Use <code>date: "u"</code> instead </flux:text></li>--}}
{{--                        </ul>--}}
                    </ul>
                    <flux:text class="mt-1">Please report <a href="https://github.com/usetrmnl/larapaper/issues/new" target="_blank" class="underline">issues on GitHub</a>. Include your example zip file.</flux:text></li>
                </div>

                <form wire:submit="importZip">
                    <div class="mb-4">
                        <flux:label for="zipFile">.zip Archive</flux:label>
                        <input
                            type="file"
                            wire:model="zipFile"
                            id="zipFile"
                            accept=".zip"
                            class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 dark:text-gray-400 focus:outline-none dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 p-2.5"
                        />
                        @error('zipFile')
                            <flux:callout variant="danger" icon="x-circle" heading="{{$message}}" class="mt-2" />
                        @enderror
                    </div>

                    <div class="flex">
                        <flux:spacer/>
                        <flux:button type="submit" variant="primary">Import</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        <flux:modal name="import-from-catalog">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Import from Catalog
                        <flux:badge color="blue" class="ml-2">Beta</flux:badge>
                    </flux:heading>
                    <flux:subheading>Browse and install Recipes from the community. Add yours <a href="https://github.com/bnussbau/trmnl-recipe-catalog" class="underline" target="_blank">here</a>.</flux:subheading>
                </div>
                <livewire:catalog.index />
            </div>
        </flux:modal>

        <flux:modal name="import-from-trmnl-catalog">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Import from TRMNL Recipe Catalog
                        <flux:badge color="yellow" class="ml-2">Alpha</flux:badge>
                    </flux:heading>
                    <flux:callout class="mb-4 mt-4" color="yellow">
                        <flux:heading size="sm">Limitations</flux:heading>
                        <ul class="list-disc pl-5 mt-2">
                            <li><flux:text>Requires <span class="font-mono">trmnl-liquid-cli</span> executable.</flux:text></li>
                            <li><flux:text>API responses in formats other than <span class="font-mono">JSON</span> are not yet fully supported.</flux:text></li>
                            <li><flux:text>There are limitations in payload size (Data Payload, Template).</flux:text></li>
                        </ul>
                        <flux:text class="mt-1">Please report issues, aside from the known limitations, on <a href="https://github.com/usetrmnl/larapaper/issues/new" target="_blank" class="underline">GitHub</a>. Include the recipe URL.</flux:text></li>
                    </flux:callout>
                </div>
                <livewire:catalog.trmnl />
            </div>
        </flux:modal>

        <flux:modal name="add-plugin" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Add Recipe</flux:heading>
                </div>

                <form wire:submit="addPlugin">
                    <div class="mb-4">
                        <flux:input label="Name" wire:model="name" id="name" class="block mt-1 w-full" type="text"
                                    name="name" autofocus/>
                    </div>

                    <div class="mb-4">
                        <flux:radio.group wire:model.live="data_strategy" label="Data Strategy" variant="segmented">
                            <flux:radio value="polling" label="Polling"/>
                            <flux:radio value="webhook" label="Webhook"/>
                            <flux:radio value="static" label="Static"/>
                        </flux:radio.group>
                    </div>

                    @if($data_strategy === 'polling')
                        <div class="mb-4">
                            <flux:input label="Polling URL" wire:model="polling_url" id="polling_url"
                                        placeholder="https://example.com/api"
                                        class="block mt-1 w-full" type="text" name="polling_url" autofocus/>
                        </div>

                        <div class="mb-4">
                            <flux:radio.group wire:model.live="polling_verb" label="Polling Verb" variant="segmented">
                                <flux:radio value="get" label="GET"/>
                                <flux:radio value="post" label="POST"/>
                            </flux:radio.group>
                        </div>

                        <div class="mb-4">
                            <flux:input label="Polling Header" wire:model="polling_header" id="polling_header"
                                        class="block mt-1 w-full" type="text" name="polling_header" autofocus/>
                        </div>

                        @if($polling_verb === 'post')
                        <div class="mb-4">
                            <flux:textarea
                                label="Polling Body"
                                wire:model="polling_body"
                                id="polling_body"
                                class="block mt-1 w-full font-mono"
                                name="polling_body"
                                rows="4"
                                placeholder=''
                            />
                        </div>
                        @endif
                        <div class="mb-4">
                            <flux:input label="Data is stale after minutes" wire:model.live="data_stale_minutes"
                                        id="data_stale_minutes"
                                        class="block mt-1 w-full" type="number" name="data_stale_minutes" autofocus/>
                        </div>
                    @endif

                    <div class="flex">
                        <flux:spacer/>
                        <flux:button type="submit" variant="primary">Create Recipe</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        @php
            $allPlugins = $this->plugins;
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            @foreach($allPlugins as $index => $plugin)
                <div
                    wire:key="plugin-{{ $plugin['id'] ?? $plugin['name'] ?? $index }}"
                    x-data="{ pluginName: {{ json_encode(strtolower($plugin['name'] ?? '')) }} }"
                    x-show="searchTerm.length <= 1 || pluginName.includes(searchTerm.toLowerCase())"
                    class="styled-container">
                    <a href="{{ ($plugin['detail_view_route']) ? route($plugin['detail_view_route']) : route('plugins.recipe', ['plugin' => $plugin['id']]) }}"
                       class="block h-full">
                        <div class="flex items-center space-x-4 px-10 py-8 h-full">
                            @isset($plugin['icon_url'])
                                <img src="{{ $plugin['icon_url'] }}" class="h-6"/>
                            @else
                                <flux:icon name="{{$plugin['flux_icon_name'] ?? 'puzzle-piece'}}"
                                       class="text-4xl text-accent"/>
                            @endif
                            <h3 class="text-lg font-medium dark:text-zinc-200">{{$plugin['name']}}</h3>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</div>
