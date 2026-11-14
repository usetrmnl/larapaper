<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugin\Transform\LocalTransformProvider;
use App\Services\Plugin\Transform\TransformFetcher;
use App\Services\Plugin\Transform\TransformResult;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.transform.provider' => 'local',
        'services.transform.node_binary' => 'node',
        'services.transform.timeout_seconds' => 2,
        'services.transform.memory_limit_mb' => 64,
        'services.transform.max_output_bytes' => 1048576,
    ]);

    if (app(LocalTransformProvider::class)->languages() !== ['node']) {
        $this->markTestSkipped('Node.js 24 or newer is required for the local transform provider.');
    }
});

function runLocalTransform(string $code, array $input = ['items' => [1, 2, 3]]): TransformResult
{
    return app(LocalTransformProvider::class)->execute('node', $code, $input);
}

test('run(input) output and console logs are returned', function (): void {
    $result = runLocalTransform('function run(input) { console.log("items", input.items); return { count: input.items.length }; }');

    expect($result->succeeded())->toBeTrue()
        ->and($result->output)->toBe(['count' => 3])
        ->and($result->stderr)->toBe('items [1,2,3]');
});

test('run is found in the supported script styles', function (string $code): void {
    expect(runLocalTransform($code)->output)->toBe(['first' => 1]);
})->with([
    'function declaration' => 'function run(input) { return { first: input.items[0] }; }',
    'async function' => 'async function run(input) { await null; return { first: input.items[0] }; }',
    'const arrow function' => 'const run = (input) => ({ first: input.items[0] });',
    'module.exports.run' => 'module.exports = { run: (input) => ({ first: input.items[0] }) };',
    'exported function' => 'export function run(input) { return { first: input.items[0] }; }',
    'export default' => 'export default function (input) { return { first: input.items[0] }; }',
    'deprecated transform function' => 'function transform(input) { return { first: input.items[0] }; }',
    'deprecated module.exports.transform' => 'module.exports = { transform: (input) => ({ first: input.items[0] }) };',
    'run preferred over transform' => 'function transform() { return { first: 0 }; } function run(input) { return { first: input.items[0] }; }',
]);

test('the require.main idiom for local testing is skipped and require() itself is unavailable', function (): void {
    $result = runLocalTransform(<<<'JS'
        function transform(input) { return { count: input.items.length }; }
        if (require.main === module) { transform({}); }
        JS);

    expect($result->output)->toBe(['count' => 3])
        ->and(runLocalTransform('const fs = require("fs"); function run() { return {}; }')->error)->toBe('Error: require() is not available in transforms (line 1)');
});

test('text cut through an emoji is returned with a replacement character', function (): void {
    $result = runLocalTransform('function run() { return { text: "ok 😀".slice(0, 4) }; }');

    expect($result->output)->toBe(['text' => "ok \u{FFFD}"]);
});

test('script mistakes are reported to the recipe owner', function (string $code, string $error): void {
    $result = runLocalTransform($code);

    expect($result->succeeded())->toBeFalse()
        ->and($result->error)->toBe($error);
})->with([
    'missing run' => ['const handler = 1;', 'run() is not defined'],
    'scalar result' => ['function run() { return 5; }', 'Transform must return an object or array'],
    'thrown error with line' => ["function run(input) {\n  return input.missing.value;\n}", "TypeError: Cannot read properties of undefined (reading 'value') (line 2)"],
    'syntax error with line' => ["function run(input) {\n  return {;\n}", "SyntaxError: Unexpected token ';' (line 2)"],
    'never settling promise' => ['function run() { return new Promise(() => {}); }', 'run() returned a promise that never settled'],
]);

test('scripts cannot reach node or shared memory', function (): void {
    $result = runLocalTransform('function run() { return [typeof process, typeof Buffer, typeof WebAssembly, typeof SharedArrayBuffer, typeof Atomics]; }');

    expect($result->output)->toBe(array_fill(0, 5, 'undefined'));
});

test('sandbox escape attempts fail', function (string $code, string $error): void {
    putenv('TRANSFORM_ESCAPE_PROBE=leaked-secret');

    try {
        $result = runLocalTransform($code);
    } finally {
        putenv('TRANSFORM_ESCAPE_PROBE');
    }

    expect($result->succeeded())->toBeFalse()
        ->and($result->error)->toBe($error);
})->with([
    'function constructor' => [
        'function run() { return { env: (() => {}).constructor("return process.env")() }; }',
        'EvalError: Code generation from strings disallowed for this context (line 1)',
    ],
    'global constructor chain' => [
        'function run() { return { env: this.constructor.constructor("return process.env")() }; }',
        'EvalError: Code generation from strings disallowed for this context (line 1)',
    ],
    'eval' => [
        'function run() { return { env: eval("process.env") }; }',
        'EvalError: Code generation from strings disallowed for this context (line 1)',
    ],
    'dynamic import' => [
        'async function run() { const fs = await import("node:fs"); return { file: fs.readFileSync("/etc/passwd", "utf8") }; }',
        'import() is not available in transforms',
    ],
    'dynamic import hidden behind a comment' => [
        'async function run() { const cp = await import/**/("node:child_process"); return { id: cp.execSync("id").toString() }; }',
        'import() is not available in transforms',
    ],
]);

