<?php

declare(strict_types=1);

use App\Services\Plugin\Transform\TransformService;

test('php transform returns decoded json output via run()', function (): void {
    $code = <<<'PHP'
<?php

function run($input)
{
    return ['n' => ($input['x'] ?? 0) + 1];
}
PHP;

    $result = app(TransformService::class)->execute('php', $code, ['x' => 41]);

    expect($result->succeeded())->toBeTrue()
        ->and($result->output)->toBe(['n' => 42]);
});

test('php transform supports the legacy transform() name', function (): void {
    $code = <<<'PHP'
<?php

function transform($input)
{
    return ['legacy' => true, 'n' => ($input['x'] ?? 0) + 1];
}
PHP;

    $result = app(TransformService::class)->execute('php', $code, ['x' => 1]);

    expect($result->succeeded())->toBeTrue()
        ->and($result->output)->toBe(['legacy' => true, 'n' => 2]);
});

test('php transform prefers run() over transform() when both exist', function (): void {
    $code = <<<'PHP'
<?php

function run($input)
{
    return ['picked' => 'run'];
}

function transform($input)
{
    return ['picked' => 'transform'];
}
PHP;

    $result = app(TransformService::class)->execute('php', $code, []);

    expect($result->output)->toBe(['picked' => 'run']);
});

test('user code without an opening php tag still runs', function (): void {
    $code = <<<'PHP'
function run($input)
{
    return ['ok' => true];
}
PHP;

    $result = app(TransformService::class)->execute('php', $code, []);

    expect($result->succeeded())->toBeTrue()
        ->and($result->output)->toBe(['ok' => true]);
});

test('user stdout noise does not corrupt the result', function (): void {
    $code = <<<'PHP'
<?php

function run($input)
{
    echo "this should be swallowed";
    print_r($input);

    return ['clean' => true];
}
PHP;

    $result = app(TransformService::class)->execute('php', $code, ['a' => 1]);

    expect($result->succeeded())->toBeTrue()
        ->and($result->output)->toBe(['clean' => true]);
});

test('php transform succeeds when stdin json exceeds typical pipe buffer size', function (): void {
    $code = <<<'PHP'
<?php

function run($input)
{
    return ['blob_len' => strlen($input['blob'] ?? '')];
}
PHP;

    $payload = ['blob' => str_repeat('a', 2_000_000)];

    $result = app(TransformService::class)->execute('php', $code, $payload);

    expect($result->succeeded())->toBeTrue()
        ->and($result->output)->toBe(['blob_len' => 2_000_000]);
});

test('php transform succeeds for large utf-8 payload where byte length differs from character count', function (): void {
    $code = <<<'PHP'
<?php

function run($input)
{
    return ['label' => $input['label'] ?? '', 'blob_len' => strlen($input['blob'] ?? '')];
}
PHP;

    $label = 'Café résumé 🪴';
    $blob = str_repeat('x', 70_000).str_repeat("\u{00A9}", 5_000).str_repeat('y', 45_000);
    $blobByteLength = mb_strlen($blob, '8bit');

    $result = app(TransformService::class)->execute('php', $code, [
        'label' => $label,
        'blob' => $blob,
    ]);

    expect($result->succeeded())->toBeTrue()
        ->and($result->output['label'])->toBe($label)
        ->and($result->output['blob_len'])->toBe($blobByteLength);
});

test('php transform fails when output is not an object or array', function (): void {
    $code = <<<'PHP'
<?php

function run($input)
{
    return 1;
}
PHP;

    $result = app(TransformService::class)->execute('php', $code, ['x' => 1]);

    expect($result->succeeded())->toBeFalse()
        ->and($result->error)->toContain('object or array');
});

test('php transform fails when run() is not defined', function (): void {
    $code = <<<'PHP'
<?php

$noop = 1;
PHP;

    $result = app(TransformService::class)->execute('php', $code, []);

    expect($result->succeeded())->toBeFalse()
        ->and($result->exitCode)->not->toBe(0);
});

test('environment variables cannot be read inside the sandbox', function (): void {
    config(['services.transform.enabled' => true]);
    putenv('TRANSFORM_SECRET_PROBE=super-secret-value');

    $code = <<<'PHP'
<?php

function run($input)
{
    return [
        'env_probe' => getenv('TRANSFORM_SECRET_PROBE'),
        'server' => $_SERVER['TRANSFORM_SECRET_PROBE'] ?? null,
    ];
}
PHP;

    $result = app(TransformService::class)->execute('php', $code, []);

    // getenv is a disabled function, so calling it is a fatal error => failure.
    expect($result->succeeded())->toBeFalse();

    putenv('TRANSFORM_SECRET_PROBE');
});

test('filesystem outside the sandbox cannot be accessed', function (): void {
    $code = <<<'PHP'
<?php

function run($input)
{
    return ['contents' => @file_get_contents('/etc/hostname')];
}
PHP;

    $result = app(TransformService::class)->execute('php', $code, []);

    // open_basedir restriction makes the read return false (empty), not the host file.
    if ($result->succeeded()) {
        expect($result->output['contents'])->toBeFalsy();
    } else {
        expect($result->succeeded())->toBeFalse();
    }
});

test('unsupported language throws from service', function (): void {
    expect(fn () => app(TransformService::class)->execute('lua', 'x', []))
        ->toThrow(InvalidArgumentException::class);
});

test('transform fails when interpreter binary cannot be resolved', function (): void {
    config(['services.transform.binaries.php' => 'definitely_missing_interpreter_zzzz_12345']);

    $result = app(TransformService::class)->execute('php', '<?php function run($a) { return []; }', []);

    expect($result->succeeded())->toBeFalse()
        ->and($result->error)->toContain('could not be resolved');
});
