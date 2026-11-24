<?php

use App\Models\Plugin;
use App\Support\CustomFieldConditions;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * This component contains the configuation modal
 */
new class extends Component
{
    public Plugin $plugin;

    public array $configuration_template = [];

    public array $configuration = []; // holds config data

    public array $multiValues = [];    // UI boxes for multi_string

    public array $xhrSelectOptions = [];

    public array $searchQueries = [];

    // ------------------------------------This section contains one-off functions for the form------------------------------------------------
    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->resetErrorBag();
        // Reload data
        $this->plugin = $this->plugin->fresh();

        $this->configuration_template = $this->plugin->configuration_template ?? [];
        $this->configuration = is_array($this->plugin->configuration) ? $this->plugin->configuration : [];

        // Initialize multiValues by exploding the CSV strings from the DB
        foreach ($this->configuration_template['custom_fields'] ?? [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fieldKey = $field['keyname'] ?? null;

            if (is_string($fieldKey) && ! array_key_exists($fieldKey, $this->configuration)) {
                if (($field['field_type'] ?? null) === 'boolean') {
                    $this->configuration[$fieldKey] = Plugin::booleanFieldDefault($field);
                } elseif (CustomFieldConditions::isConditionParent($field) && array_key_exists('default', $field)) {
                    $this->configuration[$fieldKey] = $field['default'];
                }
            }

            if (($field['field_type'] ?? null) === 'multi_string' && is_string($fieldKey)) {
                $rawValue = $this->configuration[$fieldKey] ?? ($field['default'] ?? '');

                $currentValue = is_array($rawValue) ? '' : (string) $rawValue;

                $this->multiValues[$fieldKey] = $currentValue !== ''
                    ? array_values(array_filter(explode(',', $currentValue)))
                    : [''];
            }
        }
    }

    /**
     * Triggered by @close on the modal to discard any typed but unsaved changes
     */
    public int $resetIndex = 0;

    /**
     * When recipe settings (or this modal) save, reload so Configuration Fields form stays in sync.
     */
    #[On('config-updated')]
    public function refreshFromParent(): void
    {
        $this->loadData();
        ++$this->resetIndex;
    }

    public function resetForm(): void
    {
        $this->loadData();
        ++$this->resetIndex;
    }

    public function saveConfiguration()
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);

        // final validation layer
        $this->validate([
            'multiValues.*.*' => ['nullable', 'string', 'regex:/^[^,]*$/'],
        ], [
            'multiValues.*.*.regex' => 'Items cannot contain commas.',
        ]);

        // Prepare config copy to send to db
        $finalValues = $this->configuration;
        foreach ($this->configuration_template['custom_fields'] ?? [] as $field) {
            $fieldKey = $field['keyname'];

            // Handle multi_string: Join array back to CSV string
            if ($field['field_type'] === 'multi_string' && isset($this->multiValues[$fieldKey])) {
                $finalValues[$fieldKey] = implode(',', array_filter(array_map('trim', $this->multiValues[$fieldKey])));
            }

            // Handle code fields: Try to JSON decode if necessary (standard TRMNL behavior)
            if ($field['field_type'] === 'code' && isset($finalValues[$fieldKey]) && is_string($finalValues[$fieldKey])) {
                $decoded = json_decode($finalValues[$fieldKey], true);
                if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                    $finalValues[$fieldKey] = $decoded;
                }
            }
        }

        // send to db
        $this->plugin->update(['configuration' => $finalValues]);
        $this->configuration = $finalValues; // update local state
        $this->dispatch('config-updated'); // notifies listeners
        Flux::modal('configuration-modal')->close();
    }

    // ------------------------------------This section contains helper functions for interacting with the form------------------------------------------------
    public function addMultiItem(string $fieldKey): void
    {
        $this->multiValues[$fieldKey][] = '';
    }

    public function removeMultiItem(string $fieldKey, int $index): void
    {
        unset($this->multiValues[$fieldKey][$index]);

        $this->multiValues[$fieldKey] = array_values($this->multiValues[$fieldKey]);

        if (empty($this->multiValues[$fieldKey])) {
            $this->multiValues[$fieldKey][] = '';
        }
    }

    // Livewire magic method to validate MultiValue input boxes
    // Runs on every debounce
    public function updatedMultiValues($value, $key)
    {
        $this->validate([
            'multiValues.*.*' => ['nullable', 'string', 'regex:/^[^,]*$/'],
        ], [
            'multiValues.*.*.regex' => 'Items cannot contain commas.',
        ]);
    }

    public function loadXhrSelectOptions(string $fieldKey, string $endpoint, ?string $query = null): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);

        try {
            $requestData = [];
            if ($query !== null) {
                $requestData = [
                    'function' => $fieldKey,
                    'query' => $query,
                ];
            }

            $response = $query !== null
                ? Http::post($endpoint, $requestData)
                : Http::post($endpoint);

            if ($response->successful()) {
                $this->xhrSelectOptions[$fieldKey] = $response->json();
            } else {
                $this->xhrSelectOptions[$fieldKey] = [];
            }
        } catch (Exception $e) {
            $this->xhrSelectOptions[$fieldKey] = [];
        }
    }

    public function searchXhrSelect(string $fieldKey, string $endpoint): void
    {
        $query = $this->searchQueries[$fieldKey] ?? '';
        if (! empty($query)) {
            $this->loadXhrSelectOptions($fieldKey, $endpoint, $query);
        }
    }
}; ?>

