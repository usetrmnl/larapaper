<?php

use App\Enums\FirmwareModel;
use App\Enums\ScaleLevel;
use App\Jobs\FirmwareDownloadJob;
use App\Jobs\FirmwarePollJob;
use App\Models\DeviceModel;
use App\Models\Firmware;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    private const DEFAULT_SLEEP_MODE_FROM = '22:00';

    private const DEFAULT_SLEEP_MODE_TO = '06:00';

    public $device;

    public $name;

    public $api_key;

    public $friendly_id;

    public $mac_address;

    public $default_refresh_interval;

    public $width;

    public $height;

    public $rotate;

    public $image_format;

    public $device_model_id;

    public $is_mirror = false;

    public $mirror_device_id = null;

    // Signal to device to use high compatibility approaches when redrawing content
    public $maximum_compatibility = false;

    // Interface scale; null follows the device model
    public $scale_level = null;

    // Sleep mode and special function
    public $sleep_mode_enabled = false;

    public $sleep_mode_from;

    public $sleep_mode_to;

    public $special_function;

    // Playlist properties
    public $playlists;

    public $playlist_name;

    public $selected_weekdays = null;

    public $active_from;

    public $active_until;

    public $refresh_time = null;

    // Device model properties
    public $deviceModels;

    // Firmware properties
    public $firmwares;

    public $selected_firmware_model;

    public $selected_firmware_id;

    public $download_firmware;

    public function mount(App\Models\Device $device)
    {
        abort_unless(auth()->user()->devices->contains($device), 403);

        $current_image_uuid = $device->current_screen_image;
        $current_image_path = 'images/generated/'.$current_image_uuid.'.png';

        $this->device = $device;
        $this->name = $device->name;
        $this->api_key = $device->api_key;
        $this->friendly_id = $device->friendly_id;
        $this->mac_address = $device->mac_address;
        $this->default_refresh_interval = $device->default_refresh_interval;
        $this->width = $device->width;
        $this->height = $device->height;
        $this->rotate = $device->rotate;
        $this->image_format = $device->image_format;
        $this->device_model_id = $device->device_model_id;
        $this->maximum_compatibility = $device->maximum_compatibility;
        $this->scale_level = $device->scale_level?->value;
        $this->deviceModels = DeviceModel::orderBy('label')->get()->sortBy(function ($deviceModel) {
            // Put TRMNL models at the top, then sort alphabetically within each group
            $isTrmnl = str_starts_with($deviceModel->label, 'TRMNL');

            return $isTrmnl ? '0'.$deviceModel->label : '1'.$deviceModel->label;
        });
        $this->playlists = $device->playlists()->with('items.plugin')->orderBy('created_at')->get();
        $this->selected_firmware_model = FirmwareModel::forDevice($device)->value;
        $this->refreshFirmwareList();
        $this->sleep_mode_enabled = $device->sleep_mode_enabled ?? false;
        $this->sleep_mode_from = optional($device->sleep_mode_from)->format('H:i');
        $this->sleep_mode_to = optional($device->sleep_mode_to)->format('H:i');
        $this->special_function = $device->special_function;
        $this->is_mirror = $device->mirror_device_id !== null;
        $this->mirror_device_id = $device->mirror_device_id;

        $this->applyDefaultSleepModeTimes();

        return view('livewire.devices.configure', [
            'image' => ($current_image_uuid) ? url($current_image_path) : null,
        ]);
    }

    public function updatedSleepModeEnabled(bool $enabled): void
    {
        if (! $enabled) {
            return;
        }

        $this->applyDefaultSleepModeTimes();
    }

    private function applyDefaultSleepModeTimes(): void
    {
        if (! $this->sleep_mode_enabled) {
            return;
        }

        $this->sleep_mode_from ??= self::DEFAULT_SLEEP_MODE_FROM;
        $this->sleep_mode_to ??= self::DEFAULT_SLEEP_MODE_TO;
    }

    public function deleteDevice(App\Models\Device $device)
    {
        abort_unless(auth()->user()->devices->contains($device), 403);
        $device->delete();

        redirect()->route('devices');
    }

    public function updatedDeviceModelId()
    {
        // Convert empty string to null for custom selection
        if (empty($this->device_model_id)) {
            $this->device_model_id = null;

            return;
        }

        if ($this->device_model_id) {
            $deviceModel = DeviceModel::find($this->device_model_id);
            if ($deviceModel) {
                $this->width = $deviceModel->width;
                $this->height = $deviceModel->height;
                $this->rotate = $deviceModel->rotation;
            }
        }
    }

    public function updateDevice()
    {
        abort_unless(auth()->user()->devices->contains($this->device), 403);

        $this->validate([
            'name' => 'required|string|max:255',
            'friendly_id' => 'required|string|max:255',
            'mac_address' => 'required|string|max:255',
            'default_refresh_interval' => 'required|integer|min:1',
            'width' => 'required|integer|min:1',
            'height' => 'required|integer|min:1',
            'rotate' => 'required|integer|min:0|max:359',
            'image_format' => 'required|string',
            'device_model_id' => 'nullable|exists:device_models,id',
            'mirror_device_id' => 'required_if:is_mirror,true',
            'maximum_compatibility' => 'boolean',
            'scale_level' => ['nullable', Rule::enum(ScaleLevel::class)],
            'sleep_mode_enabled' => 'boolean',
            'sleep_mode_from' => 'nullable|required_if:sleep_mode_enabled,true|date_format:H:i',
            'sleep_mode_to' => 'nullable|required_if:sleep_mode_enabled,true|date_format:H:i',
            'special_function' => 'nullable|string',
        ], [
            'sleep_mode_from.required_if' => 'A sleep mode start time is required when sleep mode is enabled.',
            'sleep_mode_to.required_if' => 'A sleep mode end time is required when sleep mode is enabled.',
        ]);

        if ($this->is_mirror) {
            $mirrorDevice = auth()->user()->devices()->find($this->mirror_device_id);
            abort_unless($mirrorDevice, 403, 'Invalid mirror device selected');
            abort_if($mirrorDevice->mirror_device_id !== null, 403, 'Cannot mirror a device that is already a mirror device');
            abort_if((int) $this->mirror_device_id === (int) $this->device->id, 403, 'Device cannot mirror itself');
        }

        // Convert empty string to null for custom selection
        $deviceModelId = empty($this->device_model_id) ? null : $this->device_model_id;

        $this->device->update([
            'name' => $this->name,
            'friendly_id' => $this->friendly_id,
            'mac_address' => $this->mac_address,
            'default_refresh_interval' => $this->default_refresh_interval,
            'width' => $this->width,
            'height' => $this->height,
            'rotate' => $this->rotate,
            'image_format' => $this->image_format,
            'device_model_id' => $deviceModelId,
            'mirror_device_id' => $this->is_mirror ? $this->mirror_device_id : null,
            'maximum_compatibility' => $this->maximum_compatibility,
            'scale_level' => empty($this->scale_level) ? null : $this->scale_level,
            'sleep_mode_enabled' => $this->sleep_mode_enabled,
            'sleep_mode_from' => $this->sleep_mode_from,
            'sleep_mode_to' => $this->sleep_mode_to,
            'special_function' => $this->special_function,
        ]);

        Flux::modal('edit-device')->close();
    }

    public function createPlaylist()
    {
        $this->validate([
            'playlist_name' => 'required|string|max:255',
            'selected_weekdays' => 'nullable|array',
            'active_from' => 'nullable|date_format:H:i',
            'active_until' => 'nullable|date_format:H:i',
            'refresh_time' => 'nullable|integer|min:60',
        ]);

        if ($this->refresh_time < 60) {
            $this->refresh_time = null;
        }

        if (empty($this->selected_weekdays)) {
            $this->selected_weekdays = null;
        }

        $this->device->playlists()->create([
            'name' => $this->playlist_name,
            'weekdays' => $this->selected_weekdays,
            'active_from' => $this->active_from,
            'active_until' => $this->active_until,
            'refresh_time' => $this->refresh_time,
            'is_active' => true,
        ]);

        $this->playlists = $this->device->playlists()->with('items.plugin')->orderBy('created_at')->get();
        $this->reset(['playlist_name', 'selected_weekdays', 'active_from', 'active_until']);
        Flux::modal('create-playlist')->close();
    }

    public function togglePlaylistActive(Playlist $playlist)
    {
        $playlist->update(['is_active' => ! $playlist->is_active]);
        $this->playlists = $this->device->playlists()->with('items.plugin')->orderBy('created_at')->get();
    }

    public function sortPlaylistItem(int $id, int $position): void
    {
        $item = PlaylistItem::query()->with('playlist.device')->findOrFail($id);
        abort_unless(auth()->user()->devices->contains($item->playlist->device), 403);

        $items = $item->playlist->items()->orderBy('order')->orderBy('id')->get();
        $ids = $items->pluck('id')->all();
        $currentIndex = array_search($id, $ids, true);

        if ($currentIndex === false) {
            return;
        }

        $ids = array_values(array_diff($ids, [$id]));
        array_splice($ids, $position, 0, [$id]);

        foreach ($ids as $index => $itemId) {
            PlaylistItem::query()->whereKey($itemId)->update(['order' => $index]);
        }

        $this->playlists = $this->device->playlists()->with('items.plugin')->orderBy('created_at')->get();
    }

    public function togglePlaylistItemActive(PlaylistItem $item)
    {
        $item->update(['is_active' => ! $item->is_active]);
        $this->playlists = $this->device->playlists()->with('items.plugin')->orderBy('created_at')->get();
    }

    public function deletePlaylist(Playlist $playlist)
    {
        abort_unless(auth()->user()->devices->contains($playlist->device), 403);
        $playlist->delete();
        $this->playlists = $this->device->playlists()->with('items.plugin')->orderBy('created_at')->get();
        Flux::modal('delete-playlist-'.$playlist->id)->close();
    }

    public function deletePlaylistItem(PlaylistItem $item)
    {
        abort_unless(auth()->user()->devices->contains($item->playlist->device), 403);
        $item->delete();
        $this->playlists = $this->device->playlists()->with('items.plugin')->orderBy('created_at')->get();
        Flux::modal('delete-playlist-item-'.$item->id)->close();
    }

    public function clearPluginImageCache(PlaylistItem $item): void
    {
        $item->loadMissing(['playlist.device', 'plugin']);

        abort_unless(auth()->user()->devices->contains($item->playlist->device), 403);

        if ($item->isMashup()) {
            return;
        }

        if (! $item->plugin_id || ! $item->plugin || $item->plugin->plugin_type !== 'recipe') {
            return;
        }

        abort_unless($item->plugin->user_id === auth()->id(), 403);

        $item->plugin->clearCurrentImage();

        Flux::toast(variant: 'success', text: 'Image cache cleared.');

        $this->playlists = $this->device->playlists()->with('items.plugin')->orderBy('created_at')->get();
    }

    public function editPlaylist(Playlist $playlist)
    {
        $this->validate([
            'playlist_name' => 'required|string|max:255',
            'selected_weekdays' => 'nullable|array',
            'active_from' => 'nullable|date_format:H:i',
            'active_until' => 'nullable|date_format:H:i',
            'refresh_time' => 'nullable|integer|min:60',
        ]);

        if (empty($this->active_from)) {
            $this->active_from = null;
        }
        if (empty($this->active_until)) {
            $this->active_until = null;
        }
        if ($this->refresh_time < 60) {
            $this->refresh_time = null;
        }

        if (empty($this->selected_weekdays)) {
            $this->selected_weekdays = null;
        }

        $playlist->update([
            'name' => $this->playlist_name,
            'weekdays' => $this->selected_weekdays,
            'active_from' => $this->active_from,
            'active_until' => $this->active_until,
            'refresh_time' => $this->refresh_time,
        ]);

        $this->playlists = $this->device->playlists()->with('items.plugin')->orderBy('created_at')->get();
        $this->reset(['playlist_name', 'selected_weekdays', 'active_from', 'active_until', 'refresh_time']);
        Flux::modal('edit-playlist-'.$playlist->id)->close();
    }

    public function preparePlaylistEdit(Playlist $playlist)
    {
        $this->playlist_name = $playlist->name;
        $this->selected_weekdays = $playlist->weekdays ?? null;
        $this->active_from = optional($playlist->active_from)->format('H:i');
        $this->active_until = optional($playlist->active_until)->format('H:i');
        $this->refresh_time = $playlist->refresh_time;
    }

    public function updatedSelectedFirmwareModel(): void
    {
        $this->selectLatestFirmwareForModel();
    }

    public function checkFirmwareUpdates(): void
    {
        abort_unless(auth()->user()->devices->contains($this->device), 403);

        FirmwarePollJob::dispatchSync();

        $this->refreshFirmwareList();
    }

    private function refreshFirmwareList(): void
    {
        $this->firmwares = Firmware::query()->orderedForSelection()->get();
        $this->selectLatestFirmwareForModel();
    }

    private function selectLatestFirmwareForModel(): void
    {
        $this->selected_firmware_id = $this->firmwares
            ->where('model', $this->selected_firmware_model)
            ->where('latest', true)
            ->first()?->id;
    }

    public function updateFirmware()
    {
        abort_unless(auth()->user()->devices->contains($this->device), 403);

        $this->validate([
            'selected_firmware_model' => ['required', Rule::enum(FirmwareModel::class)],
            'selected_firmware_id' => 'required|exists:firmware,id',
        ]);

        $firmware = Firmware::findOrFail($this->selected_firmware_id);
        $selectedModel = FirmwareModel::from($this->selected_firmware_model);

        if ($firmware->model !== $selectedModel) {
            $this->addError('selected_firmware_id', 'The selected firmware does not match the selected device model.');

            return;
        }

        if ($this->download_firmware) {
            FirmwareDownloadJob::dispatchSync($firmware);
        }

        $this->device->update([
            'update_firmware_id' => $this->selected_firmware_id,
        ]);

        Flux::modal('update-firmware')->close();
    }
}
?>

