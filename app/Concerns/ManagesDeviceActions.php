<?php

namespace App\Concerns;

use App\Enums\FirmwareModel;
use App\Jobs\FirmwareDownloadJob;
use App\Jobs\FirmwarePollJob;
use App\Models\Device;
use App\Models\Firmware;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

trait ManagesDeviceActions
{
    public ?int $pause_duration = null;

    public ?string $pause_until_date = null;

    public ?string $pause_until_time = null;

    public ?int $pause_plugin_id = null;

    public bool $simulate_physical_device = false;

    /** @var Collection<int, Firmware> */
    public $firmwares;

    public $selected_firmware_model;

    public $selected_firmware_id;

    public $download_firmware = false;

    public function initializeManagesDeviceActions(): void
    {
        $this->firmwares ??= new Collection;
    }

    #[Computed]
    public function timezone(): string
    {
        return auth()->user()->preferredTimezone();
    }

    public function updatedPauseDuration(?int $value): void
    {
        if ($value !== null) {
            $this->pause_until_date = $this->pause_until_time = null;
        }
    }

    public function updatedPauseUntilDate(?string $value): void
    {
        if ($value) {
            $this->pause_duration = null;
        }
    }

    public function updatedPauseUntilTime(?string $value): void
    {
        if ($value) {
            $this->pause_duration = null;
        }
    }

    public function updatedSelectedFirmwareModel(): void
    {
        $this->selectLatestFirmwareForModel();
    }

    public function pauseDevice(int $deviceId): void
    {
        $device = $this->findOwnedDevice($deviceId);
        $this->validate([
            'pause_plugin_id' => ['nullable', Rule::exists('plugins', 'id')->where('user_id', auth()->id())],
        ]);

        $device->update([
            'pause_until' => $this->resolvePauseUntil(),
            'pause_plugin_id' => $this->pause_plugin_id ?: null,
        ]);
        $this->reset('pause_duration', 'pause_until_date', 'pause_until_time', 'pause_plugin_id');
        Flux::modal('pause-device-'.$deviceId)->close();
        $this->refreshDisplayedDevice($device);

        $pauseUntil = $device->pause_until->timezone($this->timezone);
        Flux::toast(variant: 'success', text: "Device paused until {$pauseUntil} {$this->timezone}");
    }

    public function unpauseDevice(int $deviceId): void
    {
        $device = $this->findOwnedDevice($deviceId);
        $device->update(['pause_until' => null, 'pause_plugin_id' => null]);
        Flux::modal('unpause-device-'.$deviceId)->close();
        $this->refreshDisplayedDevice($device);
        Flux::toast(variant: 'success', text: 'Pause cleared. Wake your device manually to resume.');
    }

    public function prepareFirmwareUpdate(int $deviceId): void
    {
        $device = $this->findOwnedDevice($deviceId);

        $this->selected_firmware_model = FirmwareModel::forDevice($device)->value;

        if ($this->firmwares === null || $this->firmwares->isEmpty()) {
            $this->refreshFirmwareList();

            return;
        }

        $this->selectLatestFirmwareForModel();
    }

    public function checkFirmwareUpdates(?int $deviceId = null): void
    {
        $device = $deviceId === null
            ? $this->device
            : $this->findOwnedDevice($deviceId);

        abort_unless(auth()->user()->devices->contains($device), 403);

        FirmwarePollJob::dispatchSync();

        $this->refreshFirmwareList();
    }

    public function updateFirmware(int $deviceId): void
    {
        $device = $this->findOwnedDevice($deviceId);

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

        $device->update([
            'update_firmware_id' => $this->selected_firmware_id,
        ]);

        $this->refreshDisplayedDevice($device);

        Flux::modal('update-firmware-'.$deviceId)->close();
    }

    private function findOwnedDevice(int $deviceId): Device
    {
        return auth()->user()->devices()->with('deviceModel')->findOrFail($deviceId);
    }

    private function refreshDisplayedDevice(Device $device): void
    {
        if (isset($this->devices)) {
            $this->devices = auth()->user()->devices()->with('deviceModel')->get();
        }

        if (isset($this->device) && (int) $this->device->id === (int) $device->id) {
            $this->device->refresh();
        }
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