test('values a script throws are formatted inside the sandbox and bounded by the timeout', function (): void {
    config(['services.transform.timeout_seconds' => 1]);

    $result = runLocalTransform('function run() { throw new Proxy({}, { get() { for (;;) {} }, getPrototypeOf() { for (;;) {} } }); }');

    expect($result->error)->toBe('Transform timed out after 1000 ms');
});

test('no state is shared between runs', function (): void {
    runLocalTransform('globalThis.leaked = "previous user"; Object.prototype.polluted = true; function run() { return {}; }');

    $result = runLocalTransform('function run() { return { leaked: typeof leaked, polluted: typeof ({}).polluted }; }');

    expect($result->output)->toBe(['leaked' => 'undefined', 'polluted' => 'undefined']);
});

test('runaway scripts are stopped by the limits', function (string $code, array $config, string $error): void {
    config($config);

    expect(runLocalTransform($code)->error)->toBe($error);
})->with([
    'synchronous loop' => ['function run() { for (;;) {} }', ['services.transform.timeout_seconds' => 1], 'Transform timed out after 1000 ms'],
    'await loop' => ['async function run() { for (;;) { await null; } }', ['services.transform.timeout_seconds' => 1], 'Transform timed out after 1000 ms'],
    'top-level loop' => ['for (;;) {}', ['services.transform.timeout_seconds' => 1], 'Transform timed out after 1000 ms'],
    'heap exhaustion' => ['function run() { const chunks = []; for (;;) { chunks.push("x".repeat(1e6) + Math.random()); } }', ['services.transform.memory_limit_mb' => 16], 'Transform exceeded the memory limit'],
    'oversized output' => ['function run() { return { text: "x".repeat(5000) }; }', ['services.transform.max_output_bytes' => 1024], 'Transform output exceeds limit'],
    'timer beyond the timeout' => ['async function run() { await new Promise((resolve) => setTimeout(resolve, 60000)); return {}; }', ['services.transform.timeout_seconds' => 1], 'Transform timed out after 1000 ms'],
    'endless interval' => ['function run() { return new Promise(() => setInterval(() => {}, 1)); }', ['services.transform.timeout_seconds' => 1], 'Transform timed out after 1000 ms'],
]);

test('binary buffers are capped by the memory limit', function (string $code): void {
    config(['services.transform.memory_limit_mb' => 16]);

    expect(runLocalTransform($code)->error)->toBe('RangeError: Transform exceeded the binary buffer limit (line 1)');
})->with([
    'large ArrayBuffer' => 'function run() { new ArrayBuffer(2 ** 30); return {}; }',
    'resizable ArrayBuffer' => 'function run() { new ArrayBuffer(1, { maxByteLength: 2 ** 30 }); return {}; }',
    'typed array loop' => 'function run() { const kept = []; for (;;) { kept.push(new Uint8Array(1e6)); } }',
    'copies through the intrinsic constructor' => 'function run() { const bytes = new Uint8Array(4e6); bytes.constructor = undefined; const kept = []; for (;;) { kept.push(bytes.slice()); } }',
    'transfer to a larger buffer' => 'function run() { new ArrayBuffer(8).transfer(2 ** 30); return {}; }',
]);

test('scripts get the web APIs of the TRMNL Node runtime', function (): void {
    $result = runLocalTransform(<<<'JS'
        async function run() {
            const started = Date.now();
            await new Promise((resolve) => setTimeout(resolve, 50));
            let ticks = 0;
            await new Promise((resolve) => { const id = setInterval(() => { if (++ticks === 3) { clearInterval(id); resolve(); } }, 5); });
            const cancelled = setTimeout(() => { throw new Error('cleared timer fired'); }, 1);
            clearTimeout(cancelled);
            const controller = new AbortController();
            controller.abort();
            const bytes = new TextEncoder().encode('Grüße €');
            return {
                waited: Date.now() - started >= 50,
                ticks,
                abortError: controller.signal.reason.name,
                byteLength: bytes.length,
                decoded: new TextDecoder().decode(bytes),
                basic: btoa('user:pass'),
                decodedBasic: atob('dXNlcjpwYXNz'),
            };
        }
        JS);

    expect($result->output)->toBe([
        'waited' => true,
        'ticks' => 3,
        'abortError' => 'AbortError',
        'byteLength' => 11,
        'decoded' => 'Grüße €',
        'basic' => 'dXNlcjpwYXNz',
        'decodedBasic' => 'user:pass',
    ]);
});

test('fetch() requests are made by the server and their responses reach the script', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://api.example.test/items' => Http::response(['items' => ['a', 'b']], 201, ['X-Total' => '2'])]);

    $result = runLocalTransform(<<<'JS'
        async function run() {
            const response = await fetch('https://api.example.test/items', {
                method: 'post',
                headers: { 'Content-Type': 'application/json', 'X-Api-Key': 'from-script' },
                body: JSON.stringify({ page: 1 }),
            });
            const data = await response.json();
            return { ok: response.ok, status: response.status, total: response.headers.get('x-total'), items: data.items };
        }
        JS);

    expect($result->output)->toBe(['ok' => true, 'status' => 201, 'total' => '2', 'items' => ['a', 'b']]);
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->body() === '{"page":1}'
        && $request->header('X-Api-Key') === ['from-script']
        && $request->header('User-Agent') === ['usetrmnl/larapaper']);
});

