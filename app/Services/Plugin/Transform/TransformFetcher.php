<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Performs the `fetch()` calls of a local JavaScript transform on its behalf.
 *
 * The sandboxed script only hands over a description of each request (URL, method, headers
 * and a text body) and receives status, headers and a text body back, so it never holds a
 * socket, credentials or cookies. Requests follow the same rules as recipe polling: any
 * http(s) URL, with the polling User-Agent unless the script sets its own.
 */
final class TransformFetcher
{
    /** Maximum number of requests one transform run may make. */
    public const MAX_REQUESTS_PER_RUN = 20;

    /** Maximum bytes of one response body handed to the script. */
    public const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

    /** Maximum bytes of one request body sent by the script. */
    private const MAX_REQUEST_BODY_BYTES = 1024 * 1024;

    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private int $requestCount = 0;

    public static function networkIsAllowed(): bool
    {
        return (bool) config('services.transform.allow_network', true);
    }

    /**
     * Answer a batch of requests from the sandbox. Each answer carries the request's `id` and
     * either a response or an `error` that the script's `fetch()` rejects with.
     *
     * @param  list<mixed>  $requests
     * @return list<array<string, mixed>>
     */
    public function handle(array $requests, float $deadline): array
    {
        return array_map(fn (mixed $request): array => $this->answer($request, $deadline), $requests);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(mixed $request, float $deadline): array
    {
        $id = is_array($request) && is_int($request['id'] ?? null) ? $request['id'] : 0;

        if (! is_array($request)) {
            return ['id' => $id, 'error' => 'Invalid fetch request'];
        }

        if (! self::networkIsAllowed()) {
            return ['id' => $id, 'error' => 'Network access is disabled for transforms'];
        }

        if (++$this->requestCount > self::MAX_REQUESTS_PER_RUN) {
            return ['id' => $id, 'error' => 'A transform may make at most '.self::MAX_REQUESTS_PER_RUN.' requests'];
        }

        $url = $request['url'] ?? null;
        if (! is_string($url) || ! in_array(mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return ['id' => $id, 'error' => 'Only http and https URLs can be fetched'];
        }

        $method = is_string($request['method'] ?? null) ? mb_strtoupper($request['method']) : 'GET';
        if (! in_array($method, self::METHODS, true)) {
            return ['id' => $id, 'error' => "Unsupported request method [{$method}]"];
        }

        $body = $request['body'] ?? null;
        if ($body !== null && (! is_string($body) || mb_strlen($body, '8bit') > self::MAX_REQUEST_BODY_BYTES)) {
            return ['id' => $id, 'error' => 'Request body must be a string of at most 1 MiB'];
        }

        $remainingSeconds = $deadline - microtime(true);
        if ($remainingSeconds <= 0) {
            return ['id' => $id, 'error' => 'fetch timed out'];
        }

        return ['id' => $id, ...$this->send($url, $method, $this->headers($request['headers'] ?? []), $body, $remainingSeconds)];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(string $url, string $method, array $headers, ?string $body, float $remainingSeconds): array
    {
        $timeout = max(1, min((int) config('app.http_client_timeout', 15), (int) ceil($remainingSeconds)));

        $pending = Http::withHeaders(['User-Agent' => 'usetrmnl/larapaper', ...$headers])
            ->timeout($timeout)
            ->connectTimeout(min($timeout, 5))
            ->withOptions(['stream' => true]);

        if ($body !== null) {
            $pending = $pending->withBody($body, $headers['Content-Type'] ?? 'text/plain;charset=UTF-8');
        }

        try {
            $response = $pending->send($method, $url);
            $responseBody = $method === 'HEAD' ? '' : $this->readBounded($response);
        } catch (Throwable) {
            // Transport errors can include resolved addresses; the script only learns that the request failed.
            return ['error' => 'fetch failed'];
        }

        if ($responseBody === null) {
            return ['error' => 'Response body exceeds '.(self::MAX_RESPONSE_BYTES / 1024 / 1024).' MiB'];
        }

        return [
            'status' => $response->status(),
            'statusText' => $response->reason(),
            'url' => $url,
            'headers' => $this->responseHeaders($response),
            'body' => mb_convert_encoding($responseBody, 'UTF-8', 'UTF-8'),
        ];
    }

    /**
     * Header pairs from the sandbox, keeping only well-formed names and values.
     *
     * @return array<string, string>
     */
    private function headers(mixed $pairs): array
    {
        $headers = [];

        foreach (is_array($pairs) ? array_slice($pairs, 0, 50) : [] as $pair) {
            if (! is_array($pair) || ! is_string($pair[0] ?? null) || ! is_string($pair[1] ?? null)) {
                continue;
            }

            [$name, $value] = $pair;
            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
                continue;
            }

            $headers[$this->canonicalHeaderName($name)] = $value;
        }

        return $headers;
    }

    private function canonicalHeaderName(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', mb_strtolower($name))));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function responseHeaders(Response $response): array
    {
        $pairs = [];

        foreach ($response->headers() as $name => $values) {
            $pairs[] = [mb_strtolower((string) $name), implode(', ', (array) $values)];
        }

        return $pairs;
    }

    /**
     * Read the response body without buffering more than the limit; null when it is larger.
     */
    private function readBounded(Response $response): ?string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        try {
            while (! $stream->eof() && mb_strlen($body, '8bit') <= self::MAX_RESPONSE_BYTES) {
                $body .= $stream->read(min(65536, self::MAX_RESPONSE_BYTES + 1 - mb_strlen($body, '8bit')));
            }
        } finally {
            $stream->close();
        }

        return mb_strlen($body, '8bit') > self::MAX_RESPONSE_BYTES ? null : $body;
    }
}
