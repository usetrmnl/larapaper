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
     * Used by the firmware /setup flow. Returns null if the device cannot be
     * resolved and no auto-assign user is configured.
     */
    public function handle(Request $request): ?Device
    {
        $macAddress = $request->header('id');

        if (! $macAddress) {
            return null;
        }

        $device = Device::where('mac_address', mb_strtoupper($macAddress))->first();

        if ($device) {
            return $device;
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
