<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DeviceCatalogService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class FetchDeviceModelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(DeviceCatalogService $catalog): void
    {
        try {
            $this->processPalettes($catalog);

            $response = $catalog->fetch(DeviceCatalogService::MODELS_PATH);

            if (! $response->successful()) {
                Log::error('Failed to fetch device models from API', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return;
            }

            $data = $response->json('data', []);

            if (! is_array($data)) {
                Log::error('Invalid response format from device models API', [
                    'response' => $response->json(),
                ]);

                return;
            }

            $catalog->sync($data, []);

            Log::info('Successfully fetched and updated device models', [
                'count' => count($data),
            ]);
        } catch (Exception $exception) {
            Log::error('Exception occurred while fetching device models', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }
    }

    private function processPalettes(DeviceCatalogService $catalog): void
    {
        try {
            $response = $catalog->fetch(DeviceCatalogService::PALETTES_PATH);

            if (! $response->successful()) {
                Log::error('Failed to fetch palettes from API', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return;
            }

            $data = $response->json('data', []);

            if (! is_array($data)) {
                Log::error('Invalid response format from palettes API', [
                    'response' => $response->json(),
                ]);

                return;
            }

            $catalog->sync([], $data);

            Log::info('Successfully fetched and updated palettes', [
                'count' => count($data),
            ]);
        } catch (Exception $exception) {
            Log::error('Exception occurred while fetching palettes', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }
    }
}
