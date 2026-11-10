<?php

namespace App\Jobs;

use App\Models\Device;
use App\Services\ProxyCloudService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FetchProxyCloudResponses implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ProxyCloudService $proxyCloud): void
    {
        Device::where('proxy_cloud', true)->each(function (Device $device) use ($proxyCloud): void {
            if ($device->getNextPlaylistItem()) {
                Log::info("Skipping device: {$device->mac_address} as it has a pending playlist item.");

                return;
            }

            try {
                $response = $proxyCloud->fetchDisplay($device);

                if ($proxyCloud->deviceNotFound($response)) {
                    throw new Exception($proxyCloud->errorMessage($response) ?? 'Device not found');
                }

                if (! $proxyCloud->accepted($response)) {
                    $error = $proxyCloud->errorMessage($response);

                    if ($error !== null) {
                        throw new Exception($error);
                    }
                }

                $device->update([
                    'proxy_cloud_response' => $response->json(),
                ]);

                $this->processImage($device, $response);
                $this->uploadLogRequest($device, $proxyCloud);

                Log::info("Successfully updated proxy cloud response for device: {$device->mac_address}");
            } catch (Exception $e) {
                Log::error("Failed to fetch proxy cloud response for device: {$device->mac_address}", [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function processImage(Device $device, Response $response): void
    {
        $imageUrl = $response->json('image_url');
        $filename = $response->json('filename');

        if ($imageUrl === null) {
            return;
        }

        $imageExtension = $this->determineImageExtension($imageUrl);
        Log::info("Response data: $imageUrl. Image Extension: $imageExtension");

        try {
            $imageContents = Http::get($imageUrl)->body();
            $filePath = "images/generated/{$filename}.{$imageExtension}";

            if (! Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->put($filePath, $imageContents);
            }

            $device->update([
                'current_screen_image' => $filename,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to download and save image for device: {$device->mac_address}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function determineImageExtension(?string $imageUrl): string
    {
        if ($imageUrl === null) {
            return 'bmp';
        }

        if (Str::contains($imageUrl, '.png')) {
            return 'png';
        }

        $parsedUrl = parse_url($imageUrl);
        if ($parsedUrl === false || ! isset($parsedUrl['query'])) {
            return 'bmp';
        }

        parse_str($parsedUrl['query'], $queryParams);
        $imageType = urldecode($queryParams['response-content-type'] ?? 'image/bmp');

        return $imageType === 'image/png' ? 'png' : 'bmp';
    }

    private function uploadLogRequest(Device $device, ProxyCloudService $proxyCloud): void
    {
        if (! $device->last_log_request) {
            return;
        }

        try {
            Http::withHeaders($proxyCloud->headers($device))
                ->post(config('services.trmnl.proxy_base_url').'/api/log', $device->last_log_request);

            $device->update([
                'last_log_request' => null,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to upload device log for device: {$device->mac_address}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
