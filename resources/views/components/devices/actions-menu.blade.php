@props(['device', 'showPause' => false])

<flux:dropdown>
    <flux:button icon="ellipsis-horizontal" variant="subtle" aria-label="Device actions" />

    <flux:menu>
        @if ($showPause)
            @if ($device->isPauseActive())
                <flux:modal.trigger name="unpause-device-{{ $device->id }}">
                    <flux:menu.item icon="pause-circle">Pause Screen Generation</flux:menu.item>
                </flux:modal.trigger>
            @else
                <flux:modal.trigger name="pause-device-{{ $device->id }}">
                    <flux:menu.item icon="pause-circle">Pause Screen Generation</flux:menu.item>
                </flux:modal.trigger>
            @endif
        @endif

        <flux:modal.trigger name="update-firmware-{{ $device->id }}">
            <flux:menu.item icon="arrow-up-circle" wire:click="prepareFirmwareUpdate({{ $device->id }})">
                Update Firmware
            </flux:menu.item>
        </flux:modal.trigger>

        <flux:menu.item icon="bars-3" href="{{ route('devices.logs', $device) }}" wire:navigate>
            Show Logs
        </flux:menu.item>

        <flux:modal.trigger name="mirror-url-{{ $device->id }}">
            <flux:menu.item icon="link">Mirror URL</flux:menu.item>
        </flux:modal.trigger>

        {{ $slot }}
    </flux:menu>
</flux:dropdown>
