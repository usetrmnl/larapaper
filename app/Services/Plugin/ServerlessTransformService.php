<?php

namespace App\Services\Plugin;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ServerlessTransformService
{
    private const DEFAULT_TIMEOUT = 30;

    private const INTERPRETERS = [
        'python' => 'python3',
        'node'   => 'node',
        'php'    => 'php',
    ];

    private const EXTENSIONS = [
        'python' => 'py',
        'node'   => 'js',
        'php'    => 'php',
    ];

    public function run(string $code, string $language, array $input, ?int $timeout = null): array
    {
        if (! array_key_exists($language, self::INTERPRETERS)) {
            Log::warning("ServerlessTransformService: unsupported language [{$language}]");

            return $input;
        }

        $effectiveTimeout = $timeout ?? (int) env('TRANSFORM_TIMEOUT_SECONDS', self::DEFAULT_TIMEOUT);
        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'trmnl-tx-'.bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        $scriptPath = $tmpDir.DIRECTORY_SEPARATOR.'transform.'.self::EXTENSIONS[$language];
        $outputPath = $tmpDir.DIRECTORY_SEPARATOR.'output.json';

        try {
            file_put_contents($scriptPath, $this->buildHarness($language, $code, $outputPath));

            $result = Process::input(json_encode($input))
                ->timeout($effectiveTimeout)
                ->run([self::INTERPRETERS[$language], $scriptPath]);

            if (! $result->successful()) {
                Log::warning('ServerlessTransformService: transform exited '.$result->exitCode().': '.trim($result->errorOutput()));

                return $input;
            }

            if (! file_exists($outputPath)) {
                Log::warning('ServerlessTransformService: transform produced no output file');

                return $input;
            }

            $raw = file_get_contents($outputPath);
            if ($raw === false || $raw === '') {
                Log::warning('ServerlessTransformService: transform output file is empty');

                return $input;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                Log::warning('ServerlessTransformService: transform output is not a JSON object');

                return $input;
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::warning('ServerlessTransformService: '.$e->getMessage());

            return $input;
        } finally {
            $this->cleanupDir($tmpDir);
        }
    }

    private function buildHarness(string $language, string $code, string $outputPath): string
    {
        return match ($language) {
            'python' => $this->pythonHarness($code, $outputPath),
            'node'   => $this->nodeHarness($code, $outputPath),
            'php'    => $this->phpHarness($code, $outputPath),
        };
    }

    private function pythonHarness(string $code, string $outputPath): string
    {
        $path = var_export($outputPath, true);

        return implode("\n", [
            'import sys, json',
            'input = json.loads(sys.stdin.read())',
            '',
            $code,
            '',
            "if callable(locals().get('run', None)):",
            '    output = run(input)',
            "elif 'result' in dir():",
            '    output = result',
            'else:',
            '    output = input',
            "json.dump(output, open({$path}, 'w'))",
        ]);
    }

    private function nodeHarness(string $code, string $outputPath): string
    {
        $path = json_encode($outputPath);

        return "const input = JSON.parse(require('fs').readFileSync(0, 'utf8'));\n\n"
            .$code
            ."\n\nlet output;\n"
            ."if (typeof run === 'function') {\n  output = run(input);\n"
            ."} else if (typeof result !== 'undefined') {\n  output = result;\n"
            ."} else {\n  output = input;\n}\n"
            ."require('fs').writeFileSync({$path}, JSON.stringify(output));\n";
    }

    private function phpHarness(string $code, string $outputPath): string
    {
        $cleanedCode = preg_replace('/\A\s*<\?php\s*/u', '', $code);
        $path = var_export($outputPath, true);

        return "<?php\n"
            ."\$input = json_decode(file_get_contents('php://stdin'), true);\n\n"
            .$cleanedCode
            ."\n\nif (function_exists('run')) {\n    \$output = run(\$input);\n"
            ."} elseif (isset(\$result)) {\n    \$output = \$result;\n"
            ."} else {\n    \$output = \$input;\n}\n"
            ."file_put_contents({$path}, json_encode(\$output));\n";
    }

    private function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
