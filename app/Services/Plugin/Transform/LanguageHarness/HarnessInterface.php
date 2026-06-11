<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform\LanguageHarness;

interface HarnessInterface
{
    /**
     * Language key used in config + the plugins.transform_language column.
     */
    public function languageKey(): string;

    /**
     * Absolute path to the trusted runner script shipped with the app.
     */
    public function runnerSourcePath(): string;

    /**
     * File name the user's code is written to inside the sandbox work dir.
     */
    public function userFileName(): string;

    /**
     * Interpreter flags inserted after the binary and before the runner script path.
     * This is where per-language sandbox guardrails live.
     *
     * @param  string  $workDir  Sandbox working directory (e.g. for open_basedir).
     * @return list<string>
     */
    public function interpreterArgs(int $memoryLimitMb, string $workDir): array;

    /**
     * Normalise the user's source before it is written to disk
     * (e.g. PHP requires a leading `<?php` tag).
     */
    public function prepareUserCode(string $code): string;

    /**
     * Resolve the configured binary to an executable path, or null if it cannot be found.
     * proc-based execution with an argv array does not search PATH, so bare names must be resolved.
     */
    public function resolveBinary(string $configuredBinary): ?string;
}
