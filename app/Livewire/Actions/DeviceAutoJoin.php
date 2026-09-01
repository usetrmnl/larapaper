<?php

namespace App\Livewire\Actions;

use Livewire\Attributes\On;
use Livewire\Component;

class DeviceAutoJoin extends Component
{
    public bool $deviceAutojoin = false;

    public bool $isFirstUser = false;

    public function mount(): void
    {
        $this->deviceAutojoin = (bool) (auth()->user()->assign_new_devices ?? false);
        $this->isFirstUser = auth()->user()->id === 1;
    }

    public function updating(string $name, mixed $value): void
    {
        if ($name !== 'deviceAutojoin') {
            return;
        }

        $this->validate([
            'deviceAutojoin' => 'boolean',
        ]);

        $enabled = (bool) $value;
        $user = auth()->user();

        if ((bool) ($user->assign_new_devices ?? false) === $enabled) {
            return;
        }

        $user->update([
            'assign_new_devices' => $enabled,
        ]);

        $this->dispatch('device-auto-join-changed', enabled: $enabled);
    }

    #[On('device-auto-join-changed')]
    public function syncFromSibling(bool $enabled): void
    {
        $this->deviceAutojoin = $enabled;
    }

    public function render(): \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory
    {
        return view('livewire.actions.device-auto-join');
    }
}
