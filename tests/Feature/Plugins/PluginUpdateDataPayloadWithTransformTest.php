<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('polling stores transformed payload when transform is configured', function (): void {
    config(['services.transform.enabled' => true]);

    Http::fake([
        'https://example.test/api' => Http::response(['items' => [1, 2, 3]], 200),
    ]);

    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.test/api',
        'polling_verb' => 'get',
        'polling_header' => null,
        'polling_body' => null,
        'data_stale_minutes' => 60,
        'transform_language' => 'php',
        'transform_code' => <<<'PHP'
<?php

function run($input)
{
    return ['count' => count($input['items'] ?? [])];
}
PHP,
    ]);

    $plugin->loadMissing('user');
    $plugin->updateDataPayload();

    expect($plugin->fresh()->data_payload)->toBe(['count' => 3]);
});

test('polling with php transform succeeds for a large polled payload', function (): void {
    config(['services.transform.enabled' => true]);
    config(['services.transform.timeout_seconds' => 30]);

    $large = str_repeat('x', 1_500_000);
    Http::fake([
        'https://example.test/heavy' => Http::response(['blob' => $large], 200),
    ]);

    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.test/heavy',
        'polling_verb' => 'get',
        'polling_header' => null,
        'polling_body' => null,
        'data_stale_minutes' => 60,
        'transform_language' => 'php',
        'transform_code' => <<<'PHP'
<?php

function run($input)
{
    return [
        'ok' => true,
        'blob_len' => strlen($input['blob'] ?? ''),
    ];
}
PHP,
    ]);

    $plugin->loadMissing('user');
    $plugin->updateDataPayload();

    $payload = $plugin->fresh()->data_payload;

    expect($payload)->toBeArray()
        ->and($payload['ok'] ?? null)->toBeTrue()
        ->and($payload['blob_len'] ?? null)->toBe(1_500_000);
});

test('polling skips transform when feature is disabled', function (): void {
    config(['services.transform.enabled' => false]);

    Http::fake([
        'https://example.test/api' => Http::response(['hello' => 'world'], 200),
    ]);

    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.test/api',
        'polling_verb' => 'get',
        'data_stale_minutes' => 60,
        'transform_language' => 'php',
        'transform_code' => <<<'PHP'
<?php

function run($input)
{
    return ['should_not' => 'run'];
}
PHP,
    ]);

    $plugin->loadMissing('user');
    $plugin->updateDataPayload();

    expect($plugin->fresh()->data_payload)->toBe(['hello' => 'world']);
});

test('polling stores an error payload when the transform fails', function (): void {
    config(['services.transform.enabled' => true]);

    Http::fake([
        'https://example.test/api' => Http::response(['hello' => 'world'], 200),
    ]);

    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.test/api',
        'polling_verb' => 'get',
        'data_stale_minutes' => 60,
        'transform_language' => 'php',
        'transform_code' => <<<'PHP'
<?php

function run($input)
{
    return 'not-an-array';
}
PHP,
    ]);

    $plugin->loadMissing('user');
    $plugin->updateDataPayload();

    expect($plugin->fresh()->data_payload)->toHaveKey('error');
});