<flux:modal name="configuration-modal" @close="resetForm" class="md:w-lg">
    <div wire:key="config-form-{{ $resetIndex }}" class="space-y-6">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Configuration</flux:heading>
                <flux:subheading>Configure your plugin settings</flux:subheading>
            </div>

            <form wire:submit="saveConfiguration">
                @if (isset($configuration_template['custom_fields']) && is_array($configuration_template['custom_fields']))
                    @php
                        $customFields = $configuration_template['custom_fields'];
                        $ungroupedFields = [];
                        $groupedFields = [];
                        foreach ($customFields as $field) {
                            if (! is_array($field) || ! \App\Support\CustomFieldConditions::isVisible($field, $customFields, $configuration)) {
                                continue;
                            }

                            $group = isset($field['group']) && is_string($field['group']) ? mb_trim($field['group']) : '';
                            if ($group !== '') {
                                $groupedFields[$group][] = $field;
                            } else {
                                $ungroupedFields[] = $field;
                            }
                        }
                    @endphp

                    @foreach ($ungroupedFields as $field)
                        @include('livewire.plugins.partials.config-field', ['field' => $field])
                    @endforeach

                    @foreach ($groupedFields as $groupName => $fields)
                        @php
                            $groupHasRequiredFields = collect($fields)->contains(
                                fn (array $field): bool => \App\Support\CustomFieldConditions::isRequired($field, $customFields, $configuration)
                            );
                        @endphp
                        <div
                            x-data="{ open: false }"
                            class="mb-4 overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50/50 dark:border-zinc-700 dark:bg-zinc-900/30"
                        >
                            <button
                                type="button"
                                @click="open = ! open"
                                class="flex w-full items-center justify-between p-3 text-left transition-colors hover:bg-zinc-100/50 dark:hover:bg-zinc-800/50"
                            >
                                <span class="flex items-center gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ $groupName }}
                                    @if ($groupHasRequiredFields)
                                        <flux:badge size="sm">Required</flux:badge>
                                    @endif
                                </span>
                                <flux:icon
                                    name="chevron-down"
                                    class="size-4 text-zinc-500 transition-transform duration-200"
                                    ::class="{ 'rotate-180': open }"
                                />
                            </button>
                            <div x-show="open" x-cloak class="border-t border-zinc-200 p-3 pt-4 dark:border-zinc-700">
                                @foreach ($fields as $field)
                                    @include('livewire.plugins.partials.config-field', ['field' => $field])
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif

                <div class="w-full flex-col items-end space-y-2">
                    <flux:spacer />
                    <flux:button
                        type="submit"
                        variant="primary"
                        :disabled="$errors->any()"
                        class="disabled:cursor-not-allowed disabled:opacity-50 disabled:grayscale"
                    >
                        Save Configuration
                    </flux:button>
                    @if ($errors->any())
                        <div class="flex items-center gap-2 text-amber-600">
                            <flux:icon name="exclamation-circle" variant="micro" />
                            <span class="text-sm font-medium"> Fix errors before saving. </span>
                        </div>
                    @endif
                </div>
            </form>
        </div>
    </div>
</flux:modal>
