<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ProxyCloudService
{
    public function accessToken(Device $device): string
    {
        $override = $device->proxy_cloud_api_key;

        if (is_string($override) && $override !== '') {
            return $override;
        }

        return (string) $device->api_key;
    }

    /**
     * @return array<string, mixed>
     */
    public function headers(Device $device, ?string $accessToken = null): array
    {
        return [
            'id' => $device->mac_address,
            'access-token' => $accessToken ?? $this->accessToken($device),
            'width' => 800,
            'height' => 480,
            'rssi' => $device->last_rssi_level,
            'battery_voltage' => $device->last_battery_voltage,
            'refresh-rate' => $device->default_refresh_interval,
            'fw-version' => $device->last_firmware_version,
            'accept-encoding' => 'identity;q=1,chunked;q=0.1,*;q=0',
            'user-agent' => 'ESP32HTTPClient',
        ];
    }

    /**
     * @throws ConnectionException
     */
    public function fetchDisplay(Device $device, ?string $accessToken = null): Response
    {
        return Http::withHeaders($this->headers($device, $accessToken))
            ->get(config('services.trmnl.proxy_base_url').'/api/display');
    }

    public function deviceNotFound(Response $response): bool
    {
        return $response->status() === 200
            && $response->json('status') === 500
            && $response->json('error') === 'Device not found';
    }

    public function accepted(Response $response): bool
    {
        if ($this->deviceNotFound($response)) {
            return false;
        }

        return in_array($response->status(), [0, 200], true);
    }

    public function errorMessage(Response $response): ?string
    {
        $error = $response->json('error');

        if (! is_string($error) || $error === '') {
            return null;
        }

        return $error;
    }
}
