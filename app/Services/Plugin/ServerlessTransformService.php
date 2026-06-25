<?php

namespace App\Services\Plugin;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ServerlessTransformService
{
    public function run(string $code, string $language, array $input, bool $strict = false): array
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
                return $this->fail(
                    'ServerlessTransformService: runner returned '.$response->status().': '.$response->json('error', ''),
                    $input,
                    $strict
                );
            }

            $output = $response->json('output');
            if (! is_array($output)) {
                return $this->fail('ServerlessTransformService: runner output is not a JSON object', $input, $strict);
            }

            return $output;
        } catch (\Throwable $e) {
            return $this->fail('ServerlessTransformService: '.$e->getMessage(), $input, $strict);
        }
    }

    private function fail(string $message, array $input, bool $strict): array
    {
        if ($strict) {
            throw new \RuntimeException($message);
        }
        Log::warning($message);

        return $input;
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.transform_runner.url');
    }
}
