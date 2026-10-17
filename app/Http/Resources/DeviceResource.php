<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A device in the shape of TRMNL's Account API (GET /api/devices/{id}).
 *
 * @mixin \App\Models\Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lastPingAt = $this->last_refreshed_at?->toIso8601ZuluString();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'friendly_id' => $this->friendly_id,
            'mac_address' => $this->mac_address,
            'battery_voltage' => $this->last_battery_voltage,
            'rssi' => $this->last_rssi_level,
            'last_ping_at' => $lastPingAt,
            'percent_charged' => $this->battery_percent,
            'wifi_strength' => $this->wifi_strength,
            'hardware_last_ping_at' => $lastPingAt,
            'sleep_mode_enabled' => $this->sleep_mode_enabled,
            'sleep_start_time' => $this->sleepStartMinutes(),
            'sleep_end_time' => $this->sleepEndMinutes(),
            'sleep_until' => $this->pause_until?->toIso8601ZuluString(),
            'firmware_version' => $this->last_firmware_version,
            'refresh_interval' => $this->default_refresh_interval,
        ];
    }
}
