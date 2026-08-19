<?php

use App\Models\Device;
use App\Models\DeviceModel;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
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

    public ?int $pause_duration = null;

    public ?string $pause_until_date = null;

    public ?string $pause_until_time = null;

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

    #[Computed]
    public function timezone(): string
    {
        return auth()->user()->preferredTimezone();
    }

    public function updated(string $property): void
    {
        if ($property === 'device_model_id' && empty($this->device_model_id)) {
            $this->device_model_id = null;
        }

        if ($property === 'pause_duration' && $this->pause_duration !== null) {
            $this->pause_until_date = $this->pause_until_time = null;
        }

        if (in_array($property, ['pause_until_date', 'pause_until_time'], true) && $this->{$property}) {
            $this->pause_duration = null;
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

        $this->devices = auth()->user()->devices()->with('deviceModel')->get();
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

    public function pauseDevice(int $deviceId): void
    {
        $device = auth()->user()->devices()->findOrFail($deviceId);
        $device->update(['pause_until' => $this->resolvePauseUntil()]);
        $this->reset('pause_duration', 'pause_until_date', 'pause_until_time');
        Flux::modal('pause-device-'.$deviceId)->close();
        $this->devices = auth()->user()->devices()->with('deviceModel')->get();

        $pauseUntil = $device->pause_until->timezone($this->timezone);
        Flux::toast(variant: 'success', text: "Device paused until {$pauseUntil} {$this->timezone}");
    }

    public function unpauseDevice(int $deviceId): void
    {
        $device = auth()->user()->devices()->findOrFail($deviceId);
        $device->update(['pause_until' => null]);
        Flux::modal('unpause-device-'.$deviceId)->close();
        $this->devices = auth()->user()->devices()->with('deviceModel')->get();
        Flux::toast(variant: 'success', text: 'Pause cleared. Wake your device manually to resume.');
    }

    private function resolvePauseUntil(): Carbon
    {
        if (filled($this->pause_until_date) && filled($this->pause_until_time)) {
            $now = now($this->timezone);
            $pauseUntil = Carbon::parse("{$this->pause_until_date} {$this->pause_until_time}", $this->timezone);

            if ($pauseUntil->lte($now) || $pauseUntil->gt($now->copy()->addDays(Device::MAX_PAUSE_DAYS))) {
                throw ValidationException::withMessages([
                    'pause_until_date' => $pauseUntil->lte($now)
                        ? 'The pause time must be in the future.'
                        : 'The pause time cannot be more than '.Device::MAX_PAUSE_DAYS.' days in the future.',
                ]);
            }

            return $pauseUntil->utc();
        }

        $this->validate(['pause_duration' => 'required|integer']);

        return now()->addMinutes((int) $this->pause_duration);
    }
}

?>

<div>
    <div class="py-12">
        {{--@dump($devices)--}}
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <div class="mb-6 flex items-center justify-between">
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
                            <flux:input
                                label="Name"
                                wire:model="name"
                                id="name"
                                class="mt-1 block w-full"
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
                                class="mt-1 block w-full"
                                type="text"
                                name="mac_address"
                                autofocus
                            />
                        </div>

                        <div class="mb-4">
                            <flux:input
                                label="API Key"
                                wire:model="api_key"
                                id="api_key"
                                class="mt-1 block w-full"
                                type="text"
                                name="api_key"
                                autofocus
                            />
                        </div>

                        <div class="mb-4">
                            <flux:input
                                label="Friendly Id"
                                wire:model="friendly_id"
                                id="friendly_id"
                                class="mt-1 block w-full"
                                type="text"
                                name="friendly_id"
                                autofocus
                            />
                        </div>

                        <div class="mb-4">
                            <flux:input
                                label="Refresh Rate (seconds)"
                                wire:model="default_refresh_interval"
                                id="default_refresh_interval"
                                class="mt-1 block w-full"
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
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Friendly ID</div>
                        </th>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Mac Address</div>
                        </th>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
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
                                {{ $device->name }}
                            </td>
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                {{ $device->friendly_id }}
                            </td>
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                <div
                                    type="button"
                                    data-flux-badge="data-flux-badge"
                                    class="[&_[data-flux-badge-icon]]:size-3 [&_[data-flux-badge-icon]]:mr-1 [&_button]:!text-zinc-700 [&_button]:dark:!text-zinc-200 [&:is(button)]:hover:bg-zinc-400/25 [&:is(button)]:hover:dark:bg-zinc-400/50 -mt-1 -mb-1 inline-flex items-center rounded-md bg-zinc-400/15 px-2 py-1 text-xs font-medium whitespace-nowrap text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200"
                                >
                                    {{ $device->mac_address }}
                                </div>
                            </td>
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                {{ $device->default_refresh_interval }}
                            </td>
                            <td class="px-3 py-3 text-sm font-medium whitespace-nowrap text-zinc-800 first:pl-0 last:pr-0 dark:text-white">
                                <div class="flex items-center gap-4">
                                    <flux:button.group>
                                        <flux:button
                                            href="{{ route('devices.configure', $device) }}"
                                            wire:navigate
                                            icon="eye"
                                            iconVariant="outline"
                                        >
                                        </flux:button>
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

                    <!--[if ENDBLOCK[endif]-->
                </tbody>
            </table>
        </div>
    </div>

    @foreach ($devices as $device)
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
                            min="{{ now($this->timezone)->toDateString() }}"
                            max="{{ now($this->timezone)->addDays(\App\Models\Device::MAX_PAUSE_DAYS)->toDateString() }}"
                        />
                        <flux:input type="time" label="Time" wire:model.live="pause_until_time" />
                    </div>
                    <flux:text class="text-zinc-500">Timezone: {{ $this->timezone }}</flux:text>
                    <flux:text class="mt-2 mb-4">The device will still ping the server every 24 hours.</flux:text>
                    <flux:error name="pause_until_date" />

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
                        Paused until {{ $device->pause_until?->timezone($this->timezone) }} {{ $this->timezone }}</flux:callout.heading>
                    <flux:callout.text>
                        @if ($device->usesTouchBar())
                            To exit pause early, click "End pause" and press the touch bar in the middle of your device.
                        @else
                            To exit pause early, click "End pause" and press the physical screen button on your device.
                        @endif
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button wire:click="unpauseDevice({{ $device->id }})" variant="primary">
                            End pause
                        </flux:button>
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
    @endforeach
</div>
