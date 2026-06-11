<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform\LanguageHarness;

use Symfony\Component\Process\ExecutableFinder;

final class PhpHarness implements HarnessInterface
{
    /**
     * Functions blocked inside the sandbox: process/exec, environment access, and network.
     */
    private const DISABLED_FUNCTIONS = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'pcntl_fork',
        'getenv', 'putenv', 'apache_getenv', 'apache_setenv',
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'curl_exec', 'curl_multi_exec',
    ];

    public function languageKey(): string
    {
        return 'php';
    }

    public function runnerSourcePath(): string
    {
        return __DIR__.'/../runners/run_php.php';
    }

    public function userFileName(): string
    {
        return 'user.php';
    }

    /**
     * @return list<string>
     */
    public function interpreterArgs(int $memoryLimitMb, string $workDir): array
    {
        return [
            '-d', 'open_basedir='.$workDir,
            '-d', 'disable_functions='.implode(',', self::DISABLED_FUNCTIONS),
            '-d', 'variables_order=',
            '-d', 'register_argc_argv=1',
            '-d', 'memory_limit='.$memoryLimitMb.'M',
            '-d', 'allow_url_fopen=0',
            '-d', 'allow_url_include=0',
            // Neutralise any host-configured prepend/append scripts that open_basedir would block at startup.
            '-d', 'auto_prepend_file=',
            '-d', 'auto_append_file=',
        ];
    }

    public function prepareUserCode(string $code): string
    {
        $trimmed = mb_ltrim($code);

        if ($trimmed === '' || ! str_starts_with($trimmed, '<?php')) {
            return "<?php\n".$code;
        }

        return $code;
    }

    public function resolveBinary(string $configuredBinary): ?string
    {
        if (is_executable($configuredBinary)) {
            return $configuredBinary;
        }

        $resolved = (new ExecutableFinder)->find($configuredBinary, null, $this->searchDirs());
        if ($resolved !== null && is_executable($resolved)) {
            return $resolved;
        }

        if ($configuredBinary !== 'php' || PHP_BINARY === '' || ! is_executable(PHP_BINARY)) {
            return null;
        }

        return str_contains(mb_strtolower(basename(PHP_BINARY)), 'fpm') ? null : PHP_BINARY;
    }

    /**
     * @return list<string>
     */
    private function searchDirs(): array
    {
        return array_values(array_unique(array_filter([
            dirname(PHP_BINARY),
            '/usr/bin',
            '/usr/local/bin',
            '/opt/homebrew/bin',
        ])));
    }
}
