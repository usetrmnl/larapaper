<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Executes transforms on an external runner implementing `POST /run`
 * (e.g. bnussbau/byos-transform-runner, which isolates each run with bubblewrap).
 */
final class HttpTransformProvider implements TransformProvider
{
    /** Language keys LaraPaper can send to a runner. */
    public const LANGUAGES = ['php', 'node', 'python', 'ruby'];

    /** Maximum bytes of a runner error message surfaced to the recipe owner. */
    private const MAX_ERROR_BYTES = 1024;

    /** Seconds the languages reported by the runner's manifest are cached. */
    private const MANIFEST_CACHE_SECONDS = 300;

    /** Seconds a missing or invalid manifest is cached before the runner is asked again. */
    private const MANIFEST_FAILURE_CACHE_SECONDS = 30;

    /**
     * Languages the runner lists in `language_capabilities` of its required
     * `GET /manifest` endpoint. An unreachable runner or an invalid manifest
     * yields no languages, so no transform can run.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        $cached = Cache::get('transform:languages');
        if (is_array($cached)) {
            return $cached;
        }

        $languages = $this->manifestLanguages();
        Cache::put('transform:languages', $languages ?? [], $languages === null ? self::MANIFEST_FAILURE_CACHE_SECONDS : self::MANIFEST_CACHE_SECONDS);

        return $languages ?? [];
    }

    /**
     * @return list<string>|null
     */
    private function manifestLanguages(): ?array
    {
        $url = $this->runnerUrl();
        if ($url === null) {
            return null;
        }

        try {
            $response = $this->request()->connectTimeout(2)->timeout(3)->get($url.'/manifest');
        } catch (Throwable) {
            return null;
        }

        $reported = $response->successful() ? $response->json('language_capabilities') : null;
        if (! is_array($reported) || ! array_is_list($reported)) {
            return null;
        }

        return array_values(array_intersect(self::LANGUAGES, $reported));
    }

    /**
     * @param  array<int|string, mixed>  $input
     */
    public function execute(string $language, string $code, array $input): TransformResult
    {
        if (! in_array($language, $this->languages(), true)) {
            throw new InvalidArgumentException('Unsupported transform language');
        }

        $startedAt = hrtime(true);

        $url = $this->runnerUrl();
        if ($url === null) {
            return $this->failed('Transform runner URL is not configured', $startedAt);
        }

        $timeout = max(1, (int) config('services.transform.timeout_seconds', 5));
        $limit = max(1, (int) config('services.transform.max_output_bytes', 1048576));

        try {
            $response = $this->request()
                ->connectTimeout(3)
                ->timeout($timeout + 2)
                ->withOptions(['stream' => true, 'read_timeout' => $timeout + 2])
                ->post($url.'/run', [
                    'language' => $language,
                    'code' => $code,
                    'input' => $input,
                    'timeout' => $timeout,
                ]);

            $body = $this->readBounded($response, $limit);
        } catch (Throwable) {
            // Transport exceptions may contain URLs or credentials: only a safe summary leaves this provider.
            return $this->failed('Transform runner request failed', $startedAt);
        }

        if ($body === null) {
            return $this->failed('Transform runner output exceeds limit', $startedAt);
        }

        $decoded = json_decode($body, true);

        if (! $response->successful()) {
            $runnerError = is_array($decoded) && is_string($decoded['error'] ?? null)
                ? mb_strcut($decoded['error'], 0, self::MAX_ERROR_BYTES)
                : '';

            return $this->failed('Transform runner returned HTTP '.$response->status(), $startedAt, $runnerError);
        }

        if (! is_array($decoded) || ! is_array($decoded['output'] ?? null)) {
            return $this->failed('Transform runner must return an object or array in output', $startedAt);
        }

        return new TransformResult(
            output: $decoded['output'],
            stdout: '',
            stderr: '',
            exitCode: 0,
            durationMs: $this->elapsedMs($startedAt),
        );
    }

    /**
     * Base URL of the runner without a trailing slash, or null when it is not an http(s) URL.
     */
    private function runnerUrl(): ?string
    {
        $url = config('services.transform.runner_url');

        if (! is_string($url) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        return mb_rtrim($url, '/');
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()->withoutRedirecting();

        $token = config('services.transform.runner_token');
        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    /**
     * Read the response body without buffering more than the configured limit.
     * Returns null when the body exceeds the limit.
     */
    private function readBounded(Response $response, int $limit): ?string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        try {
            while (! $stream->eof() && mb_strlen($body, '8bit') <= $limit) {
                $body .= $stream->read(min(8192, $limit + 1 - mb_strlen($body, '8bit')));
            }
        } finally {
            $stream->close();
        }

        return mb_strlen($body, '8bit') > $limit ? null : $body;
    }

    private function failed(string $error, int|float $startedAt, string $diagnostics = ''): TransformResult
    {
        return new TransformResult(
            output: null,
            stdout: '',
            stderr: $diagnostics,
            exitCode: -1,
            durationMs: $this->elapsedMs($startedAt),
            error: $error,
        );
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
