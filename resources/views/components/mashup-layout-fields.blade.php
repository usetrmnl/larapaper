@props([
    'layout',
    'plugins',
    'layoutModel' => 'mashup_layout',
    'pluginsModel' => 'mashup_plugins',
    'lockedPlugin' => null,
])

@php
    $required = \App\Models\PlaylistItem::getRequiredPluginCountForLayout($layout);
    $selectableCount = $lockedPlugin ? max(0, $required - 1) : $required;
@endphp

<div {{ $attributes }}>
    <flux:separator text="Layout" />
    <div class="mt-4 mb-4">
        <flux:radio.group wire:model.live="{{ $layoutModel }}" variant="segmented">
            <flux:radio value="full" icon="mashup-1x1" />
            <flux:radio value="1Lx1R" icon="mashup-1Lx1R" />
            <flux:radio value="1Lx2R" icon="mashup-1Lx2R" />
            <flux:radio value="2Lx1R" icon="mashup-2Lx1R" />
            <flux:radio value="1Tx1B" icon="mashup-1Tx1B" />
            <flux:radio value="2Tx1B" icon="mashup-2Tx1B" />
            <flux:radio value="1Tx2B" icon="mashup-1Tx2B" />
            <flux:radio value="2x2" icon="mashup-2x2" />
        </flux:radio.group>
    </div>

    @if ($selectableCount > 0)
        <div class="mb-4">
            <div class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                @if ($lockedPlugin || $layout !== 'full')
                    Mashup Slots
                @else
                    Plugin
                @endif
            </div>
            <div class="space-y-2">
                @if ($lockedPlugin)
                    <div class="flex items-center gap-2">
                        <div class="w-24 text-sm text-zinc-500 dark:text-zinc-400">Main Plugin</div>
                        <flux:input :value="$lockedPlugin->name" disabled class="flex-1" />
                    </div>
                @endif

                @for ($i = 0; $i < $selectableCount; ++$i)
                    <div class="flex items-center gap-2" wire:key="{{ $pluginsModel }}-slot-{{ $i }}">
                        <div class="w-24 text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($selectableCount === 1 && ! $lockedPlugin)
                                Plugin
                            @else
                                Plugin {{ $lockedPlugin ? $i + 2 : $i + 1 }}:
                            @endif
                        </div>
                        <flux:select wire:model="{{ $pluginsModel }}.{{ $i }}" class="flex-1">
                            <option value="">Select a plugin...</option>
                            @foreach ($plugins as $availablePlugin)
                                <option value="{{ $availablePlugin->id }}">{{ $availablePlugin->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                @endfor
            </div>
            <flux:error name="{{ $pluginsModel }}" />
        </div>
    @endif
</div>
