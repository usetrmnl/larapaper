<?php

declare(strict_types=1);

use App\Actions\Api\RunDeviceDisplayCycle;
use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugin\Transform\HttpTransformProvider;
use App\Services\Plugin\Transform\TransformException;
use App\Services\Plugin\Transform\TransformProvider;
use App\Services\Plugin\Transform\TransformResult;
use App\Services\Plugin\Transform\TransformService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Bind a provider that records its input and returns the given result.
 *
 * @param  array<int|string, mixed>|null  $output
 * @return object{inputs: list<array<int|string, mixed>>}
 */
function fakeTransformProvider(?array $output, ?string $error = null, string $stderr = ''): object
{
    $provider = new class($output, $error, $stderr) implements TransformProvider
    {
        /** @var list<array<int|string, mixed>> */
        public array $inputs = [];

        public function __construct(private ?array $output, private ?string $error, private string $stderr) {}

        public function execute(string $language, string $code, array $input): TransformResult
        {
            $this->inputs[] = $input;

            return new TransformResult($this->output, '', $this->stderr, $this->error === null ? 0 : 1, 1, $this->error);
        }

        public function languages(): array
        {
            return ['php', 'node'];
        }
    };

    app()->instance(TransformProvider::class, $provider);

    return $provider;
}

function transformPlugin(array $attributes = []): Plugin
{
    return Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://source.test',
        'polling_verb' => 'get',
        'transform_language' => 'php',
        'transform_code' => 'function run($input) { return $input; }',
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    config(['services.transform.provider' => 'http']);
    // Runner contract tests below fake only POST /run; manifest tests clear this.
    Cache::put('transform:languages', HttpTransformProvider::LANGUAGES);
});

test('transforms are enabled only with the http provider', function (?string $provider, bool $enabled): void {
    config(['services.transform.provider' => $provider]);

    expect(TransformService::isEnabled())->toBe($enabled)
        ->and(app(TransformProvider::class))->toBeInstanceOf(HttpTransformProvider::class);
})->with([
    'http' => ['http', true],
    'unset' => [null, false],
    'removed local provider' => ['local', false],
]);

test('external provider uses the language capabilities of the runner manifest', function (): void {
    Cache::forget('transform:languages');
    config(['services.transform.runner_url' => 'http://runner.test', 'services.transform.runner_token' => 'fixture-only']);
    Http::fake(['runner.test/manifest' => Http::response(['name' => 'Runner', 'language_capabilities' => ['python', 'cobol', 'node']])]);

    expect(app(HttpTransformProvider::class)->languages())->toBe(['node', 'python'])
        ->and(app(HttpTransformProvider::class)->languages())->toBe(['node', 'python']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'http://runner.test/manifest'
        && $request->hasHeader('Authorization', 'Bearer fixture-only'));
});

test('external provider offers no languages without a valid manifest', function (mixed $response): void {
    Cache::forget('transform:languages');
    config(['services.transform.runner_url' => 'http://runner.test']);
    Http::fake(['runner.test/manifest' => $response]);

    expect(app(HttpTransformProvider::class)->languages())->toBe([]);
})->with([
    'missing endpoint' => fn () => Http::response([], 404),
    'malformed body' => fn () => Http::response(['language_capabilities' => 'php']),
    'unreachable' => fn () => fn () => throw new Illuminate\Http\Client\ConnectionException('down'),
]);

test('external provider refuses to run without a manifest', function (): void {
    Cache::forget('transform:languages');
    config(['services.transform.runner_url' => 'http://runner.test']);
    Http::fake(['runner.test/manifest' => Http::response([], 404), 'runner.test/run' => Http::response(['output' => []])]);

    expect(fn () => app(HttpTransformProvider::class)->execute('php', 'code', []))->toThrow(InvalidArgumentException::class);
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/run'));
});

test('service passes polled keys and the trmnl context to the provider', function (): void {
    $provider = fakeTransformProvider(['ok' => true]);
    $owner = User::factory()->create();
    $plugin = transformPlugin(['user_id' => $owner->id, 'configuration' => ['display_label' => 'Janus']]);

    app(TransformService::class)->transform($plugin, ['items' => [1, 2]]);

    $input = $provider->inputs[0];
    expect($input['items'])->toBe([1, 2])
        ->and($input['trmnl']['plugin_settings']['custom_fields_values'])->toBe(['display_label' => 'Janus'])
        ->and($input['trmnl']['plugin_settings']['instance_name'])->toBe($plugin->name)
        ->and($input['trmnl']['user']['name'])->toBe($owner->name)
        ->and($input['trmnl'])->not->toHaveKeys(['system', 'sensors']);
});

test('service replaces a polled trmnl key with the real context', function (): void {
    $provider = fakeTransformProvider(['ok' => true]);

    app(TransformService::class)->transform(transformPlugin(), ['trmnl' => ['system' => ['forged' => true]]]);

    expect($provider->inputs[0]['trmnl'])->not->toHaveKey('system');
});

