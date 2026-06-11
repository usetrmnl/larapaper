<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function pollingTransformPlugin(array $attributes = []): Plugin
{
    return Plugin::factory()->create([
        'user_id' => User::factory()->create()->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.test/api',
        'polling_verb' => 'get',
        'data_stale_minutes' => 60,
        'transform_language' => 'node',
        'transform_code' => 'function run(input) { return input; }',
        ...$attributes,
    ]);
}

test('polling stores the runner output and sends the polled data with the trmnl context', function (): void {
    fakeTransformRunner(fn (array $input): array => ['count' => count($input['items'])]);
    Http::fake(['https://example.test/api' => Http::response(['items' => [1, 2, 3]])]);
    $plugin = pollingTransformPlugin(['configuration' => ['display_label' => 'Janus']]);

    $plugin->updateDataPayload();

    expect($plugin->fresh()->data_payload)->toBe(['count' => 3]);
    Http::assertSent(fn ($request): bool => $request->url() === 'http://runner.test/run'
        && $request['language'] === 'node'
        && $request['input']['items'] === [1, 2, 3]
        && $request['input']['trmnl']['plugin_settings']['custom_fields_values'] === ['display_label' => 'Janus']);
});

test('polling skips the transform unless the http provider is configured and the recipe has a transform language', function (?string $provider, ?string $language): void {
    fakeTransformRunner(fn (): array => ['executed' => true]);
    config(['services.transform.provider' => $provider]);
    Http::fake(['https://example.test/api' => Http::response(['raw' => true])]);
    $plugin = pollingTransformPlugin(['transform_language' => $language]);

    $plugin->updateDataPayload();

    expect($plugin->fresh()->data_payload)->toBe(['raw' => true]);
    Http::assertNotSent(fn ($request): bool => $request->url() === 'http://runner.test/run');
})->with([
    'no provider' => [null, 'node'],
    'unknown provider' => ['local', 'node'],
    'no transform language' => ['http', null],
]);

test('multiple polling urls arrive as IDX keys and array responses are wrapped in data', function (): void {
    fakeTransformRunner(fn (array $input): array => ['a' => $input['IDX_0']['a'], 'sum' => array_sum($input['IDX_1']['data'])]);
    Http::fake([
        'https://example.test/one' => Http::response(['a' => 1]),
        'https://example.test/two' => Http::response([10, 20]),
    ]);
    $plugin = pollingTransformPlugin(['polling_url' => "https://example.test/one\nhttps://example.test/two"]);

    $plugin->updateDataPayload();

    expect($plugin->fresh()->data_payload)->toBe(['a' => 1, 'sum' => 30]);
});

test('returning the input does not persist the trmnl context', function (): void {
    fakeTransformRunner();
    Http::fake(['https://example.test/api' => Http::response(['score' => 7])]);
    $plugin = pollingTransformPlugin(['configuration' => ['api_key' => 'secret']]);

    $plugin->updateDataPayload();

    expect($plugin->fresh()->data_payload)->toBe(['score' => 7]);
});

test('a failed transform stores the untransformed payload, logs and records the error', function (): void {
    Log::spy();
    fakeTransformRunner(fn (): mixed => Http::response(['error' => 'transform exited non-zero: secret detail'], 422));
    Http::fake(['https://example.test/api' => Http::response(['score' => 0])]);
    $plugin = pollingTransformPlugin([
        'data_payload' => ['score' => 74],
        'data_payload_updated_at' => now()->subHour(),
    ]);

    $plugin->updateDataPayload();

    $plugin->refresh();
    expect($plugin->data_payload)->toBe(['score' => 0])
        ->and($plugin->data_payload_updated_at->isToday())->toBeTrue()
        ->and($plugin->transform_error)->toContain('Transform runner returned HTTP 422')->toContain('secret detail')
        ->and($plugin->transform_failed_at)->not->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Transform failed')
        && $context['plugin_id'] === $plugin->id
        && ! str_contains(json_encode($context), 'secret detail'));
});

test('the first failure time is kept until a transform succeeds again', function (): void {
    $failing = true;
    fakeTransformRunner(function (array $input) use (&$failing): mixed {
        return $failing ? Http::response(['error' => 'still broken'], 422) : $input;
    });
    Http::fake(['https://example.test/api' => Http::response(['score' => 1])]);
    $since = now()->subDay();
    $plugin = pollingTransformPlugin(['transform_error' => 'old error', 'transform_failed_at' => $since]);

    $plugin->updateDataPayload();
    expect($plugin->fresh()->transform_failed_at->timestamp)->toBe($since->timestamp);

    $failing = false;
    $plugin->updateDataPayload();

    expect($plugin->fresh())
        ->transform_error->toBeNull()
        ->transform_failed_at->toBeNull();
});

test('transformed data is available as top-level liquid variables', function (): void {
    fakeTransformRunner(fn (array $input): array => json_decode($input['data']['structuredContent']['result'], true));
    Http::fake(['https://example.test/api' => Http::response(['data' => ['structuredContent' => ['result' => '{"pm25":4}']]])]);
    $plugin = pollingTransformPlugin(['markup_language' => 'liquid', 'render_markup' => '<div>{{ pm25 }} µg/m³</div>']);

    $plugin->updateDataPayload();

    expect($plugin->fresh()->render('full', false))->toContain('4 µg/m³');
});

test('saving static data stores the transform output', function (): void {
    fakeTransformRunner(fn (array $input): array => ['count' => count($input['items'])]);
    $plugin = pollingTransformPlugin(['data_strategy' => 'static', 'polling_url' => null]);

    $plugin->storeStaticDataPayload(['items' => [1, 2]]);

    expect($plugin->fresh())
        ->data_payload->toBe(['count' => 2])
        ->transform_error->toBeNull();
});

test('a failed static transform stores the untransformed data and records the error', function (): void {
    fakeTransformRunner(fn (): mixed => Http::response(['error' => 'boom'], 422));
    $plugin = pollingTransformPlugin(['data_strategy' => 'static', 'polling_url' => null]);

    $plugin->storeStaticDataPayload(['items' => [1, 2]]);

    expect($plugin->fresh())
        ->data_payload->toBe(['items' => [1, 2]])
        ->transform_error->toContain('boom')
        ->transform_failed_at->not->toBeNull();
});

test('webhook data is not transformed', function (): void {
    fakeTransformRunner(fn (): array => ['executed' => true]);
    $plugin = pollingTransformPlugin(['data_strategy' => 'webhook', 'polling_url' => null]);

    $this->postJson(route('api.custom_plugins.webhook', $plugin), ['merge_variables' => ['raw' => true]])->assertOk();

    expect($plugin->fresh()->data_payload)->toBe(['raw' => true]);
    Http::assertNothingSent();
});