<div class="bg-muted flex flex-col items-center justify-center p-4 sm:p-6">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        @php
            $current_image_uuid = $device->current_screen_image;
            if ($current_image_uuid) {
                $file_extension = Storage::disk('public')->exists('images/generated/'.$current_image_uuid.'.png') ? 'png' : 'bmp';
                $current_image_url = Storage::disk('public')->url('images/generated/'.$current_image_uuid.'.'.$file_extension);
            } else {
                $current_image_url = asset('storage/images/setup-logo.bmp');
            }
        @endphp

        <flux:card body="divided">
            <flux:card.header class="items-start sm:items-center">
                <flux:card.heading class="w-full min-w-0 flex-1">
                    <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:gap-6">
                        <flux:tooltip
                            content="Friendly ID: {{ $device->friendly_id }}"
                            position="bottom"
                            class="min-w-0 shrink-0 sm:max-w-[12rem]"
                        >
                            <h1 class="truncate text-base font-medium sm:text-lg">{{ $device->name }}</h1>
                        </flux:tooltip>

                        <div class="flex w-full flex-wrap items-center justify-evenly gap-x-3 gap-y-2 text-sm font-normal sm:mx-auto sm:flex-1">
                            <flux:tooltip content="Last refresh" position="bottom" class="mx-auto shrink-0">
                                <span class="whitespace-nowrap">{{ $device->last_refreshed_at?->diffForHumans() }}</span>
                            </flux:tooltip>

                            <flux:tooltip content="MAC Address" position="bottom" class="mx-auto min-w-0 shrink-0">
                                <span class="text-center font-mono text-xs break-all sm:text-sm">{{ $device->mac_address }}</span>
                            </flux:tooltip>

                            @if ($device->last_firmware_version)
                                <flux:tooltip content="Firmware Version" position="bottom" class="mx-auto shrink-0">
                                    <span class="whitespace-nowrap">{{ $device->last_firmware_version }}</span>
                                </flux:tooltip>
                            @endif

                            @if ($device->wifiStrength)
                                <flux:tooltip content="Wi-Fi signal" position="bottom" class="mx-auto shrink-0">
                                    <x-responsive-icons.wifi
                                        :strength="$device->wifiStrength"
                                        :rssi="$device->last_rssi_level"
                                        class="dark:text-zinc-200"
                                    />
                                </flux:tooltip>
                            @endif

                            @if ($device->batteryPercent)
                                @if ($device->last_battery_charging)
                                    <flux:tooltip content="Charging …" position="bottom" class="mx-auto shrink-0">
                                        <flux:icon.battery-charging class="dark:text-zinc-200" />
                                    </flux:tooltip>
                                @else
                                    <flux:tooltip content="Battery" position="bottom" class="mx-auto shrink-0">
                                        <x-responsive-icons.battery :percent="$device->batteryPercent" />
                                    </flux:tooltip>
                                @endif
                            @endif

                            @if ($device->isPauseActive())
                                <flux:tooltip
                                    content="Pause active until {{ $device->pause_until?->format('H:i') }}"
                                    position="bottom"
                                    class="mx-auto shrink-0"
                                >
                                    <flux:icon name="pause-circle" variant="solid" />
                                </flux:tooltip>
                            @endif
                        </div>
                    </div>
                </flux:card.heading>

                <flux:card.actions>
                    <div class="flex items-center gap-1">
                        <flux:modal.trigger name="edit-device">
                            <flux:button icon="pencil-square" aria-label="Edit device" />
                        </flux:modal.trigger>

                        <flux:dropdown>
                            <flux:button icon="ellipsis-horizontal" variant="subtle" aria-label="Device actions" />
                            <flux:menu>
                                <flux:modal.trigger name="update-firmware">
                                    <flux:menu.item icon="arrow-up-circle">Update Firmware</flux:menu.item>
                                </flux:modal.trigger>
                                <flux:menu.item icon="bars-3" href="{{ route('devices.logs', $device) }}" wire:navigate>
                                    Show Logs
                                </flux:menu.item>
                                <flux:modal.trigger name="mirror-url">
                                    <flux:menu.item icon="link">Mirror URL</flux:menu.item>
                                </flux:modal.trigger>
                                <flux:menu.separator />
                                <flux:modal.trigger name="delete-device">
                                    <flux:menu.item icon="trash" variant="danger">Delete Device</flux:menu.item>
                                </flux:modal.trigger>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </flux:card.actions>
            </flux:card.header>

            <flux:modal name="edit-device" class="md:w-96">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Edit TRMNL</flux:heading>
                        <flux:subheading></flux:subheading>
                    </div>
                    <flux:input label="Name" wire:model="name" />

                    <flux:input
                        label="API Key"
                        icon="key"
                        value="{{ $device->api_key }}"
                        type="password"
                        viewable
                        class="max-w-xs"
                        readonly
                    />

                    <flux:input label="Friendly ID" wire:model="friendly_id" />
                    <flux:input label="MAC Address" wire:model="mac_address" />

                    <flux:input
                        label="Default Refresh Interval (seconds)"
                        wire:model="default_refresh_interval"
                        type="number"
                    />

                    <flux:select label="Device Model" wire:model.live="device_model_id">
                        <flux:select.option value="">Custom (Manual Dimensions)</flux:select.option>
                        @foreach ($deviceModels as $deviceModel)
                            <flux:select.option value="{{ $deviceModel->id }}">
                                {{ $deviceModel->label }} ({{ $deviceModel->width }}x{{ $deviceModel->height }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:checkbox wire:model.live="is_mirror" label="Mirrors Device" />
                    @if ($is_mirror)
                        <flux:select wire:model="mirror_device_id" label="Select Device to Mirror">
                            <flux:select.option value="">Select a device</flux:select.option>
                            @foreach (auth()->user()->devices->where('mirror_device_id', null)->where('id', '!=', $device->id) as $mirrorOption)
                                <flux:select.option value="{{ $mirrorOption->id }}">
                                    {{ $mirrorOption->name }} ({{ $mirrorOption->friendly_id }})
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:select
                        label="Scale"
                        wire:model="scale_level"
                        description="Size of text and spacing in screens. Automatic picks one from the screen width."
                    >
                        <flux:select.option value="">
                            Automatic ({{ ScaleLevel::tryFrom($device->deviceModel?->scale_level ?? '')?->label() ?? 'Regular' }})
                        </flux:select.option>
                        @foreach (ScaleLevel::cases() as $scaleLevel)
                            <flux:select.option value="{{ $scaleLevel->value }}">
                                {{ $scaleLevel->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:checkbox
                        wire:model="maximum_compatibility"
                        label="Maximum Compatibility"
                        description="Resolves display issues caused by certain e-ink driver chips. Disables fast refresh. TRMNL Firmware 1.6.0+ required."
                    />

                    @if (empty($device_model_id))
                        <flux:separator class="my-4" text="Advanced Device Settings" />
                        <div class="flex gap-4">
                            <flux:input label="Width (px)" wire:model="width" type="number" />
                            <flux:input label="Height (px)" wire:model="height" type="number" />
                            <flux:input label="Rotate °" wire:model="rotate" type="number" />
                        </div>
                        <flux:select label="Image Format" wire:model="image_format">
                            @foreach (\App\Enums\ImageFormat::cases() as $format)
                                <flux:select.option value="{{ $format->value }}">
                                    {{ $format->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:separator class="my-4" text="Special Functions" />
                    <flux:select label="Special Function" wire:model="special_function">
                        <flux:select.option value="sleep">Sleep</flux:select.option>
                        <flux:select.option value="add_wifi">Add WiFi</flux:select.option>
                        <flux:select.option value="none">None</flux:select.option>
                    </flux:select>

                    <div class="mb-4 flex items-center gap-4">
                        <flux:switch wire:model.live="sleep_mode_enabled" />
                        <div>
                            <div class="font-semibold">Sleep Mode</div>
                            <div class="text-sm text-zinc-500">Enabling Sleep Mode extends battery life</div>
                        </div>
                    </div>
                    @if ($sleep_mode_enabled)
                        <div class="mb-4 flex gap-4">
                            <flux:input type="time" label="From" wire:model.fill="sleep_mode_from" />
                            <flux:input type="time" label="To" wire:model.fill="sleep_mode_to" />
                        </div>
                    @endif

                    <div class="flex">
                        <flux:spacer />

                        <flux:button type="submit" wire:click="updateDevice" variant="primary"
                            >Save changes
                        </flux:button>
                    </div>
                </div>
            </flux:modal>

            <flux:modal name="update-firmware" class="md:w-96">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Update Firmware</flux:heading>
                        <flux:subheading>Select a firmware version to update to</flux:subheading>
                    </div>

                    <form wire:submit="updateFirmware">
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
                            <flux:callout.heading>TRMNL devices only</flux:callout.heading>
                            <flux:callout.text>
                                OTA firmware updates are currently available only for TRMNL devices. Applying firmware
                                to other device models may break your device.</flux:callout.text>
                        </flux:callout>

                        <flux:button
                            variant="subtle"
                            icon="arrow-path"
                            wire:click="checkFirmwareUpdates"
                            class="mb-4 w-full"
                        >
                            Check for new firmware versions
                        </flux:button>

                        <div class="mb-4">
                            <flux:select label="Device Model" wire:model.live="selected_firmware_model" required>
                                @foreach (FirmwareModel::cases() as $firmwareModel)
                                    <flux:select.option value="{{ $firmwareModel->value }}">
                                        {{ $firmwareModel->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        <div class="mb-4">
                            <flux:select label="Firmware Version" wire:model="selected_firmware_id" required>
                                @foreach ($firmwares->where('model', $selected_firmware_model) as $firmware)
                                    <flux:select.option value="{{ $firmware->id }}">
                                        {{ $firmware->version_tag }} {{ $firmware->latest ? '(Latest)' : '' }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        <div class="mb-4">
                            <flux:checkbox wire:model="download_firmware" label="Cache Firmware on BYOS">
                            </flux:checkbox>
                            <flux:text class="mt-2 text-xs">Check if the Device has no internet connection.</flux:text>
                        </div>

                        <div class="flex">
                            <flux:spacer />
                            <flux:button type="submit" variant="primary">Update Firmware</flux:button>
                        </div>
                    </form>
                </div>
            </flux:modal>

            <flux:modal name="delete-device" class="min-w-[22rem] space-y-6">
                <div>
                    <flux:heading size="lg">Delete {{ $device->name }}?</flux:heading>
                </div>

                <div class="flex gap-2">
                    <flux:spacer />

                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="deleteDevice({{ $device->id }})" variant="danger"
                        >Delete device
                    </flux:button>
                </div>
            </flux:modal>

            <flux:modal name="mirror-url" class="md:w-96">
                @php
                    $mirrorUrl = url('/mirror/index.html').'?api_key='.urlencode($device->api_key);
                @endphp

                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Mirror WebUI</flux:heading>
                        <flux:subheading
                            >Mirror this device onto older devices with a web browser — Safari is supported back to iOS
                            9.</flux:subheading>
                    </div>

                    <flux:input label="Mirror URL" value="{{ $mirrorUrl }}" readonly copyable />
                </div>
            </flux:modal>

            <flux:card.body class="space-y-6">
                @if (! $device->mirror_device_id)
                    @if ($current_image_url)
                        <div>
                            <flux:heading size="lg" class="mb-4">Screen</flux:heading>
                            <div class="flex justify-center overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                                <div class="relative origin-center rotate-[{{ $device->preview_rotation }}deg]">
                                    <img
                                        src="{{ $current_image_url }}"
                                        class="max-h-[min(480px,70vh)] max-w-full object-contain"
                                        alt="Current screen"
                                    />
                                </div>
                            </div>
                        </div>
                    @endif

                    <flux:separator class="my-6" text="Playlists" />

                    <div>
                        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <flux:heading size="lg">Device Playlists</flux:heading>
                            <flux:modal.trigger name="create-playlist">
                                <flux:button icon="plus" variant="primary" class="w-full sm:w-auto">
                                    Create Playlist
                                </flux:button>
                            </flux:modal.trigger>
                        </div>
                    </div>
                @else
                    <flux:callout variant="info">
                        <div class="flex items-center gap-2">
                            <flux:icon.link />
                            <flux:text>
                                This device is mirrored from
                                <a
                                    href="{{ route('devices.configure', $device->mirrorDevice) }}"
                                    wire:navigate
                                    class="font-medium hover:underline"
                                >
                                    {{ $device->mirrorDevice->name }}
                                </a>
                            </flux:text>
                        </div>
                    </flux:callout>
                @endif

                <flux:modal name="create-playlist" class="md:w-96">
                    <div class="space-y-6">
                        <div>
                            <flux:heading size="lg">Create Playlist</flux:heading>
                        </div>

                        <form wire:submit="createPlaylist">
                            <div class="mb-4">
                                <flux:input label="Playlist Name" wire:model="playlist_name" required />
                            </div>

                            <div class="mb-4">
                                <flux:checkbox.group wire:model="selected_weekdays" label="Active Days (optional)">
                                    <flux:checkbox label="Monday" value="1" />
                                    <flux:checkbox label="Tuesday" value="2" />
                                    <flux:checkbox label="Wednesday" value="3" />
                                    <flux:checkbox label="Thursday" value="4" />
                                    <flux:checkbox label="Friday" value="5" />
                                    <flux:checkbox label="Saturday" value="6" />
                                    <flux:checkbox label="Sunday" value="0" />
                                </flux:checkbox.group>
                            </div>

                            <div class="mb-4">
                                <flux:input type="time" label="Active From (optional)" wire:model="active_from" />
                            </div>

                            <div class="mb-4">
                                <flux:input type="time" label="Active Until (optional)" wire:model="active_until" />
                            </div>

                            <div class="mb-4">
                                <flux:input
                                    type="number"
                                    label="Refresh Time (seconds)"
                                    wire:model="refresh_time"
                                    min="1"
                                    placeholder="Leave empty to use device default"
                                />
                            </div>

                            <div class="flex">
                                <flux:spacer />
                                <flux:button type="submit" variant="primary">Create Playlist</flux:button>
                            </div>
                        </form>
                    </div>
                </flux:modal>

                @foreach ($playlists as $playlist)
                    <flux:card body="divided" wire:key="playlist-{{ $playlist->id }}" class="mb-4 last:mb-0">
                        <flux:card.header class="items-start sm:items-center">
                            <flux:card.heading>
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                    <flux:switch
                                        wire:model.live="playlist.is_active"
                                        wire:click="togglePlaylistActive({{ $playlist->id }})"
                                        :checked="$playlist->is_active"
                                    />
                                    <span>{{ $playlist->name }}</span>
                                </div>
                            </flux:card.heading>

                            @if ($playlist->weekdays || ($playlist->active_from && $playlist->active_until))
                                <flux:card.subheading>
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        @if ($playlist->weekdays)
                                            <span>{{ implode(', ', collect($playlist->weekdays)->map(fn ($day) => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][$day])->toArray()) }}</span>
                                        @endif
                                        @if ($playlist->active_from && $playlist->active_until)
                                            @if ($playlist->weekdays)
                                                <span aria-hidden="true">·</span>
                                            @endif
                                            <span>{{ $playlist->active_from->format('H:i') }} - {{ $playlist->active_until->format('H:i') }}</span>
                                        @endif
                                    </span>
                                </flux:card.subheading>
                            @endif

                            <flux:card.actions>
                                <div class="flex gap-1">
                                    <flux:modal.trigger name="edit-playlist-{{ $playlist->id }}">
                                        <flux:tooltip content="Edit playlist settings" position="bottom">
                                            <flux:button
                                                icon="pencil-square"
                                                variant="subtle"
                                                size="sm"
                                                wire:click="preparePlaylistEdit({{ $playlist->id }})"
                                                aria-label="Edit playlist"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                    <flux:modal.trigger name="delete-playlist-{{ $playlist->id }}">
                                        <flux:button icon="trash" size="sm" aria-label="Delete playlist" />
                                    </flux:modal.trigger>
                                </div>
                            </flux:card.actions>
                        </flux:card.header>

                        <flux:modal name="edit-playlist-{{ $playlist->id }}" class="md:w-96">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">Edit Playlist</flux:heading>
                                </div>

                                <form wire:submit="editPlaylist({{ $playlist->id }})">
                                    <div class="mb-4">
                                        <flux:input label="Playlist Name" wire:model="playlist_name" required />
                                    </div>

                                    <div class="mb-4">
                                        <flux:checkbox.group
                                            wire:model="selected_weekdays"
                                            label="Active Days (optional)"
                                        >
                                            <flux:checkbox label="Monday" value="1" />
                                            <flux:checkbox label="Tuesday" value="2" />
                                            <flux:checkbox label="Wednesday" value="3" />
                                            <flux:checkbox label="Thursday" value="4" />
                                            <flux:checkbox label="Friday" value="5" />
                                            <flux:checkbox label="Saturday" value="6" />
                                            <flux:checkbox label="Sunday" value="0" />
                                        </flux:checkbox.group>
                                    </div>

                                    <div class="mb-4">
                                        <flux:input
                                            type="time"
                                            label="Active From (optional)"
                                            wire:model="active_from"
                                        />
                                    </div>

                                    <div class="mb-4">
                                        <flux:input
                                            type="time"
                                            label="Active Until (optional)"
                                            wire:model="active_until"
                                        />
                                    </div>

                                    <div class="mb-4">
                                        <flux:input
                                            type="number"
                                            label="Refresh Time (seconds)"
                                            wire:model="refresh_time"
                                            min="1"
                                            placeholder="Leave empty to use device default"
                                        />
                                    </div>

                                    <div class="flex">
                                        <flux:spacer />
                                        <flux:button type="submit" variant="primary">Save Changes</flux:button>
                                    </div>
                                </form>
                            </div>
                        </flux:modal>

                        <flux:modal name="delete-playlist-{{ $playlist->id }}" class="min-w-[22rem] space-y-6">
                            <div>
                                <flux:heading size="lg">Delete {{ $playlist->name }}?</flux:heading>
                                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                    This will permanently delete this playlist and all its items.
                                </p>
                            </div>

                            <div class="flex gap-2">
                                <flux:spacer />
                                <flux:modal.close>
                                    <flux:button variant="ghost">Cancel</flux:button>
                                </flux:modal.close>
                                <flux:button wire:click="deletePlaylist({{ $playlist->id }})" variant="danger"
                                    >Delete playlist</flux:button>
                            </div>
                        </flux:modal>

                        <flux:card.body>
                            @if ($playlist->items->isEmpty())
                                <x-playlist-empty-callout />
                            @else
                                <table class="w-full" data-flux-table>
                                    <thead data-flux-columns>
                                        <tr>
                                            <th
                                                class="w-10 px-2 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 dark:text-white"
                                                data-flux-column
                                            >
                                                <span class="sr-only">Reorder</span>
                                            </th>
                                            <th
                                                class="px-3 py-3 text-left text-sm font-medium text-zinc-800 last:pr-0 dark:text-white"
                                                data-flux-column
                                            >
                                                <div class="flex whitespace-nowrap">Plugin</div>
                                            </th>
                                            <th
                                                class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                                                data-flux-column
                                            >
                                                <div class="flex whitespace-nowrap">Status</div>
                                            </th>
                                            <th
                                                class="px-3 py-3 text-right text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                                                data-flux-column
                                            >
                                                <div class="flex justify-end whitespace-nowrap">Actions</div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-zinc-800/10 dark:divide-white/20"
                                        data-flux-rows
                                        @if ($playlist->items->count() > 1) wire:sort="sortPlaylistItem" @endif
                                    >
                                        @foreach ($playlist->items->sortBy('order') as $item)
                                            <tr
                                                data-flux-row
                                                wire:key="playlist-item-{{ $item->id }}"
                                                @if ($playlist->items->count() > 1) wire:sort:item="{{ $item->id }}" @endif
                                            >
                                                <td class="w-10 px-2 py-3 align-middle text-zinc-400 first:pl-0 dark:text-zinc-500">
                                                    @if ($playlist->items->count() > 1)
                                                        <div
                                                            wire:sort:handle
                                                            class="flex cursor-grab touch-none justify-center active:cursor-grabbing"
                                                            title="Drag to reorder"
                                                        >
                                                            <flux:icon name="bars-3" variant="mini" class="size-5" />
                                                        </div>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 last:pr-0 dark:text-zinc-300">
                                                    <x-playlist-plugin-name :item="$item" />
                                                </td>
                                                <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                                    <flux:switch
                                                        wire:click="togglePlaylistItemActive({{ $item->id }})"
                                                        :checked="$item->is_active"
                                                    />
                                                </td>
                                                <td class="px-3 py-3 text-sm whitespace-nowrap first:pl-0 last:pr-0">
                                                    <div class="flex items-center justify-end gap-2">
                                                        @if (! $item->isMashup() && $item->plugin?->plugin_type === 'recipe')
                                                            <flux:dropdown>
                                                                <flux:button
                                                                    icon="ellipsis-horizontal"
                                                                    variant="ghost"
                                                                    size="xs"
                                                                />
                                                                <flux:menu>
                                                                    <flux:menu.item
                                                                        icon="x-mark"
                                                                        wire:click="clearPluginImageCache({{ $item->id }})"
                                                                    >
                                                                        Clear image cache</flux:menu.item>
                                                                </flux:menu>
                                                            </flux:dropdown>
                                                        @endif
                                                        <flux:modal.trigger name="delete-playlist-item-{{ $item->id }}">
                                                            <flux:button icon="trash" variant="ghost" size="sm" />
                                                        </flux:modal.trigger>
                                                    </div>

                                                    <flux:modal
                                                        name="delete-playlist-item-{{ $item->id }}"
                                                        class="min-w-[22rem] space-y-6"
                                                    >
                                                        <div>
                                                            <flux:heading size="lg">Delete {{ $item->plugin?->name ?? 'missing item' }}?</flux:heading>
                                                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                                                This will remove this item from the playlist.
                                                            </p>
                                                        </div>

                                                        <div class="flex gap-2">
                                                            <flux:spacer />
                                                            <flux:modal.close>
                                                                <flux:button variant="ghost">Cancel</flux:button>
                                                            </flux:modal.close>
                                                            <flux:button
                                                                wire:click="deletePlaylistItem({{ $item->id }})"
                                                                variant="danger"
                                                            >Delete item</flux:button>
                                                        </div>
                                                    </flux:modal>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </flux:card.body>
                    </flux:card>
                @endforeach
            </flux:card.body>
        </flux:card>
    </div>
</div>
