@props([
    'device',
    'firmwares',
    'selectedFirmwareModel',
    'simulatePhysicalDevice',
    'timezone',
    'selectablePlugins',
])

@php
    $firmwares ??= new \Illuminate\Database\Eloquent\Collection;
@endphp

<flux:modal name="pause-device-{{ $device->id }}">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Pause</flux:heading>
            <div class="mt-2 text-sm text-zinc-500">
                Select how long to pause screen generation for
                <span class="font-semibold">{{ $device->name }}</span>.
            </div>
        </div>
        <form wire:submit="pauseDevice({{ $device->id }})">
            <div class="mb-4">
                <flux:radio.group wire:model.live="pause_duration" label="Pause Duration" variant="segmented">
                    <flux:radio value="30" label="30 min" />
                    <flux:radio value="60" label="60 min" />
                    <flux:radio value="120" label="120 min" />
                    <flux:radio value="240" label="240 min" />
                    <flux:radio value="480" label="480 min" />
                </flux:radio.group>
            </div>

            <flux:separator text="or" class="my-4" />

            <div class="mb-4 grid grid-cols-2 gap-4">
                <flux:input
                    type="date"
                    label="Date"
                    wire:model.live="pause_until_date"
                    min="{{ now($timezone)->toDateString() }}"
                    max="{{ now($timezone)->addDays(\App\Models\Device::MAX_PAUSE_DAYS)->toDateString() }}"
                />
                <flux:input type="time" label="Time" wire:model.live="pause_until_time" />
            </div>
            <flux:text class="text-zinc-500">Timezone: {{ $timezone }}</flux:text>
            <flux:text class="mt-2 mb-4">The device will still ping the server every 24 hours.</flux:text>
            <flux:error name="pause_until_date" />

            <flux:separator class="my-4" />

            <div class="mb-4">
                <flux:select
                    label="Pause Screen"
                    wire:model="pause_plugin_id"
                    description="Optionally render a plugin instead of the default sleep screen."
                >
                    <flux:select.option value="">Default sleep screen</flux:select.option>
                    @foreach ($selectablePlugins as $plugin)
                        <flux:select.option value="{{ $plugin->id }}">{{ $plugin->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="pause_plugin_id" />
            </div>

            <div class="flex">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </div>
</flux:modal>

<flux:modal name="unpause-device-{{ $device->id }}">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Pause Active</flux:heading>
        </div>

        <flux:callout variant="info" icon="pause-circle">
            <flux:callout.heading>
                Paused until {{ $device->pause_until?->timezone($timezone) }} {{ $timezone }}
            </flux:callout.heading>
            <flux:callout.text>
                @if ($device->usesTouchBar())
                    To exit pause early, click "End pause" and press the touch bar in the middle of your device.
                @else
                    To exit pause early, click "End pause" and press the physical screen button on your device.
                @endif
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="unpauseDevice({{ $device->id }})" variant="primary"> End pause </flux:button>
            </x-slot>
        </flux:callout>

        <div class="flex">
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="ghost">Close</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>

<flux:modal name="update-firmware-{{ $device->id }}" class="md:w-96">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Update Firmware</flux:heading>
            <flux:subheading>Select a firmware version to update to</flux:subheading>
        </div>

        <form wire:submit="updateFirmware({{ $device->id }})">
            <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
                <flux:callout.heading>TRMNL devices only</flux:callout.heading>
                <flux:callout.text>
                    OTA firmware updates are currently available only for TRMNL devices. Applying firmware to other
                    device models may break your device.</flux:callout.text>
            </flux:callout>

            <flux:button
                variant="subtle"
                icon="arrow-path"
                wire:click="checkFirmwareUpdates({{ $device->id }})"
                class="mb-4 w-full"
            >
                Check for new firmware versions
            </flux:button>

            <div class="mb-4">
                <flux:select label="Device Model" wire:model.live="selected_firmware_model" required>
                    @foreach (\App\Enums\FirmwareModel::cases() as $firmwareModel)
                        <flux:select.option value="{{ $firmwareModel->value }}">
                            {{ $firmwareModel->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="mb-4">
                <flux:select label="Firmware Version" wire:model="selected_firmware_id" required>
                    @foreach ($firmwares->where('model', $selectedFirmwareModel) as $firmware)
                        <flux:select.option value="{{ $firmware->id }}">
                            {{ $firmware->version_tag }} {{ $firmware->latest ? '(Latest)' : '' }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="mb-4">
                <flux:checkbox wire:model="download_firmware" label="Cache Firmware on BYOS"> </flux:checkbox>
                <flux:text class="mt-2 text-xs">Check if the Device has no internet connection.</flux:text>
            </div>

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary">Update Firmware</flux:button>
            </div>
        </form>
    </div>
</flux:modal>

<flux:modal name="mirror-url-{{ $device->id }}" class="md:w-96">
    @php
        $mirrorUrl = url('/mirror/index.html').'?'.http_build_query([
            'api_key' => $device->api_key,
            'mode' => $simulatePhysicalDevice ? 'display' : 'mirror',
        ]);
    @endphp

    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Mirror WebUI</flux:heading>
            <flux:subheading
                >Mirror this device onto older devices with a web browser — Safari is supported back to iOS
                9.</flux:subheading>
        </div>

        <flux:switch
            wire:model.live="simulate_physical_device"
            label="Simulate physical device"
            description="WebUI acts as the device itself, rendering screens and advancing its playlist. Only use this when no physical device uses this API key."
        />

        <flux:input label="Mirror URL" value="{{ $mirrorUrl }}" readonly copyable />
    </div>
</flux:modal>
