<?php

namespace App\Actions\Api;

use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResolveDeviceByMacAddress
{
    /**
     * Resolve or auto-provision a device by its MAC address header (`id`).
     *
     * Used by the firmware /setup flow, which hands the device its api_key. A
     * MAC address is not a secret, so an existing device is only returned while
     * it has never been activated (no /setup or /display request yet), or when
     * its owner has Auto-Join enabled (used to re-pair a device after a factory
     * reset). Unknown MAC addresses are only
     * auto-provisioned when a user has Auto-Join enabled. Returns null
     * otherwise.
     */
    public function handle(Request $request): ?Device
    {
        $macAddress = $request->header('id');

        if (! $macAddress) {
            return null;
        }

        $device = Device::where('mac_address', mb_strtoupper($macAddress))->first();

        if ($device) {
            return $device->last_refreshed_at === null || $device->user?->assign_new_devices
                ? $device
                : null;
        }

        $autoAssignUser = User::where('assign_new_devices', true)->first();

        if (! $autoAssignUser) {
            return null;
        }

        $modelName = $request->header('model-id');
        $deviceModel = $modelName
            ? DeviceModel::where('name', $modelName)->first()
            : null;

        return Device::create([
            'mac_address' => mb_strtoupper($macAddress),
            'api_key' => Str::random(22),
            'user_id' => $autoAssignUser->id,
            'name' => "{$autoAssignUser->name}'s TRMNL",
            'friendly_id' => Str::random(6),
            'default_refresh_interval' => 900,
            'mirror_device_id' => $autoAssignUser->assign_new_device_id,
            'device_model_id' => $deviceModel?->id,
        ]);
    }
}
