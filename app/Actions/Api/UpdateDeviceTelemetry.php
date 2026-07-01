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
        $charging = $request->header('battery-charging');
        $usb = $request->header('usb-connected');

        $device->fill([
            'last_rssi_level' => $request->header('rssi'),
            'last_battery_voltage' => $batteryPercent !== null
                ? $device->calculateVoltageFromPercent((int) $batteryPercent)
                : $request->header('battery_voltage'),
            'last_firmware_version' => $request->header('fw-version'),
            'last_refreshed_at' => now(),
            ...($charging !== null ? ['last_battery_charging' => $charging === '1'] : []),
            ...($usb !== null ? ['last_usb_connected' => filter_var($usb, FILTER_VALIDATE_BOOLEAN)] : []),
        ]);

        if ($device->isDirty()) {
            Log::debug('Device telemetry update', ['device_id' => $device->id, ...$device->getDirty()]);
            $device->save();
        }

        if ($sensorHeader = $request->server('HTTP_SENSORS') ?? $request->header('http_sensors')) {
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
