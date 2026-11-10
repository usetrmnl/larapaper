<?php

use App\Concerns\ManagesDeviceActions;
use App\Concerns\ProvidesSelectablePlugins;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Services\ProxyCloudService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use ManagesDeviceActions;
    use ProvidesSelectablePlugins;

    public $devices;

    public $showDeviceForm = false;

    public $name;

    public $mac_address;

    public $api_key;

    public $default_refresh_interval = 900;

    public $friendly_id;

    public $is_mirror = false;

    public $mirror_device_id = null;

    public $device_model_id = null;

    public $deviceModels;

    public ?int $proxyKeyDeviceId = null;

    public string $proxy_cloud_api_key = '';

    protected $rules = [
        'mac_address' => 'required',
        'api_key' => 'required',
        'default_refresh_interval' => 'required|integer',
        'device_model_id' => 'nullable|exists:device_models,id',
        'mirror_device_id' => 'required_if:is_mirror,true',
    ];

    public function mount()
    {
        $this->devices = auth()->user()->devices()->with('deviceModel')->get();
        $this->deviceModels = DeviceModel::orderBy('label')->get()->sortBy(function ($deviceModel) {
            // Put TRMNL models at the top, then sort alphabetically within each group
            $isTrmnl = str_starts_with($deviceModel->label, 'TRMNL');

            return $isTrmnl ? '0'.$deviceModel->label : '1'.$deviceModel->label;
        });

        return view('livewire.devices.manage');
    }

    /**
     * Devices that have not requested /api/setup or /api/display yet.
     */
    #[Computed]
    public function devicesAwaitingActivation(): Collection
    {
        return $this->devices->whereNull('last_refreshed_at')->values();
    }

    public function updated(string $property): void
    {
        if ($property === 'device_model_id' && empty($this->device_model_id)) {
            $this->device_model_id = null;
        }
    }

    public function generateApiKey(): void
    {
        $this->api_key = Device::generateApiKey();
        $this->resetValidation('api_key');
    }

    public function generateFriendlyId(): void
    {
        $this->friendly_id = Device::generateFriendlyId();
        $this->resetValidation('friendly_id');
    }

    public function createDevice(): void
    {
        $this->validate();

        if ($this->is_mirror) {
            // Verify the mirror device belongs to the user and is not a mirror device itself
            $mirrorDevice = auth()->user()->devices()->find($this->mirror_device_id);
            abort_unless($mirrorDevice, 403, 'Invalid mirror device selected');
            abort_if($mirrorDevice->mirror_device_id !== null, 403, 'Cannot mirror a device that is already a mirror device');
        }

        // Convert empty string to null for custom selection
        $deviceModelId = empty($this->device_model_id) ? null : $this->device_model_id;

        Device::create([
            'name' => $this->name,
            'mac_address' => $this->mac_address,
            'api_key' => $this->api_key,
            'default_refresh_interval' => $this->default_refresh_interval,
            'friendly_id' => $this->friendly_id,
            'user_id' => auth()->id(),
            'device_model_id' => $deviceModelId,
            'mirror_device_id' => $this->is_mirror ? $this->mirror_device_id : null,
        ]);

        $this->reset();
        Flux::modal('create-device')->close();

        $this->devices = auth()->user()->devices()->with('deviceModel')->get();
        Flux::toast(variant: 'success', text: 'Device created successfully.');
    }

    public function toggleProxyCloud(Device $device, ProxyCloudService $proxyCloud): void
    {
        abort_unless(auth()->user()->devices->contains($device), 403);

        if ($device->proxy_cloud) {
            $device->update([
                'proxy_cloud' => false,
            ]);
            $this->devices = auth()->user()->devices()->with('deviceModel')->get();

            return;
        }

        try {
            $response = $proxyCloud->fetchDisplay($device);
        } catch (ConnectionException) {
            Flux::toast(variant: 'danger', text: 'Could not reach TRMNL cloud.');

            return;
        }

        if ($proxyCloud->deviceNotFound($response)) {
            $this->proxyKeyDeviceId = $device->id;
            $this->proxy_cloud_api_key = '';
            $this->resetValidation('proxy_cloud_api_key');
            Flux::modal('proxy-cloud-api-key')->show();

            return;
        }

        if (! $proxyCloud->accepted($response)) {
            Flux::toast(variant: 'danger', text: $proxyCloud->errorMessage($response) ?? 'TRMNL cloud rejected the proxy request.');

            return;
        }

        $device->update([
            'proxy_cloud' => true,
        ]);
        $this->devices = auth()->user()->devices()->with('deviceModel')->get();
    }

    public function saveProxyCloudApiKey(ProxyCloudService $proxyCloud): void
    {
        $device = auth()->user()->devices()->find($this->proxyKeyDeviceId);
        abort_unless($device instanceof Device, 403);

        $this->validate([
            'proxy_cloud_api_key' => ['required', 'string', 'max:255'],
        ]);

        try {
            $response = $proxyCloud->fetchDisplay($device, $this->proxy_cloud_api_key);
        } catch (ConnectionException) {
            $this->addError('proxy_cloud_api_key', 'Could not reach TRMNL cloud.');

            return;
        }

        if ($proxyCloud->deviceNotFound($response)) {
            $this->addError('proxy_cloud_api_key', 'TRMNL cloud could not find a device for this API key.');

            return;
        }

        if (! $proxyCloud->accepted($response)) {
            $this->addError('proxy_cloud_api_key', $proxyCloud->errorMessage($response) ?? 'TRMNL cloud rejected this API key.');

            return;
        }

        $device->update([
            'proxy_cloud_api_key' => $this->proxy_cloud_api_key,
            'proxy_cloud' => true,
        ]);

        $this->reset('proxy_cloud_api_key', 'proxyKeyDeviceId');
        Flux::modal('proxy-cloud-api-key')->close();
        $this->devices = auth()->user()->devices()->with('deviceModel')->get();
        Flux::toast(variant: 'success', text: 'Proxy enabled.');
    }
}

