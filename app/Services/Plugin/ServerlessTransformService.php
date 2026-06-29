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
                $msg = 'runner returned '.$response->status().': '.$response->json('error', $response->body());
                if ($strict) {
                    throw new \RuntimeException($msg);
                }
                Log::warning('ServerlessTransformService: '.$msg);

                return ['error' => $msg];
            }

            $output = $response->json('output');
            if (is_array($output)) {
                return $output;
            }

            // Runner returned an error body (e.g. {"error": "..."}) — store it as the payload.
            $runnerBody = $response->json();
            if (is_array($runnerBody)) {
                if ($strict) {
                    throw new \RuntimeException($runnerBody['error'] ?? 'transform runner returned no output');
                }

                return $runnerBody;
            }

            return $this->fail('ServerlessTransformService: runner returned no output', $input, $strict);
        } catch (\Throwable $e) {
            if ($strict) {
                throw new \RuntimeException('ServerlessTransformService: '.$e->getMessage(), previous: $e);
            }
            Log::warning('ServerlessTransformService: '.$e->getMessage());

            return ['error' => $e->getMessage()];
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
