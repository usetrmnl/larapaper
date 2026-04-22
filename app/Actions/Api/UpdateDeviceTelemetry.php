<?php

namespace App\Actions\Api;

use App\Models\Device;
use App\Services\DeviceSensorService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UpdateDeviceTelemetry
{
    public function __construct(private DeviceSensorService $sensorService) {}

    /**
     * Apply telemetry headers (rssi, battery, firmware, sensors) from a
     * display request onto the device record.
     */
    public function handle(Request $request, Device $device): void
    {
        $batteryPercent = $request->header('battery-percent') ?? $request->header('percent-charged');
        $lastBatteryVoltage = $batteryPercent !== null
            ? $device->calculateVoltageFromPercent((int) $batteryPercent)
            : $request->header('battery_voltage');

        $device->update([
            'last_rssi_level' => $request->header('rssi'),
            'last_battery_voltage' => $lastBatteryVoltage,
            'last_firmware_version' => $request->header('fw-version'),
            'last_refreshed_at' => now(),
        ]);

        $sensorHeader = $request->server('HTTP_SENSORS') ?? $request->header('http_sensors');
        if ($sensorHeader) {
            try {
                $this->sensorService->ingestFromHeader($device, $sensorHeader);
            } catch (Exception $e) {
                Log::warning('Failed to ingest device sensor header', [
                    'device_id' => $device->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
