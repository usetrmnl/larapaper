<?php

use App\Models\Device;
use App\Models\DeviceModel;
use Livewire\Component;

new class extends Component
{
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

    public ?int $pause_duration;

    protected $rules = [
        'mac_address' => 'required',
        'api_key' => 'required',
        'default_refresh_interval' => 'required|integer',
        'device_model_id' => 'nullable|exists:device_models,id',
        'mirror_device_id' => 'required_if:is_mirror,true',
    ];

    public function mount()
    {
        $this->devices = auth()->user()->devices;
        $this->deviceModels = DeviceModel::orderBy('label')->get()->sortBy(function ($deviceModel) {
            // Put TRMNL models at the top, then sort alphabetically within each group
            $isTrmnl = str_starts_with($deviceModel->label, 'TRMNL');

            return $isTrmnl ? '0'.$deviceModel->label : '1'.$deviceModel->label;
        });

        return view('livewire.devices.manage');
    }

    public function updatedDeviceModelId(): void
    {
        // Convert empty string to null for custom selection
        if (empty($this->device_model_id)) {
            $this->device_model_id = null;
        }
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

        $this->devices = auth()->user()->devices;
        Flux::toast(variant: 'success', text: 'Device created successfully.');
    }

    public function toggleProxyCloud(Device $device): void
    {
        abort_unless(auth()->user()->devices->contains($device), 403);
        $device->update([
            'proxy_cloud' => ! $device->proxy_cloud,
        ]);

        // if ($device->proxy_cloud) {
        //     \App\Jobs\FetchProxyCloudResponses::dispatch();
        // }
    }

    public function pauseDevice($deviceId): void
    {
        $this->validate([
            'pause_duration' => 'required|integer',
        ]);
        $device = auth()->user()->devices()->findOrFail($deviceId);
        $pauseUntil = now()->addMinutes($this->pause_duration);
        $device->update(['pause_until' => $pauseUntil]);
        $this->reset('pause_duration');
        Flux::modal('pause-device-'.$deviceId)->close();
        $this->devices = auth()->user()->devices;
        Flux::toast(variant: 'success', text: 'Device paused until '.$pauseUntil->format('H:i'));
    }
}

?>

