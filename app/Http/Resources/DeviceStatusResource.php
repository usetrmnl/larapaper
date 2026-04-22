<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Device
 */
class DeviceStatusResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mac_address' => $this->mac_address,
            'name' => $this->name,
            'friendly_id' => $this->friendly_id,
            'last_rssi_level' => $this->last_rssi_level,
            'last_battery_voltage' => $this->last_battery_voltage,
            'last_firmware_version' => $this->last_firmware_version,
            'battery_percent' => $this->battery_percent,
            'wifi_strength' => $this->wifi_strength,
            'current_screen_image' => $this->current_screen_image,
            'default_refresh_interval' => $this->default_refresh_interval,
            'sleep_mode_enabled' => $this->sleep_mode_enabled,
            'sleep_mode_from' => $this->sleep_mode_from,
            'sleep_mode_to' => $this->sleep_mode_to,
            'special_function' => $this->special_function,
            'pause_until' => $this->pause_until,
            'updated_at' => $this->updated_at,
        ];
    }
}
