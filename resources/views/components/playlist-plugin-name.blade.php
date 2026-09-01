@props(['item'])

@if ($item->isMashup())
    <div {{ $attributes }}>
        <div class="font-medium">{{ $item->getMashupName() }}</div>
        <div class="text-xs text-zinc-500 dark:text-zinc-400">
            <flux:icon name="mashup-{{ $item->getMashupLayoutType() }}" class="inline-block pb-1" variant="mini" />
            {{ $item->mashupPluginNames() }}
        </div>
    </div>
@elseif ($item->plugin?->managementUrl())
    <a
        href="{{ $item->plugin->managementUrl() }}"
        wire:navigate
        {{ $attributes->class('font-medium hover:underline') }}
    >{{ $item->plugin->name }}</a>
@else
    <div {{ $attributes->class('font-medium') }}>{{ $item->plugin?->name ?? 'Missing plugin' }}</div>
@endif