<div>
    <div class="py-12">
        {{--@dump($devices)--}}
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-between items-center mb-6">
                <livewire:device-resource-nav />
                <flux:modal.trigger name="create-device">
                    <flux:button icon="plus" variant="primary">Add Device</flux:button>
                </flux:modal.trigger>
            </div>
            <flux:modal name="create-device" class="md:w-96">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Add Device</flux:heading>
                    </div>

                    <form wire:submit="createDevice">
                        <div class="mb-4">
                            <flux:input label="Name" wire:model="name" id="name" class="block mt-1 w-full" type="text"
                                        name="name"
                                        autofocus/>
                        </div>

                        <div class="mb-4">
                            <flux:input label="Mac Address" wire:model="mac_address" id="mac_address"
                                        class="block mt-1 w-full"
                                        type="text" name="mac_address" autofocus/>
                        </div>

                        <div class="mb-4">
                            <flux:input label="API Key" wire:model="api_key" id="api_key" class="block mt-1 w-full"
                                        type="text"
                                        name="api_key" autofocus/>
                        </div>

                        <div class="mb-4">
                            <flux:input label="Friendly Id" wire:model="friendly_id" id="friendly_id"
                                        class="block mt-1 w-full"
                                        type="text" name="friendly_id" autofocus/>
                        </div>

                        <div class="mb-4">
                            <flux:input label="Refresh Rate (seconds)" wire:model="default_refresh_interval"
                                        id="default_refresh_interval"
                                        class="block mt-1 w-full" type="number" name="default_refresh_interval"
                                        autofocus/>
                        </div>

                        <div class="mb-4">
                            <flux:select label="Device Model" wire:model.live="device_model_id">
                                <flux:select.option value="">Custom (Manual Dimensions)</flux:select.option>
                              @if ($deviceModels && $deviceModels->count() > 0)
                                    @foreach($deviceModels as $deviceModel)
                                        <flux:select.option value="{{ $deviceModel->id }}">
                                            {{ $deviceModel->label }} ({{ $deviceModel->width }}x{{ $deviceModel->height }})
                                        </flux:select.option>
                                    @endforeach
                                @endif
                            </flux:select>
                        </div>

                        <div class="mb-4">
                            <flux:checkbox wire:model.live="is_mirror" label="Mirrors Device"/>
                        </div>

                        @if($is_mirror)
                            <div class="mb-4">
                                <flux:select wire:model="mirror_device_id" label="Select Device to Mirror">
                                    <flux:select.option value="">Select a device</flux:select.option>
                                    @foreach(auth()->user()->devices->where('mirror_device_id', null) as $device)
                                        <flux:select.option value="{{ $device->id }}">
                                            {{ $device->name }} ({{ $device->friendly_id }})
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif

                        <div class="flex">
                            <flux:spacer/>
                            <flux:button type="submit" variant="primary">Create Device</flux:button>
                        </div>

                    </form>
                </div>
            </flux:modal>

            <table
                class="min-w-full table-fixed text-zinc-800 divide-y divide-zinc-800/10 dark:divide-white/20 text-zinc-800"
                data-flux-table="">
                <thead data-flux-columns="">
                <tr>
                    <th class="py-3 px-3 first:pl-0 last:pr-0 text-left text-sm font-medium text-zinc-800 dark:text-white"
                        data-flux-column="">
                        <div class="whitespace-nowrap flex group-[]/right-align:justify-end">Name</div>
                    </th>
                    <th class="py-3 px-3 first:pl-0 last:pr-0 text-left text-sm font-medium text-zinc-800 dark:text-white"
                        data-flux-column="">
                        <div class="whitespace-nowrap flex group-[]/right-align:justify-end">Friendly ID</div>
                    </th>
                    <th class="py-3 px-3 first:pl-0 last:pr-0 text-left text-sm font-medium text-zinc-800 dark:text-white"
                        data-flux-column="">
                        <div class="whitespace-nowrap flex group-[]/right-align:justify-end">Mac Address</div>
                    </th>
                    <th class="py-3 px-3 first:pl-0 last:pr-0 text-left text-sm font-medium text-zinc-800 dark:text-white"
                        data-flux-column="">
                        <div class="whitespace-nowrap flex group-[]/right-align:justify-end">Refresh</div>
                    </th>
                    <th class="py-3 px-3 first:pl-0 last:pr-0 text-left text-sm font-medium text-zinc-800 dark:text-white"
                        data-flux-column="">
                        <div class="whitespace-nowrap flex group-[]/right-align:justify-end">Actions</div>
                    </th>
                </tr>
                </thead>

                <tbody class="divide-y divide-zinc-800/10 dark:divide-white/20" data-flux-rows="">
                @foreach ($devices as $device)
                    <tr data-flux-row="">
                        <td class="py-3 px-3 first:pl-0 last:pr-0 text-sm whitespace-nowrap  text-zinc-500 dark:text-zinc-300"
                        >
                            {{ $device->name }}
                        </td>
                        <td class="py-3 px-3 first:pl-0 last:pr-0 text-sm whitespace-nowrap  text-zinc-500 dark:text-zinc-300"
                        >
                            {{ $device->friendly_id }}
                        </td>
                        <td class="py-3 px-3 first:pl-0 last:pr-0 text-sm whitespace-nowrap  text-zinc-500 dark:text-zinc-300"
                        >
                            <div type="button" data-flux-badge="data-flux-badge"
                                 class="inline-flex items-center font-medium whitespace-nowrap -mt-1 -mb-1 text-xs py-1 [&_[data-flux-badge-icon]]:size-3 [&_[data-flux-badge-icon]]:mr-1 rounded-md px-2 text-zinc-700 [&_button]:!text-zinc-700 dark:text-zinc-200 [&_button]:dark:!text-zinc-200 bg-zinc-400/15 dark:bg-zinc-400/40 [&:is(button)]:hover:bg-zinc-400/25 [&:is(button)]:hover:dark:bg-zinc-400/50">
                                {{ $device->mac_address }}
                            </div>
                        </td>
                        <td class="py-3 px-3 first:pl-0 last:pr-0 text-sm whitespace-nowrap  text-zinc-500 dark:text-zinc-300"
                        >
                            {{ $device->default_refresh_interval }}
                        </td>
                        <td class="py-3 px-3 first:pl-0 last:pr-0 text-sm whitespace-nowrap  font-medium text-zinc-800 dark:text-white"
                        >
                            <div class="flex items-center gap-4">
                                <flux:button.group>

                                <flux:button href="{{ route('devices.configure', $device) }}" wire:navigate icon="eye" iconVariant="outline">
                                </flux:button>
                                @if($device->isPauseActive())
                                    <flux:tooltip content="Device paused until: {{ $device->pause_until?->format('H:i') }}">
                                        <flux:button icon="pause-circle"/>
                                    </flux:tooltip>
                                @else
                                    <flux:modal.trigger name="pause-device-{{ $device->id }}">
                                        <flux:button icon="pause-circle" iconVariant="outline">
                                        </flux:button>
                                    </flux:modal.trigger>
                                @endif
                                </flux:button.group>

                                <flux:tooltip
                                    content="Proxies images from the TRMNL Cloud service when no image is set (available in TRMNL DEV Edition only)."
                                    position="bottom">
                                    <flux:switch wire:click="toggleProxyCloud({{ $device->id }})"
                                                 :checked="$device->proxy_cloud"
                                                 :disabled="$device->mirror_device_id !== null"
                                                 label="☁️ Proxy"/>
                                </flux:tooltip>
                            </div>
                        </td>
                    </tr>
                @endforeach

                <!--[if ENDBLOCK]><![endif]-->
                </tbody>
            </table>
        </div>
    </div>

    @foreach ($devices as $device)
        <flux:modal name="pause-device-{{ $device->id }}">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Pause</flux:heading>
                    <div class="text-sm text-zinc-500 mt-2">Select how long to pause screen generation for <span
                            class="font-semibold">{{ $device->name }}</span>.
                    </div>
                </div>
                <form wire:submit="pauseDevice({{ $device->id }})">
                    <div class="mb-4">
                        <flux:radio.group wire:model.live="pause_duration" label="Pause Duration" variant="segmented">
                            <flux:radio value="30" label="30 min"/>
                            <flux:radio value="60" label="60 min"/>
                            <flux:radio value="120" label="120 min"/>
                            <flux:radio value="240" label="240 min"/>
                            <flux:radio value="480" label="480 min"/>
                        </flux:radio.group>
                    </div>
                    <div class="flex">
                        <flux:spacer/>
                        <flux:modal.close>
                            <flux:button variant="ghost">Cancel</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary">Save</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endforeach
</div>
