<?php

namespace App\Actions\Api;

use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResolveDeviceByApiKey
{
    /**
     * Resolve the device for a firmware request by its access-token header.
     *
     * When `$autoAssign` is true and no matching device exists, a new device
     * will be created and assigned to the first user whose Auto-Join window
     * is open (if any). Returns null if the device cannot be resolved or
     * auto-created.
     */
    public function handle(Request $request, bool $autoAssign = false): ?Device
    {
        $accessToken = $request->header('access-token');
        $macAddress = $request->header('id');

        $device = Device::where('api_key', $accessToken)->first();

        if ($device) {
            return $device;
        }

        if (! $autoAssign || ! $macAddress) {
            return null;
        }

        $autoAssignUser = User::withActiveAutoJoin()->first();

        if (! $autoAssignUser) {
            return null;
        }

        return Device::create([
            'mac_address' => mb_strtoupper($macAddress),
            'api_key' => $accessToken ?? Str::random(22),
            'user_id' => $autoAssignUser->id,
            'name' => "{$autoAssignUser->name}'s TRMNL",
            'friendly_id' => Str::random(6),
            'default_refresh_interval' => 900,
            'mirror_device_id' => $autoAssignUser->assign_new_device_id,
        ]);
    }
}