test('fetch() failures reject inside the script', function (string $url, Closure $response, string $error): void {
    Http::preventStrayRequests();
    Http::fake(['https://api.example.test/*' => $response]);

    $result = runLocalTransform("async function run() { try { await fetch('{$url}'); return { failed: false }; } catch (error) { return { failed: error.message }; } }");

    expect($result->output)->toBe(['failed' => $error]);
})->with([
    'local file' => ['file:///etc/passwd', fn () => Http::response(), 'Only http and https URLs can be fetched'],
    'connection failure' => ['https://api.example.test/down', fn () => Http::failedConnection(), 'fetch failed'],
    'oversized response' => ['https://api.example.test/huge', fn () => Http::response(str_repeat('x', TransformFetcher::MAX_RESPONSE_BYTES + 1)), 'Response body exceeds 5 MiB'],
]);

test('fetch() with an aborted signal rejects without a request', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $result = runLocalTransform("async function run() { const controller = new AbortController(); controller.abort(); try { await fetch('https://api.example.test/items', { signal: controller.signal }); } catch (error) { return { error: error.name }; } }");

    expect($result->output)->toBe(['error' => 'AbortError']);
    Http::assertNothingSent();
});

test('fetch() raced against an abortable timeout resolves when the response arrives first', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://api.example.test/quote' => Http::response(['price' => 42])]);

    $result = runLocalTransform(<<<'JS'
        async function run() {
            const controller = new AbortController();
            let timer;
            const deadline = new Promise((_, reject) => {
                timer = setTimeout(() => { controller.abort(); reject(new Error('Request timed out')); }, 1500);
            });
            try {
                const response = await Promise.race([fetch('https://api.example.test/quote', { signal: controller.signal }), deadline]);
                return await response.json();
            } finally {
                clearTimeout(timer);
                controller.abort();
            }
        }
        JS);

    expect($result->output)->toBe(['price' => 42]);
});

test('fetch() exposes error statuses like the fetch API', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://api.example.test/missing' => Http::response('gone', 404)]);

    $result = runLocalTransform("async function run() { const response = await fetch('https://api.example.test/missing'); return { ok: response.ok, status: response.status, body: await response.text() }; }");

    expect($result->output)->toBe(['ok' => false, 'status' => 404, 'body' => 'gone']);
});

test('a run may make a limited number of requests', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://api.example.test/*' => fn () => Http::response(['ok' => true])]);

    $result = runLocalTransform(<<<'JS'
        async function run() {
            const results = await Promise.allSettled(Array.from({ length: 21 }, (_, i) => fetch('https://api.example.test/' + i)));
            return { rejected: results.filter((result) => result.status === 'rejected').map((result) => result.reason.message) };
        }
        JS);

    expect($result->output)->toBe(['rejected' => ['A transform may make at most 20 requests']]);
    Http::assertSentCount(20);
});

test('fetch() rejects without a request when network access is disabled', function (): void {
    config(['services.transform.allow_network' => false]);
    Http::preventStrayRequests();
    Http::fake();

    $result = runLocalTransform("async function run() { await fetch('https://api.example.test/items'); return {}; }");

    expect($result->error)->toBe('TypeError: Network access is disabled for transforms (line 1)');
    Http::assertNothingSent();
});

test('the server refuses fetch requests when network access is disabled', function (): void {
    config(['services.transform.allow_network' => false]);
    Http::preventStrayRequests();
    Http::fake();

    $answers = (new TransformFetcher)->handle([['id' => 1, 'url' => 'https://api.example.test/items', 'method' => 'GET', 'headers' => [], 'body' => null]], microtime(true) + 5);

    expect($answers)->toBe([['id' => 1, 'error' => 'Network access is disabled for transforms']]);
    Http::assertNothingSent();
});

test('polling stores the output of a local JavaScript transform that fetches more data', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://example.test/api' => Http::response(['catalogue' => 'https://example.test/catalogue.json']),
        'https://example.test/catalogue.json' => Http::response(['items' => [1, 2, 3]]),
    ]);
    $plugin = Plugin::factory()->create([
        'user_id' => User::factory()->create()->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.test/api',
        'polling_verb' => 'get',
        'configuration' => ['display_label' => 'Janus'],
        'transform_language' => 'node',
        'transform_code' => <<<'JS'
            async function run(input) {
                const catalogue = await (await fetch(input.catalogue)).json();
                return { count: catalogue.items.length, label: input.trmnl.plugin_settings.custom_fields_values.display_label };
            }
            JS,
    ]);

    $plugin->updateDataPayload();

    expect($plugin->fresh())
        ->data_payload->toBe(['count' => 3, 'label' => 'Janus'])
        ->transform_error->toBeNull();
});
