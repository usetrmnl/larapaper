<?php

use App\Services\Plugin\ServerlessTransformService;
use Illuminate\Support\Facades\Process;

it('transforms data with a PHP run() function', function (): void {
    $service = new ServerlessTransformService();
    $result = $service->run(
        'function run($input) { return ["doubled" => $input["value"] * 2]; }',
        'php',
        ['value' => 5]
    );
    expect($result)->toBe(['doubled' => 10]);
});

it('strips a leading <?php tag from PHP user code', function (): void {
    $service = new ServerlessTransformService();
    $result = $service->run(
        "<?php\nfunction run(\$input) { return ['ok' => true]; }",
        'php',
        []
    );
    expect($result)->toBe(['ok' => true]);
});

it('falls back to $result variable when run() is not defined (PHP)', function (): void {
    $service = new ServerlessTransformService();
    $result = $service->run('$result = ["fallback" => true];', 'php', []);
    expect($result)->toBe(['fallback' => true]);
});

it('passes input through unchanged when neither run() nor $result is defined (PHP)', function (): void {
    $service = new ServerlessTransformService();
    $input = ['key' => 'value'];
    expect($service->run('// no-op', 'php', $input))->toBe($input);
});

it('transforms data with a Node run() function', function (): void {
    $service = new ServerlessTransformService();
    $result = $service->run(
        'function run(input) { return { doubled: input.value * 2 }; }',
        'node',
        ['value' => 5]
    );
    expect($result)->toBe(['doubled' => 10]);
});

it('falls back to result variable when run is not defined (Node)', function (): void {
    $service = new ServerlessTransformService();
    $result = $service->run('const result = { fallback: true };', 'node', []);
    expect($result)->toBe(['fallback' => true]);
});

it('transforms data with a Python run() function', function (): void {
    $service = new ServerlessTransformService();
    $result = $service->run(
        "def run(input):\n    return {'doubled': input['value'] * 2}",
        'python',
        ['value' => 5]
    );
    expect($result)->toBe(['doubled' => 10]);
})->skip(fn () => empty(trim((string) shell_exec('which python3 2>/dev/null'))), 'python3 not on PATH');

it('returns input unchanged for an unsupported language', function (): void {
    $service = new ServerlessTransformService();
    $input = ['key' => 'value'];
    expect($service->run('noop', 'cobol', $input))->toBe($input);
});

it('returns input unchanged when the transform exits non-zero', function (): void {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'fatal error', exitCode: 1)]);
    $service = new ServerlessTransformService();
    $input = ['key' => 'value'];
    expect($service->run('function run($input) { return $input; }', 'php', $input))->toBe($input);
});

it('returns input unchanged when the transform output is not a JSON object', function (): void {
    // run() returns null → json_encode(null) = "null" → json_decode → null, not array
    $service = new ServerlessTransformService();
    $input = ['key' => 'value'];
    $result = $service->run('function run($input) { return null; }', 'php', $input);
    expect($result)->toBe($input);
});

it('returns input unchanged on timeout', function (): void {
    $service = new ServerlessTransformService();
    $input = ['value' => 1];
    $result = $service->run(
        '<?php function run($input) { sleep(10); return $input; }',
        'php',
        $input,
        1  // 1-second timeout
    );
    expect($result)->toBe($input);
});