?>

<div>
    <div class="py-12">
        {{--@dump($devices)--}}
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="w-full min-w-0 sm:flex-1">
                    <livewire:device-resource-nav />
                </div>
                <flux:modal.trigger name="create-device" class="shrink-0">
                    <flux:button icon="plus" variant="primary" class="w-full sm:w-auto">Add Device</flux:button>
                </flux:modal.trigger>
            </div>
            <flux:modal name="create-device" class="md:w-96">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Add Device</flux:heading>
                    </div>

                    @if (auth()->id() === 1)
                        <div class="space-y-3">
                            <livewire:actions.device-auto-join :key="'create-device-modal'" />
                            <flux:text>
                                Click the button above to permit auto join for {{ \App\Models\User::AUTO_JOIN_WINDOW_MINUTES }} minutes.
                                Then point the device at this server's base url to finish setup. After it appears, add
                                recipes to a device playlist to get started.
                            </flux:text>
                        </div>
                        <flux:separator text="Or add manually" />
                    @endif

                    <form wire:submit="createDevice">
                        <div class="mb-4">
                            <flux:input
                                label="Name"
                                wire:model="name"
                                id="name"
                                class="mb-1 block w-full"
                                type="text"
                                name="name"
                                autofocus
                            />
                        </div>

                        <div class="mb-4">
                            <flux:input
                                label="Mac Address"
                                wire:model="mac_address"
                                id="mac_address"
                                class="mb-1 block w-full"
                                type="text"
                                name="mac_address"
                                autofocus
                            />
                        </div>

                        <div class="mb-4">
                            <flux:input
                                label="API Key"
                                description="To proxy the cloud service, copy from Device Settings → Developer Perks; otherwise, create a new one."
                                wire:model="api_key"
                                id="api_key"
                                class="mb-1 block w-full"
                                type="text"
                                name="api_key"
                            >
                                <x-slot:iconTrailing>
                                    <flux:tooltip content="Generate API key">
                                        <flux:button
                                            variant="subtle"
                                            class="-me-1"
                                            square
                                            size="sm"
                                            inset="left right"
                                            icon="dices"
                                            wire:click="generateApiKey"
                                            aria-label="Generate API key"
                                        />
                                    </flux:tooltip>
                                </x-slot:iconTrailing>
                            </flux:input>
                        </div>

                        <div class="mb-4">
                            <flux:input
                                label="Friendly Id"
                                description="Copy from trmnl.com or generate a new one."
                                wire:model="friendly_id"
                                id="friendly_id"
                                class="mb-1 block w-full"
                                type="text"
                                name="friendly_id"
                            >
                                <x-slot:iconTrailing>
                                    <flux:tooltip content="Generate friendly ID">
                                        <flux:button
                                            variant="subtle"
                                            class="-me-1"
                                            square
                                            size="sm"
                                            inset="left right"
                                            icon="dices"
                                            wire:click="generateFriendlyId"
                                            aria-label="Generate friendly ID"
                                        />
                                    </flux:tooltip>
                                </x-slot:iconTrailing>
                            </flux:input>
                        </div>

                        <div class="mb-4">
                            <flux:input
                                label="Refresh Rate (seconds)"
                                wire:model="default_refresh_interval"
                                id="default_refresh_interval"
                                class="mb-1 block w-full"
                                type="number"
                                name="default_refresh_interval"
                                autofocus
                            />
                        </div>

                        <div class="mb-4">
                            <flux:select label="Device Model" wire:model.live="device_model_id">
                                <flux:select.option value="">Custom (Manual Dimensions)</flux:select.option>
                                @if ($deviceModels && $deviceModels->count() > 0)
                                    @foreach ($deviceModels as $deviceModel)
                                        <flux:select.option value="{{ $deviceModel->id }}">
                                            {{ $deviceModel->label }} ({{ $deviceModel->width }}x{{ $deviceModel->height }})
                                        </flux:select.option>
                                    @endforeach
                                @endif
                            </flux:select>
                        </div>

                        <div class="mb-4">
                            <flux:checkbox wire:model.live="is_mirror" label="Mirrors Device" />
                        </div>

                        @if ($is_mirror)
                            <div class="mb-4">
                                <flux:select wire:model="mirror_device_id" label="Select Device to Mirror">
                                    <flux:select.option value="">Select a device</flux:select.option>
                                    @foreach (auth()->user()->devices->where('mirror_device_id', null) as $device)
                                        <flux:select.option value="{{ $device->id }}">
                                            {{ $device->name }} ({{ $device->friendly_id }})
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif

                        <div class="flex">
                            <flux:spacer />
                            <flux:button type="submit" variant="primary">Create Device</flux:button>
                        </div>
                    </form>
                </div>
            </flux:modal>

            @if ($this->devicesAwaitingActivation->isNotEmpty())
                <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
                    <flux:callout.heading>Awaiting activation</flux:callout.heading>
                    <flux:callout.text>
                        {{ $this->devicesAwaitingActivation->pluck('name')->join(', ') }} {{ $this->devicesAwaitingActivation->count() === 1 ? 'has' : 'have' }} not
                        connected yet. Point the device at this server to finish setup. The first setup request
                        activates the device and hands out its API key. Until then, anyone who knows its MAC address can
                        claim the key. After activation, setup can only run again while Auto-Join is permitted.
                    </flux:callout.text>
                </flux:callout>
            @endif

            <table
                class="min-w-full table-fixed divide-y divide-zinc-800/10 text-zinc-800 dark:divide-white/20"
                data-flux-table=""
            >
                <thead data-flux-columns="">
                    <tr>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Name</div>
                        </th>
                        <th
                            class="hidden px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 md:table-cell dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Friendly ID</div>
                        </th>
                        <th
                            class="hidden px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 md:table-cell dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Mac Address</div>
                        </th>
                        <th
                            class="hidden px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 md:table-cell dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Refresh</div>
                        </th>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Actions</div>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-800/10 dark:divide-white/20" data-flux-rows="">
                    @foreach ($devices as $device)
                        <tr data-flux-row="">
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                <a
                                    href="{{ route('devices.configure', $device) }}"
                                    wire:navigate
                                    class="font-medium hover:underline dark:text-zinc-200"
                                >{{ $device->name }}</a>
                            </td>
                            <td class="hidden px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 md:table-cell dark:text-zinc-300">
                                {{ $device->friendly_id }}
                            </td>
                            <td class="hidden px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 md:table-cell dark:text-zinc-300">
                                <div
                                    type="button"
                                    data-flux-badge="data-flux-badge"
                                    class="[&_[data-flux-badge-icon]]:size-3 [&_[data-flux-badge-icon]]:mr-1 [&_button]:!text-zinc-700 [&_button]:dark:!text-zinc-200 [&:is(button)]:hover:bg-zinc-400/25 [&:is(button)]:hover:dark:bg-zinc-400/50 -mt-1 -mb-1 inline-flex items-center rounded-md bg-zinc-400/15 px-2 py-1 font-mono text-xs font-medium whitespace-nowrap text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200"
                                >
                                    {{ $device->mac_address }}
                                </div>
                            </td>
                            <td class="hidden px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 md:table-cell dark:text-zinc-300">
                                {{ $device->default_refresh_interval }}
                            </td>
                            <td class="px-3 py-3 text-sm font-medium whitespace-nowrap text-zinc-800 first:pl-0 last:pr-0 dark:text-white">
                                <div class="flex items-center gap-4">
                                    <div class="flex items-center gap-1">
                                        <flux:button.group>
                                            <flux:tooltip content="View device" position="bottom">
                                                <flux:button
                                                    href="{{ route('devices.configure', $device) }}"
                                                    wire:navigate
                                                    icon="eye"
                                                    iconVariant="outline"
                                                />
                                            </flux:tooltip>
                                            @if ($device->isPauseActive())
                                                <flux:modal.trigger name="unpause-device-{{ $device->id }}">
                                                    <flux:tooltip content="Device paused until: {{ $device->pause_until->diffForHumans() }}">
                                                        <flux:button icon="pause-circle" />
                                                    </flux:tooltip>
                                                </flux:modal.trigger>
                                            @else
                                                <flux:modal.trigger name="pause-device-{{ $device->id }}">
                                                    <flux:tooltip content="Pause screen generation" position="bottom">
                                                        <flux:button icon="pause-circle" iconVariant="outline" />
                                                    </flux:tooltip>
                                                </flux:modal.trigger>
                                            @endif
                                        </flux:button.group>
                                        <x-devices.actions-menu :device="$device" />
                                    </div>

                                    <flux:tooltip
                                        content="Proxies images from the TRMNL Cloud service when no image is set (available in TRMNL DEV Edition only)."
                                        position="bottom"
                                    >
                                        <flux:switch
                                            wire:click="toggleProxyCloud({{ $device->id }})"
                                            :checked="$device->proxy_cloud"
                                            :disabled="$device->mirror_device_id !== null"
                                            label="☁️ Proxy"
                                        />
                                    </flux:tooltip>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <flux:modal name="proxy-cloud-api-key" class="md:w-96">
        <form wire:submit="saveProxyCloudApiKey" class="space-y-6">
            <div>
                <flux:heading size="lg">Cloud API key</flux:heading>
                <flux:text class="mt-2">
                    TRMNL cloud could not find this device. Enter the cloud device API key from Device Settings →
                    Developer Perks.
                </flux:text>
            </div>

            <flux:input label="Cloud API key" wire:model="proxy_cloud_api_key" type="text" name="proxy_cloud_api_key" />

            <div class="flex">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    @foreach ($devices as $device)
        <x-devices.action-modals
            :device="$device"
            :firmwares="$this->firmwares"
            :selected-firmware-model="$this->selected_firmware_model"
            :simulate-physical-device="$this->simulate_physical_device"
            :timezone="$this->timezone"
            :selectable-plugins="$this->selectablePlugins"
        />
    @endforeach
</div>
