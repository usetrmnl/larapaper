<?php

namespace App\Services\Plugin;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ServerlessTransformService
{
    public function run(string $code, string $language, array $input): array
    {
        $url = config('services.transform_runner.url');
        if (! $url) {
            return $input;
        }

        $timeout = config('services.transform_runner.timeout', 30);

        try {
            $response = Http::timeout($timeout)->post("{$url}/run", [
                'language' => $language,
                'code'     => $code,
                'input'    => $input,
                'timeout'  => $timeout,
            ]);

            if (! $response->successful()) {
                Log::warning('ServerlessTransformService: runner returned '.$response->status().': '.$response->json('error', ''));

                return $input;
            }

            $output = $response->json('output');
            if (! is_array($output)) {
                Log::warning('ServerlessTransformService: runner output is not a JSON object');

                return $input;
            }

            return $output;
        } catch (\Throwable $e) {
            Log::warning('ServerlessTransformService: '.$e->getMessage());

            return $input;
        }
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.transform_runner.url');
    }
}
