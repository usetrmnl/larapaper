<?php

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

        $this->dispatch('profile-updated');
    }
}; ?>

<section class="w-full py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        @include('partials.settings-heading')

        <x-pages::settings.layout heading="Preferences" subheading="Update your preferences">
            <form wire:submit="updatePreferences" class="my-6 w-full space-y-6">

                <flux:select wire:model="timezone" label="Timezone">
                    <flux:select.option value="" disabled>Select timezone...</flux:select.option>
                    @foreach(timezone_identifiers_list() as $tz)
                        <flux:select.option value="{{ $tz }}">{{ $tz }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="assign_new_device_id" label="Auto-Joined Devices should mirror">
                    <flux:select.option value="">None</flux:select.option>
                    @foreach(auth()->user()->devices->where('mirror_device_id', null) as $device)
                        <flux:select.option value="{{ $device->id }}">
                            {{ $device->name }} ({{ $device->friendly_id }})
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex items-center gap-4">
                    <div class="flex items-center justify-end">
                        <flux:button variant="primary" type="submit" class="w-full">{{ __('Save') }}</flux:button>
                    </div>

                    <x-action-message class="me-3" on="profile-updated">
                        {{ __('Saved.') }}
                    </x-action-message>
                </div>
            </form>

        </x-pages::settings.layout>
    </div>
</section>
