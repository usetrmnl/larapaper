<?php

use App\Models\Plugin;
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
            if (($field['field_type'] ?? null) === 'multi_string') {
                $fieldKey = $field['keyname'];
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

<flux:modal name="configuration-modal" @close="resetForm" class="md:w-96">
    <div wire:key="config-form-{{ $resetIndex }}" class="space-y-6">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Configuration</flux:heading>
                <flux:subheading>Configure your plugin settings</flux:subheading>
            </div>

            <form wire:submit="saveConfiguration">
                @if (isset($configuration_template['custom_fields']) && is_array($configuration_template['custom_fields']))
                    @foreach ($configuration_template['custom_fields'] as $field)
                        @php
                            $fieldKey = $field['keyname'] ?? $field['key'] ?? $field['name'];
                            $rawValue = $configuration[$fieldKey] ?? ($field['default'] ?? '');

                            // These are sanitized at Model/Plugin level, safe to render HTML
                            $safeDescription = $field['description'] ?? '';
                            $safeHelp = $field['help_text'] ?? '';

                            // For code fields, if the value is an array, JSON encode it
                            if ($field['field_type'] === 'code' && is_array($rawValue)) {
                                $currentValue = json_encode($rawValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            } else {
                                $currentValue = is_array($rawValue) ? '' : (string) $rawValue;
                            }
                        @endphp
                        <div class="mb-4">
                            @if ($field['field_type'] === 'author_bio')
                                @continue
                            @endif

                            @if ($field['field_type'] === 'copyable_webhook_url')
                                @continue
                            @endif

                            @if ($field['field_type'] === 'string' || $field['field_type'] === 'url')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:input
                                        wire:model="configuration.{{ $fieldKey }}"
                                        value="{{ $currentValue }}"
                                    />
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'text')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:textarea
                                        wire:model="configuration.{{ $fieldKey }}"
                                        value="{{ $currentValue }}"
                                    />
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'code')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:textarea
                                        rows="{{ $field['rows'] ?? 3 }}"
                                        placeholder="{{ $field['placeholder'] ?? null }}"
                                        wire:model="configuration.{{ $fieldKey }}"
                                        value="{{ $currentValue }}"
                                        class="font-mono"
                                    />
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'password')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:input
                                        type="password"
                                        wire:model="configuration.{{ $fieldKey }}"
                                        value="{{ $currentValue }}"
                                        viewable
                                    />
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'copyable')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:input value="{{ $field['value'] }}" copyable />
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'time_zone')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:select
                                        wire:model="configuration.{{ $fieldKey }}"
                                        value="{{ Arr::get($field, 'value') }}"
                                    >
                                        <option value="">Select timezone...</option>
                                        @foreach (timezone_identifiers_list() as $timezone)
                                            <option
                                                value="{{ $timezone }}"
                                                {{ $currentValue === $timezone ? 'selected' : '' }}
                                            >
                                                {{ $timezone }}
                                            </option>
                                        @endforeach
                                    </flux:select>
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'number')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:input
                                        type="number"
                                        wire:model="configuration.{{ $fieldKey }}"
                                        value="{{ $currentValue }}"
                                    />
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'boolean')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:checkbox
                                        wire:model="configuration.{{ $fieldKey }}"
                                        :checked="$currentValue"
                                    />
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'date')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:input
                                        type="date"
                                        wire:model="configuration.{{ $fieldKey }}"
                                        value="{{ $currentValue }}"
                                    />
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'time')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:input
                                        type="time"
                                        wire:model="configuration.{{ $fieldKey }}"
                                        value="{{ $currentValue }}"
                                    />
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'select')
                                @if (isset($field['multiple']) && $field['multiple'] === true)
                                    <flux:field>
                                        <flux:label>{{ $field['name'] }}</flux:label>
                                        <flux:description>{!! $safeDescription !!}</flux:description>
                                        <flux:checkbox.group wire:model="configuration.{{ $fieldKey }}">
                                            @if (isset($field['options']) && is_array($field['options']))
                                                @foreach ($field['options'] as $option)
                                                    @if (is_array($option))
                                                        @foreach ($option as $label => $value)
                                                            <flux:checkbox label="{{ $label }}" value="{{ $value }}" />
                                                        @endforeach
                                                    @else
                                                        @php
                                                            $key = mb_strtolower(str_replace(' ', '_', $option));
                                                        @endphp
                                                        <flux:checkbox label="{{ $option }}" value="{{ $key }}" />
                                                    @endif
                                                @endforeach
                                            @endif
                                        </flux:checkbox.group>
                                        <flux:description>{!! $safeHelp !!}</flux:description>
                                    </flux:field>
                                @else
                                    <flux:field>
                                        <flux:label>{{ $field['name'] }}</flux:label>
                                        <flux:description>{!! $safeDescription !!}</flux:description>
                                        <flux:select wire:model="configuration.{{ $fieldKey }}">
                                            <option value="">Select {{ $field['name'] }}...</option>
                                            @if (isset($field['options']) && is_array($field['options']))
                                                @foreach ($field['options'] as $option)
                                                    @if (is_array($option))
                                                        @foreach ($option as $label => $value)
                                                            <option
                                                                value="{{ $value }}"
                                                                {{ $currentValue === $value ? 'selected' : '' }}
                                                            >
                                                                {{ $label }}
                                                            </option>
                                                        @endforeach
                                                    @else
                                                        @php
                                                            $key = mb_strtolower(str_replace(' ', '_', $option));
                                                        @endphp
                                                        <option
                                                            value="{{ $key }}"
                                                            {{ $currentValue === $key ? 'selected' : '' }}
                                                        >
                                                            {{ $option }}
                                                        </option>
                                                    @endif
                                                @endforeach
                                            @endif
                                        </flux:select>
                                        <flux:description>{!! $safeHelp !!}</flux:description>
                                    </flux:field>
                                @endif

                            @elseif ($field['field_type'] === 'xhrSelect')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:select
                                        wire:model="configuration.{{ $fieldKey }}"
                                        wire:init="loadXhrSelectOptions('{{ $fieldKey }}', '{{ $field['endpoint'] }}')"
                                    >
                                        <option value="">Select {{ $field['name'] }}...</option>
                                        @if (isset($xhrSelectOptions[$fieldKey]) && is_array($xhrSelectOptions[$fieldKey]))
                                            @foreach ($xhrSelectOptions[$fieldKey] as $option)
                                                @if (is_array($option))
                                                    @if (isset($option['id']) && isset($option['name']))
                                                        {{-- xhrSelectSearch format: { 'id' => 'db-456', 'name' => 'Team Goals' } --}}
                                                        <option
                                                            value="{{ $option['id'] }}"
                                                            {{ $currentValue === (string)$option['id'] ? 'selected' : '' }}
                                                        >
                                                            {{ $option['name'] }}
                                                        </option>
                                                    @else
                                                        {{-- xhrSelect format: { 'Braves' => 123 } --}}
                                                        @foreach ($option as $label => $value)
                                                            <option
                                                                value="{{ $value }}"
                                                                {{ $currentValue === (string)$value ? 'selected' : '' }}
                                                            >
                                                                {{ $label }}
                                                            </option>
                                                        @endforeach
                                                    @endif
                                                @else
                                                    <option
                                                        value="{{ $option }}"
                                                        {{ $currentValue === (string)$option ? 'selected' : '' }}
                                                    >
                                                        {{ $option }}
                                                    </option>
                                                @endif
                                            @endforeach
                                        @endif
                                    </flux:select>
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>

                            @elseif ($field['field_type'] === 'xhrSelectSearch')
                                <div class="space-y-2">
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:input.group>
                                        <flux:input
                                            wire:model="searchQueries.{{ $fieldKey }}"
                                            placeholder="Enter search query..."
                                        />
                                        <flux:button
                                            wire:click="searchXhrSelect('{{ $fieldKey }}', '{{ $field['endpoint'] }}')"
                                            icon="magnifying-glass"
                                        />
                                    </flux:input.group>
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                    @if ((isset($xhrSelectOptions[$fieldKey]) && is_array($xhrSelectOptions[$fieldKey]) && count($xhrSelectOptions[$fieldKey]) > 0) || ! empty($currentValue))
                                        <flux:select wire:model="configuration.{{ $fieldKey }}">
                                            <option value="">Select {{ $field['name'] }}...</option>
                                            @if (isset($xhrSelectOptions[$fieldKey]) && is_array($xhrSelectOptions[$fieldKey]))
                                                @foreach ($xhrSelectOptions[$fieldKey] as $option)
                                                    @if (is_array($option))
                                                        @if (isset($option['id']) && isset($option['name']))
                                                            {{-- xhrSelectSearch format: { 'id' => 'db-456', 'name' => 'Team Goals' } --}}
                                                            <option
                                                                value="{{ $option['id'] }}"
                                                                {{ $currentValue === (string)$option['id'] ? 'selected' : '' }}
                                                            >
                                                                {{ $option['name'] }}
                                                            </option>
                                                        @else
                                                            {{-- xhrSelect format: { 'Braves' => 123 } --}}
                                                            @foreach ($option as $label => $value)
                                                                <option
                                                                    value="{{ $value }}"
                                                                    {{ $currentValue === (string)$value ? 'selected' : '' }}
                                                                >
                                                                    {{ $label }}
                                                                </option>
                                                            @endforeach
                                                        @endif
                                                    @else
                                                        <option
                                                            value="{{ $option }}"
                                                            {{ $currentValue === (string)$option ? 'selected' : '' }}
                                                        >
                                                            {{ $option }}
                                                        </option>
                                                    @endif
                                                @endforeach
                                            @endif
                                            @if (! empty($currentValue) && (! isset($xhrSelectOptions[$fieldKey]) || empty($xhrSelectOptions[$fieldKey])))
                                                {{-- Show current value even if no options are loaded --}}
                                                <option value="{{ $currentValue }}" selected>
                                                    {{ $currentValue }}
                                                </option>
                                            @endif
                                        </flux:select>
                                    @endif
                                </div>
                            @elseif ($field['field_type'] === 'multi_string')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>

                                    <div class="mt-2 space-y-2">
                                        @foreach ($multiValues[$fieldKey] as $index => $item)
                                            <div
                                                class="flex items-center gap-2"
                                                wire:key="multi-{{ $fieldKey }}-{{ $index }}"
                                            >
                                                <flux:input
                                                    wire:model.live.debounce="multiValues.{{ $fieldKey }}.{{ $index }}"
                                                    :placeholder="$field['placeholder'] ?? 'Value...'"
                                                    :invalid="$errors->has('multiValues.'.$fieldKey.'.'.$index)"
                                                    class="flex-1"
                                                />

                                                @if (count($multiValues[$fieldKey]) > 1)
                                                    <flux:button
                                                        variant="ghost"
                                                        icon="trash"
                                                        size="sm"
                                                        wire:click="removeMultiItem('{{ $fieldKey }}', {{ $index }})"
                                                    />
                                                @endif
                                            </div>
                                            @error("multiValues.{$fieldKey}.{$index}")
                                                <div class="mt-1 flex items-center gap-2 text-amber-600">
                                                    <flux:icon name="exclamation-triangle" variant="micro" />
                                                    {{-- $message comes from thrown error --}}
                                                    <span class="text-xs font-medium">{{ $message }}</span>
                                                </div>
                                            @enderror
                                        @endforeach

                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="plus"
                                            wire:click="addMultiItem('{{ $fieldKey }}')"
                                        >
                                            Add Item
                                        </flux:button>
                                    </div>
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>
                            @elseif ($field['field_type'] === 'lat_lon')
                                <flux:field>
                                    <flux:label>{{ $field['name'] }}</flux:label>
                                    <flux:description>{!! $safeDescription !!}</flux:description>
                                    <flux:input
                                        wire:model="configuration.{{ $fieldKey }}"
                                        value="{{ $currentValue }}"
                                        placeholder="{{ $field['placeholder'] ?? '40.7128,-74.0060' }}"
                                        pattern="-?\d+(\.\d+)?,-?\d+(\.\d+)?"
                                        title="Latitude,longitude (e.g. 40.7128,-74.0060)"
                                    />
                                    <flux:description>{!! $safeHelp !!}</flux:description>
                                </flux:field>
                            @else
                                <flux:callout variant="warning">Field type "{{ $field['field_type'] }}" not yet supported</flux:callout>
                            @endif
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
