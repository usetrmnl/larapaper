<?php

use Livewire\Component;
use OffloadProject\Toggle\Facades\Toggle;

new class extends Component
{
    /**
     * @return array{toggles: Illuminate\Support\Collection<int, array{name: string, title: string, description: string|null, active: bool}>|null}
     */
    public function with(): array
    {
        return [
            'toggles' => auth()->user()->id === 1
                ? collect(Toggle::all())->map(function (bool $active, string $name): array {
                    $experiment = config("toggle.experiments.{$name}", []);

                    return [
                        'name' => $name,
                        'title' => $experiment['title'] ?? $name,
                        'description' => $experiment['description'] ?? null,
                        'active' => $active,
                    ];
                })->values()
                : null,
        ];
    }

    public function toggle(string $name, bool $active): void
    {
        abort_unless(auth()->user()->id === 1, 403);

        if ($active) {
            Toggle::enable($name);
        } else {
            Toggle::disable($name);
        }
        Flux::toast(text: "The feature '{$name}' has been ".($active ? 'enabled' : 'disabled').'.', variant: 'success');
    }
};
?>

<section class="w-full py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        @include('partials.settings-heading')

        <x-pages::settings.layout
            :heading="__('Lab')"
            :subheading="__('Experimental features before general availability.')"
        >
            <div class="space-y-6">
                <flux:card>
                    <div class="space-y-4">
                        <div class="flex items-center gap-2">
                            <flux:icon.beaker class="size-5" />
                            <flux:heading size="lg">{{ __('Experimental features') }}</flux:heading>
                        </div>

                        <flux:text>
                            {{ __('Activate or deactivate experimental features prior to their general availability. These lab features are considered alpha and may impact system stability.') }}
                        </flux:text>

                        <flux:separator />

                        <div class="space-y-4">
                            @forelse ($toggles as $toggle)
                                <flux:field variant="inline" wire:key="{{ $toggle['name'] }}">
                                    <flux:label class="flex-1">{{ $toggle['title'] }}</flux:label>
                                    @if ($toggle['description'])
                                        <flux:description>{{ $toggle['description'] }}</flux:description>
                                    @endif
                                    <flux:switch
                                        :checked="$toggle['active']"
                                        wire:change="toggle('{{ $toggle['name'] }}', $event.target.checked)"
                                    />
                                </flux:field>
                            @empty
                                <flux:text class="text-zinc-500 dark:text-zinc-400">
                                    {{ __('Currently, there are no experimental features available.') }}
                                </flux:text>
                            @endforelse
                        </div>
                    </div>
                </flux:card>
            </div>
        </x-pages::settings.layout>
    </div>
</section>
