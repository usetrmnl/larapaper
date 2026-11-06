@php
    $fieldKey = $field['keyname'] ?? $field['key'] ?? $field['name'];
    $rawValue = $configuration[$fieldKey] ?? ($field['default'] ?? '');
    $customFields = $configuration_template['custom_fields'] ?? [];
    $labelBadge = \App\Support\CustomFieldConditions::isRequired($field, is_array($customFields) ? $customFields : [], is_array($configuration) ? $configuration : []) ? 'Required' : null;
    $updatesLive = \App\Support\CustomFieldConditions::isConditionParent($field);
    // Blade only merges this bag into component tags when the variable is named $attributes.
    $attributes = new \Illuminate\View\ComponentAttributeBag([
        ($updatesLive ? 'wire:model.live' : 'wire:model') => 'configuration.'.$fieldKey,
    ]);

    $safeDescription = \Stevebauman\Purify\Facades\Purify::clean($field['description'] ?? null);
    $safeHelp = \Stevebauman\Purify\Facades\Purify::clean($field['help_text'] ?? null);

    // For code fields, if the value is an array, JSON encode it
    if ($field['field_type'] === 'code' && is_array($rawValue)) {
        $currentValue = json_encode($rawValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        $currentValue = is_array($rawValue) ? '' : (string) $rawValue;
    }
@endphp

@if ($field['field_type'] !== 'author_bio' && $field['field_type'] !== 'copyable_webhook_url')
    <div class="mb-4">
        @if ($field['field_type'] === 'string' || $field['field_type'] === 'url')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:input {{ $attributes }} value="{{ $currentValue }}" />
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'text')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:textarea {{ $attributes }} value="{{ $currentValue }}" />
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'code')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:textarea
                    rows="{{ $field['rows'] ?? 3 }}"
                    placeholder="{{ $field['placeholder'] ?? null }}"
                    {{ $attributes }}
                    value="{{ $currentValue }}"
                    class="font-mono"
                />
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'password')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:input type="password" {{ $attributes }} value="{{ $currentValue }}" viewable />
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'copyable')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:input value="{{ $field['value'] }}" copyable />
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'time_zone')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:select {{ $attributes }} value="{{ Arr::get($field, 'value') }}">
                    <option value="">Select timezone...</option>
                    @foreach (timezone_identifiers_list() as $timezone)
                        <option value="{{ $timezone }}" {{ $currentValue === $timezone ? 'selected' : '' }}>
                            {{ $timezone }}
                        </option>
                    @endforeach
                </flux:select>
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'number')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:input type="number" {{ $attributes }} value="{{ $currentValue }}" />
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'boolean')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:checkbox {{ $attributes }} :checked="$currentValue" />
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'date')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:input type="date" {{ $attributes }} value="{{ $currentValue }}" />
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'time')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:input type="time" {{ $attributes }} value="{{ $currentValue }}" />
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'select')
            @if (isset($field['multiple']) && $field['multiple'] === true)
                <flux:field>
                    <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                    <flux:description>{!! $safeDescription !!}</flux:description>
                    <flux:checkbox.group {{ $attributes }}>
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
                    <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                    <flux:description>{!! $safeDescription !!}</flux:description>
                    <flux:select {{ $attributes }}>
                        <option value="">Select {{ $field['name'] }}...</option>
                        @if (isset($field['options']) && is_array($field['options']))
                            @foreach ($field['options'] as $option)
                                @if (is_array($option))
                                    @foreach ($option as $label => $value)
                                        <option value="{{ $value }}" {{ $currentValue === $value ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                @else
                                    @php
                                        $key = mb_strtolower(str_replace(' ', '_', $option));
                                    @endphp
                                    <option value="{{ $key }}" {{ $currentValue === $key ? 'selected' : '' }}>
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
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:select
                    {{ $attributes }}
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
                                <option value="{{ $option }}" {{ $currentValue === (string)$option ? 'selected' : '' }}>
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
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:input.group>
                    <flux:input wire:model="searchQueries.{{ $fieldKey }}" placeholder="Enter search query..." />
                    <flux:button
                        wire:click="searchXhrSelect('{{ $fieldKey }}', '{{ $field['endpoint'] }}')"
                        icon="magnifying-glass"
                    />
                </flux:input.group>
                <flux:description>{!! $safeHelp !!}</flux:description>
                @if ((isset($xhrSelectOptions[$fieldKey]) && is_array($xhrSelectOptions[$fieldKey]) && count($xhrSelectOptions[$fieldKey]) > 0) || ! empty($currentValue))
                    <flux:select {{ $attributes }}>
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
                            <option value="{{ $currentValue }}" selected>{{ $currentValue }}</option>
                        @endif
                    </flux:select>
                @endif
            </div>

        @elseif ($field['field_type'] === 'multi_string')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>

                <div class="mt-2 space-y-2">
                    @foreach ($multiValues[$fieldKey] as $index => $item)
                        <div class="flex items-center gap-2" wire:key="multi-{{ $fieldKey }}-{{ $index }}">
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

                    <flux:button variant="ghost" size="sm" icon="plus" wire:click="addMultiItem('{{ $fieldKey }}')">
                        Add Item
                    </flux:button>
                </div>
                <flux:description>{!! $safeHelp !!}</flux:description>
            </flux:field>

        @elseif ($field['field_type'] === 'lat_lon')
            <flux:field>
                <flux:label :badge="$labelBadge">{{ $field['name'] }}</flux:label>
                <flux:description>{!! $safeDescription !!}</flux:description>
                <flux:input
                    {{ $attributes }}
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
@endif
