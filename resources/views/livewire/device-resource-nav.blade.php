<?php

use Livewire\Component;

new class extends Component
{
    public string $section = 'devices';

    public function mount(): void
    {
        $this->section = match (request()->route()?->getName()) {
            'devices' => 'devices',
            'device-models.index' => 'device-models',
            'device-palettes.index' => 'device-palettes',
            default => 'devices',
        };
    }

    public function updatedSection(string $value): void
    {
        $url = match ($value) {
            'devices' => route('devices'),
            'device-models' => route('device-models.index'),
            'device-palettes' => route('device-palettes.index'),
            default => route('devices'),
        };

        $this->redirect($url, navigate: true);
    }
}

?>

<div>
    <flux:radio.group wire:model.live="section" variant="segmented" size="lg" class="max-w-full">
        <flux:radio value="devices" label="{{ __('Devices') }}" />
        <flux:radio value="device-models" label="{{ __('Device Models') }}" />
        <flux:radio value="device-palettes" label="{{ __('Device Palettes') }}" />
    </flux:radio.group>
</div>
