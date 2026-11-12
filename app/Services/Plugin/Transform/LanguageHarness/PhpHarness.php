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
        'getenv', 'putenv', 'apache_getenv', 'apache_setenv', 'mail',
        'ini_set', 'ini_alter', 'ini_restore', 'set_time_limit', 'dl', 'posix_kill',
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'curl_exec', 'curl_multi_exec',
        'socket_create', 'socket_create_listen', 'socket_connect', 'socket_sendto', 'ftp_connect', 'ftp_ssl_connect',
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
            '-n', // Do not load host extensions or startup hooks into user code.
            '-d', 'open_basedir='.$workDir,
            '-d', 'disable_functions='.implode(',', self::DISABLED_FUNCTIONS),
            '-d', 'disable_classes=FFI',
            '-d', 'ffi.enable=false',
            '-d', 'display_errors=stderr',
            '-d', 'html_errors=0',
            '-d', 'log_errors=0',
            '-d', 'variables_order=',
            '-d', 'register_argc_argv=1',
            '-d', 'memory_limit='.$memoryLimitMb.'M',
            '-d', 'allow_url_fopen='.(config('services.transform.allow_network', false) ? '1' : '0'),
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

        if ($configuredBinary !== 'php' || ! is_executable(PHP_BINARY)) {
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