test('service drops a top-level trmnl key from the output', function (): void {
    fakeTransformProvider(['score' => 1, 'trmnl' => ['plugin_settings' => ['custom_fields_values' => ['api_key' => 'secret']]]]);

    expect(app(TransformService::class)->transform(transformPlugin(), []))->toBe(['score' => 1]);
});

test('service rejects languages the provider cannot run', function (): void {
    fakeTransformProvider(['ok' => true]);

    expect(fn () => app(TransformService::class)->transform(transformPlugin(['transform_language' => 'python']), []))
        ->toThrow(TransformException::class, 'not available');
});

test('service surfaces failure diagnostics on the exception', function (): void {
    fakeTransformProvider(null, 'Transform process exited with code 255', 'PHP Fatal error: boom');

    try {
        app(TransformService::class)->transform(transformPlugin(), []);
        $this->fail('Expected TransformException');
    } catch (TransformException $e) {
        expect($e->getMessage())->toBe('Transform process exited with code 255')
            ->and($e->diagnostics)->toBe('PHP Fatal error: boom');
    }
});

test('external provider sends the runner contract and bearer token', function (): void {
    config([
        'services.transform.runner_url' => 'http://runner.test',
        'services.transform.runner_token' => 'fixture-only',
        'services.transform.timeout_seconds' => 5,
    ]);
    Http::fake(['runner.test/run' => Http::response(['output' => ['score' => 74]])]);

    $result = app(HttpTransformProvider::class)->execute('python', 'def run(input): return input', ['value' => 74]);

    expect($result->succeeded())->toBeTrue()->and($result->output)->toBe(['score' => 74]);
    Http::assertSent(fn ($request): bool => $request->url() === 'http://runner.test/run'
        && $request->hasHeader('Authorization', 'Bearer fixture-only')
        && $request['input'] === ['value' => 74]
        && $request['language'] === 'python'
        && $request['timeout'] === 5);
});

test('external provider rejects invalid and unsuccessful responses', function (array $body, int $status): void {
    config(['services.transform.runner_url' => 'http://runner.test']);
    Http::fake(['runner.test/run' => Http::response($body, $status)]);

    $result = app(HttpTransformProvider::class)->execute('php', 'code', []);

    expect($result->succeeded())->toBeFalse()->and($result->error)->not->toContain('private-fixture');
})->with([
    'scalar output' => [['output' => 1], 200],
    'missing output' => [['error' => 'private-fixture'], 200],
    'runner error' => [['error' => 'private-fixture'], 422],
    'redirect' => [['output' => []], 302],
]);

test('external provider reports the runner error as diagnostics', function (): void {
    config(['services.transform.runner_url' => 'http://runner.test']);
    Http::fake(['runner.test/run' => Http::response(['error' => 'transform exited non-zero: NameError'], 422)]);

    $result = app(HttpTransformProvider::class)->execute('python', 'code', []);

    expect($result->error)->toBe('Transform runner returned HTTP 422')
        ->and($result->stderr)->toBe('transform exited non-zero: NameError');
});

test('external provider rejects oversized output', function (): void {
    config(['services.transform.runner_url' => 'http://runner.test', 'services.transform.max_output_bytes' => 64]);
    Http::fake(['runner.test/run' => Http::response(['output' => ['big' => str_repeat('x', 65)]])]);

    $result = app(HttpTransformProvider::class)->execute('php', 'code', []);

    expect($result->succeeded())->toBeFalse()->and($result->error)->toContain('limit');
});

test('external provider fails without a runner url and sends nothing', function (): void {
    config(['services.transform.runner_url' => null]);
    Http::fake();

    expect(app(HttpTransformProvider::class)->execute('php', 'code', [])->succeeded())->toBeFalse();
    Http::assertNothingSent();
});

test('a display cycle with a failing transform stores the untransformed data and records the error', function (): void {
    $owner = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $owner->id]);
    $plugin = transformPlugin([
        'user_id' => $owner->id,
        'plugin_type' => 'recipe',
        'data_stale_minutes' => 1,
        'data_payload' => ['score' => 74],
        'data_payload_updated_at' => now()->subHour(),
    ]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id, 'is_active' => true, 'weekdays' => null, 'active_from' => null, 'active_until' => null]);
    PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'plugin_id' => $plugin->id, 'is_active' => true]);
    fakeTransformRunner(fn (): mixed => Http::response(['output' => 1]));
    Http::fake(['source.test' => Http::response(['score' => 0])]);

    $cycle = app(RunDeviceDisplayCycle::class);
    $result = (new ReflectionMethod($cycle, 'processPlaylist'))->invoke($cycle, $device);

    expect($result['identity'])->toBe('plugin:'.$plugin->id)
        ->and($plugin->fresh())
        ->data_payload->toBe(['score' => 0])
        ->transform_error->toContain('must return an object or array')
        ->transform_failed_at->not->toBeNull();
});
