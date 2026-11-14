<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

/**
 * Runs JavaScript transforms with the Node.js binary on this host.
 *
 * Every run gets its own short-lived `node` process with an empty environment, started under
 * Node's permission model without any grants (no filesystem, child processes, workers or addons)
 * and with code generation from strings disabled. Inside it, `runners/run_node.mjs` evaluates the
 * user's code in a fresh `node:vm` context that only exchanges strings with the runner, so the
 * script has no `process`, `require`, `import()`, timers or sockets. Its `fetch()` only describes
 * requests, which TransformFetcher performs in PHP unless `TRANSFORM_ALLOW_NETWORK=false`.
 *
 * This is a restricted runtime, not an OS-level sandbox: the process shares the PHP user and has
 * no CPU quota beyond the timeout.
 */
final class LocalTransformProvider implements TransformProvider
{
    /** First Node.js release with a stable permission model. */
    private const MINIMUM_NODE_MAJOR_VERSION = 24;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Seconds the PHP side waits beyond the runner's own timeout before killing the process. */
    private const KILL_GRACE_SECONDS = 2;

    /** @var array<string, string|null> Resolved binary (null when unusable) per configured value. */
    private static array $resolvedBinaries = [];

    /**
     * `node` when a Node.js binary of a supported version resolves on this host.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        return $this->nodeBinary() !== null ? ['node'] : [];
    }

    /**
     * @param  array<int|string, mixed>  $input
     */
    public function execute(string $language, string $code, array $input): TransformResult
    {
        if ($language !== 'node') {
            throw new InvalidArgumentException('Unsupported transform language');
        }

        $startedAt = hrtime(true);

        $binary = $this->nodeBinary();
        if ($binary === null) {
            return $this->failed('Node.js '.self::MINIMUM_NODE_MAJOR_VERSION.' or newer was not found (TRANSFORM_NODE_BINARY)', $startedAt);
        }

        $stdin = json_encode(['code' => $code, 'input' => $input], self::JSON_FLAGS);
        if ($stdin === false) {
            return $this->failed('Transform input could not be encoded as JSON', $startedAt);
        }

        $timeoutSeconds = max(1, (int) config('services.transform.timeout_seconds', 5));
        $memoryLimitMb = max(16, (int) config('services.transform.memory_limit_mb', 128));

        $command = [
            $binary,
            '--permission',
            '--disallow-code-generation-from-strings',
            '--no-addons',
            '--max-old-space-size='.$memoryLimitMb,
            '--disable-warning=ExperimentalWarning',
            self::runnerPath(),
            (string) ($timeoutSeconds * 1000),
            TransformFetcher::networkIsAllowed() ? '1' : '0',
            // Binary buffers live outside the V8 heap; the runner caps them at the same budget.
            (string) ($memoryLimitMb * 1024 * 1024),
        ];

        $stdinStream = new InputStream;
        $process = new SymfonyProcess($command, dirname(self::runnerPath()), self::emptyEnvironment(), $stdinStream, $timeoutSeconds + self::KILL_GRACE_SECONDS);

        try {
            $process->start();
            $stdinStream->write($stdin."\n");
            $result = $this->converse($process, $stdinStream, microtime(true) + $timeoutSeconds);
            $process->wait();
        } catch (ProcessTimedOutException) {
            return $this->failed("Transform timed out after {$timeoutSeconds} seconds", $startedAt);
        } catch (OutputLimitExceededException) {
            return $this->failed('Transform output exceeds limit', $startedAt);
        } catch (InvalidTransformMessageException) {
            return $this->failed('Transform produced an invalid result', $startedAt);
        } catch (ProcessSignaledException $e) {
            // V8 aborts the process when the heap limit is reached.
            return $this->crashed('signal '.$e->getSignal(), $e->getProcess()->getErrorOutput(), $startedAt);
        } catch (Throwable) {
            return $this->failed('Transform process could not be started', $startedAt);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        if ($result === null) {
            return $this->crashed('code '.($process->getExitCode() ?? -1), $process->getErrorOutput(), $startedAt);
        }

        return $this->interpret($result, $startedAt);
    }

    public static function runnerPath(): string
    {
        return __DIR__.'/runners/run_node.mjs';
    }

    /**
     * Read the runner's line-delimited messages, answer its fetch batches through
     * TransformFetcher, and return the final result message (null if none arrived).
     *
     * @return array<string, mixed>|null
     *
     * @throws OutputLimitExceededException
     * @throws InvalidTransformMessageException
     */
    private function converse(SymfonyProcess $process, InputStream $stdinStream, float $deadline): ?array
    {
        $maxOutputBytes = max(1, (int) config('services.transform.max_output_bytes', 1048576));
        $fetcher = new TransformFetcher;
        $outputBytes = 0;
        $buffer = '';

        foreach ($process->getIterator(SymfonyProcess::ITER_KEEP_OUTPUT) as $type => $chunk) {
            $outputBytes += mb_strlen($chunk, '8bit');
            if ($outputBytes > $maxOutputBytes) {
                throw new OutputLimitExceededException;
            }

            if ($type !== SymfonyProcess::OUT) {
                continue;
            }

            $buffer .= $chunk;

            while (($newline = mb_strpos($buffer, "\n", 0, '8bit')) !== false) {
                $message = json_decode(mb_substr($buffer, 0, $newline, '8bit'), true);
                $buffer = mb_substr($buffer, $newline + 1, null, '8bit');

                if (! is_array($message)) {
                    throw new InvalidTransformMessageException;
                }

                if (($message['type'] ?? null) === 'result') {
                    $stdinStream->close();

                    return $message;
                }

                if (($message['type'] ?? null) === 'fetch' && is_array($message['requests'] ?? null)) {
                    $answers = $fetcher->handle(array_values($message['requests']), $deadline);
                    $stdinStream->write(json_encode(['type' => 'responses', 'responses' => $answers], self::JSON_FLAGS)."\n");
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function interpret(array $response, int|float $startedAt): TransformResult
    {
        $logs = is_array($response['logs'] ?? null)
            ? implode("\n", array_filter($response['logs'], is_string(...)))
            : '';

        if (($response['ok'] ?? false) !== true || ! is_array($response['output'] ?? null)) {
            $error = is_string($response['error'] ?? null) ? $response['error'] : 'Transform must return an object or array';

            return $this->failed($error, $startedAt, $logs);
        }

        return new TransformResult(
            output: $response['output'],
            stdout: '',
            stderr: $logs,
            exitCode: 0,
            durationMs: $this->elapsedMs($startedAt),
        );
    }

    /**
     * The runner reports every script failure on stdout, so a crash means Node itself gave up.
     * Node's own stderr is not surfaced: it describes the runner, not the user's script.
     */
    private function crashed(string $reason, string $stderr, int|float $startedAt): TransformResult
    {
        return $this->failed(
            str_contains($stderr, 'heap out of memory') ? 'Transform exceeded the memory limit' : 'Transform process exited with '.$reason,
            $startedAt,
        );
    }

    /**
     * Absolute path of a usable Node.js binary, or null when none of a supported version is found.
     */
    private function nodeBinary(): ?string
    {
        $configured = config('services.transform.node_binary', 'node');
        if (! is_string($configured) || $configured === '') {
            return null;
        }

        if (! array_key_exists($configured, self::$resolvedBinaries)) {
            self::$resolvedBinaries[$configured] = $this->resolveBinary($configured);
        }

        return self::$resolvedBinaries[$configured];
    }

    private function resolveBinary(string $configured): ?string
    {
        $binary = str_contains($configured, '/')
            ? $configured
            : (new ExecutableFinder)->find($configured, null, ['/usr/local/bin', '/usr/bin', '/opt/homebrew/bin']);

        if ($binary === null || ! is_file($binary) || ! is_executable($binary)) {
            return null;
        }

        try {
            $version = mb_trim(Process::env(self::emptyEnvironment())->timeout(5)->run([$binary, '--version'])->output());
        } catch (Throwable) {
            return null;
        }

        if (preg_match('/^v(\d+)\./', $version, $matches) !== 1 || (int) $matches[1] < self::MINIMUM_NODE_MAJOR_VERSION) {
            return null;
        }

        return realpath($binary) ?: $binary;
    }

    /**
     * Symfony Process merges the given environment into the parent's, so every inherited
     * variable is explicitly removed. The child starts with no environment at all.
     *
     * @return array<string, false>
     */
    private static function emptyEnvironment(): array
    {
        return array_fill_keys(array_map(strval(...), array_unique(array_merge(
            array_keys(getenv()),
            array_keys($_ENV),
            array_keys($_SERVER),
        ))), false);
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
