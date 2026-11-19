<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public ?int $assign_new_device_id = null;

    public ?string $timezone = null;

    public function mount(): void
    {
        $this->assign_new_device_id = Auth::user()->assign_new_device_id;
        $this->timezone = Auth::user()->timezone ?? config('app.timezone');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['timezone', 'assign_new_device_id'], true)) {
            $this->updatePreferences();
        }
    }

    public function updatePreferences(): void
    {
        $validated = $this->validate([
            'assign_new_device_id' => [
                'nullable',
                Rule::exists('devices', 'id')->where(function ($query) {
                    $query->where('user_id', Auth::id())
                        ->whereNull('mirror_device_id');
                }),
            ],
            'timezone' => [
                'nullable',
                'string',
                Rule::in(timezone_identifiers_list()),
            ],
        ]);

        Auth::user()->update($validated);

        Flux::toast(variant: 'success', text: __('Saved'));
    }
}; ?>

<section class="w-full py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        @include('partials.settings-heading')

        <x-pages::settings.layout heading="Preferences" subheading="Update your preferences">
            <div class="my-6 w-full space-y-2">
                <flux:item.group>
                    <flux:item>
                        <flux:item.heading>{{ __('Timezone') }}</flux:item.heading>
                        <flux:item.subheading>
                            {{ __('Used for schedules and times shown in the app.') }}</flux:item.subheading>

                        <flux:item.actions>
                            <flux:select
                                wire:model.live="timezone"
                                size="sm"
                                aria-label="{{ __('Timezone') }}"
                                class="max-w-56"
                            >
                                <flux:select.option value="" disabled>Select timezone...</flux:select.option>
                                @foreach (timezone_identifiers_list() as $tz)
                                    <flux:select.option value="{{ $tz }}">{{ $tz }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </flux:item.actions>
                    </flux:item>

                    <flux:item>
                        <flux:item.heading>{{ __('Auto-joined devices should mirror') }}</flux:item.heading>
                        <flux:item.subheading>
                            {{ __('New devices that auto-join will mirror this device.') }}</flux:item.subheading>

                        <flux:item.actions>
                            <flux:select
                                wire:model.live="assign_new_device_id"
                                size="sm"
                                aria-label="{{ __('Auto-joined devices should mirror') }}"
                                class="max-w-56"
                            >
                                <flux:select.option value="">None</flux:select.option>
                                @foreach (auth()->user()->devices->where('mirror_device_id', null) as $device)
                                    <flux:select.option value="{{ $device->id }}">
                                        {{ $device->name }} ({{ $device->friendly_id }})
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        </flux:item.actions>
                    </flux:item>
                </flux:item.group>

                <flux:error name="timezone" />
                <flux:error name="assign_new_device_id" />
            </div>
        </x-pages::settings.layout>
    </div>
</section>
