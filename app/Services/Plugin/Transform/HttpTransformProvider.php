<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class HttpTransformProvider implements TransformProvider
{
    public function languages(): array
    {
        return ['php', 'python', 'node'];
    }

    public function execute(string $language, string $code, array $input): TransformResult
    {
        if (! in_array($language, $this->languages(), true)) {
            throw new InvalidArgumentException('Unsupported transform language');
        }

        $started = hrtime(true);
        $output = null;
        $error = null;
        $url = config('services.transform.runner_url');
        if (! is_string($url) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $error = 'Transform runner URL is not configured';
        } else {
            try {
                $timeout = max(1, (int) config('services.transform.timeout_seconds', 5));
                $request = Http::acceptJson()->timeout($timeout)->withoutRedirecting()
                    ->withOptions(['stream' => true, 'read_timeout' => $timeout]);
                $token = config('services.transform.runner_token');
                if (is_string($token) && $token !== '') {
                    $request = $request->withToken($token);
                }
                $response = $request->post(mb_rtrim($url, '/').'/run', [
                    'language' => $language, 'code' => $code, 'input' => $input, 'timeout' => $timeout,
                ]);
                if (! $response->successful()) {
                    $error = 'Transform runner returned HTTP '.$response->status();
                } else {
                    $limit = (int) config('services.transform.max_output_bytes', 1048576);
                    $stream = $response->toPsrResponse()->getBody();
                    $body = '';
                    try {
                        while (! $stream->eof() && mb_strlen($body) <= $limit) {
                            $body .= $stream->read(min(8192, $limit + 1 - mb_strlen($body)));
                        }
                    } finally {
                        $stream->close();
                    }
                    if (mb_strlen($body) > $limit) {
                        $error = 'Transform runner output exceeds limit';
                    } else {
                        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                        if (! is_array($decoded) || ! is_array($decoded['output'] ?? null)) {
                            $error = 'Transform runner must return an object or array in output';
                        } else {
                            $output = $decoded['output'];
                        }
                    }
                }
            } catch (Throwable) {
                // Transport exception strings and runner errors may contain URLs,
                // credentials or user data. Only a safe summary leaves this provider.
                $error = 'Transform runner request failed';
            }
        }

        return new TransformResult(
            output: $output, stdout: '', stderr: '', exitCode: $error === null ? 0 : -1,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000), error: $error,
        );
    }
}
