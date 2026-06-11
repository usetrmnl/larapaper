<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use App\Services\Plugin\Transform\LanguageHarness\HarnessInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

final class TransformService
{
    public function __construct(
        private readonly HarnessRegistry $harnesses,
    ) {}

    /**
     * Execute the user's transform code against the parsed polling payload.
     *
     * @param  array<int|string, mixed>  $input  Parsed polling payload only (no render-time context).
     */
    public function execute(string $language, string $code, array $input): TransformResult
    {
        $harness = $this->harnesses->for($language);
        $languageKey = $harness->languageKey();

        $configuredBinary = config('services.transform.binaries.'.$languageKey);
        if (! is_string($configuredBinary) || $configuredBinary === '') {
            return $this->failed("Interpreter binary not configured for [{$languageKey}]");
        }

        $binary = $harness->resolveBinary($configuredBinary);
        if ($binary === null) {
            return $this->failed(
                "Interpreter binary could not be resolved for [{$languageKey}] (configured as [{$configuredBinary}]). Use an absolute path in config, or ensure it is on PATH."
            );
        }

        $stdin = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($stdin === false) {
            return $this->failed('Failed to encode transform input as JSON: '.json_last_error_msg());
        }

        $timeoutSeconds = max(1, (int) config('services.transform.timeout_seconds'));
        $memoryLimitMb = max(8, (int) config('services.transform.memory_limit_mb'));

        return $this->runInSandbox($harness, $binary, $code, $stdin, $timeoutSeconds, $memoryLimitMb);
    }

    private function runInSandbox(
        HarnessInterface $harness,
        string $binary,
        string $code,
        string $stdin,
        int $timeoutSeconds,
        int $memoryLimitMb,
    ): TransformResult {
        $workDir = sys_get_temp_dir().'/trmnl-transform-'.uniqid('', true);
        File::makeDirectory($workDir, 0700, true);

        $startedAt = $this->nowMs();
        $marker = '<<<TRMNL_TRANSFORM:'.Str::random(32).'>>>';

        try {
            $runnerDest = $workDir.'/harness.'.pathinfo($harness->runnerSourcePath(), PATHINFO_EXTENSION);
            File::copy($harness->runnerSourcePath(), $runnerDest);

            $userPath = $workDir.'/'.$harness->userFileName();
            File::put($userPath, $harness->prepareUserCode($code));

            $command = [
                $binary,
                ...$harness->interpreterArgs($memoryLimitMb, $workDir),
                $runnerDest,
                $marker,
                $userPath,
            ];

            try {
                $result = Process::path($workDir)
                    ->env($this->minimalEnv())
                    ->timeout($timeoutSeconds)
                    ->input($stdin)
                    ->run($command);
            } catch (Throwable $e) {
                return new TransformResult(
                    output: null,
                    stdout: '',
                    stderr: $e->getMessage(),
                    exitCode: -1,
                    durationMs: $this->elapsedMs($startedAt),
                    error: 'Transform process failed: '.$e->getMessage(),
                );
            }

            return $this->interpret($result->exitCode() ?? -1, $result->output(), $result->errorOutput(), $marker, $startedAt);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function interpret(int $exitCode, string $stdout, string $stderr, string $marker, int $startedAt): TransformResult
    {
        $durationMs = $this->elapsedMs($startedAt);

        if ($exitCode !== 0) {
            return new TransformResult(
                output: null,
                stdout: $stdout,
                stderr: $stderr,
                exitCode: $exitCode,
                durationMs: $durationMs,
                error: 'Transform process exited with code '.$exitCode,
            );
        }

        $markerPos = mb_strrpos($stdout, $marker, 0, '8bit');
        if ($markerPos === false) {
            return new TransformResult(
                output: null,
                stdout: $stdout,
                stderr: $stderr,
                exitCode: $exitCode,
                durationMs: $durationMs,
                error: 'Transform produced no result',
            );
        }

        $payload = mb_substr($stdout, $markerPos + mb_strlen($marker, '8bit'), null, '8bit');
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return new TransformResult(
                output: null,
                stdout: $stdout,
                stderr: $stderr,
                exitCode: $exitCode,
                durationMs: $durationMs,
                error: 'Transform must return an object or array',
            );
        }

        return new TransformResult(
            output: $decoded,
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $exitCode,
            durationMs: $durationMs,
        );
    }

    /**
     * @return array<string, string>
     */
    private function minimalEnv(): array
    {
        return [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
        ];
    }

    private function failed(string $error): TransformResult
    {
        return new TransformResult(
            output: null,
            stdout: '',
            stderr: '',
            exitCode: -1,
            durationMs: 0,
            error: $error,
        );
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private function elapsedMs(int $startedAt): int
    {
        return $this->nowMs() - $startedAt;
    }
}
