<?php

namespace App\Livewire\Actions;

use Livewire\Attributes\On;
use Livewire\Component;

class DeviceAutoJoin extends Component
{
    public bool $deviceAutojoin = false;

    public bool $isFirstUser = false;

    /**
     * Unix timestamp at which the Auto-Join window closes, or null when closed.
     */
    public ?int $autoJoinExpiresAt = null;

    /**
     * Server unix timestamp at the last sync, used by the countdown to correct
     * for client clock skew.
     */
    public int $serverNow = 0;

    public function mount(): void
    {
        $this->isFirstUser = auth()->user()->id === 1;
        $this->syncState();
    }

    public function updating(string $name, mixed $value): void
    {
        if ($name !== 'deviceAutojoin') {
            return;
        }

        $this->validate([
            'deviceAutojoin' => 'boolean',
        ]);

        $user = auth()->user();

        if ((bool) $value) {
            $user->enableAutoJoin();
        } else {
            $user->disableAutoJoin();
        }

        $this->syncState();

        $this->dispatch('device-auto-join-changed');
    }

    /**
     * Reload the Auto-Join state from the user. Also called by the countdown
     * once it reaches zero.
     */
    #[On('device-auto-join-changed')]
    public function syncState(): void
    {
        $user = auth()->user()->refresh();

        $this->deviceAutojoin = $user->hasActiveAutoJoin();
        $this->autoJoinExpiresAt = $this->deviceAutojoin ? $user->assign_new_devices_until->timestamp : null;
        $this->serverNow = now()->timestamp;
    }

    public function render(): \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory
    {
        return view('livewire.actions.device-auto-join');
    }
}
