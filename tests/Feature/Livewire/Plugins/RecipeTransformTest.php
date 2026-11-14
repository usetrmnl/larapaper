<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    fakeTransformRunner(languages: ['php', 'node']);
});

function transformRecipe(array $attributes = []): Plugin
{
    $user = User::factory()->create();
    test()->actingAs($user);

    return Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com',
        'polling_verb' => 'get',
        'data_stale_minutes' => 60,
        'transform_code' => null,
        'transform_language' => null,
        ...$attributes,
    ]);
}

test('the plus menu offers the transform tab for polling and static recipes with the http provider', function (?string $provider, string $strategy, bool $offered): void {
    config(['services.transform.provider' => $provider]);

    $component = Livewire::test('plugins.recipe', ['plugin' => transformRecipe(['data_strategy' => $strategy, 'data_payload' => []])]);

    $offered ? $component->assertSeeHtml('wire:click="toggleTransformTab"') : $component->assertDontSeeHtml('wire:click="toggleTransformTab"');
})->with([
    'polling' => ['http', 'polling', true],
    'static' => ['http', 'static', true],
    'webhook' => ['http', 'webhook', false],
    'no provider' => [null, 'polling', true],
]);

test('adding the transform tab inserts the example and saving persists it', function (): void {
    $plugin = transformRecipe();

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('toggleTransformTab')
        ->assertSet('active_tab', 'transform')
        ->assertSet('transform_language', 'php')
        ->assertSet('transform_code', fn (?string $code): bool => str_contains((string) $code, "\$input['transformed_at']"))
        ->assertSee('Transform script', false)
        ->call('saveMarkup')
        ->assertHasNoErrors();

    expect($plugin->fresh())
        ->transform_language->toBe('php')
        ->transform_code->toContain('function run(array $input)');
});

test('removing the transform tab clears the language but keeps the code', function (): void {
    $plugin = transformRecipe(['transform_language' => 'php', 'transform_code' => '<?php function run($input) { return $input; }']);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('switchTab', 'transform')
        ->call('toggleTransformTab')
        ->assertSet('active_tab', 'full')
        ->call('saveMarkup')
        ->assertHasNoErrors()
        ->call('toggleTransformTab')
        ->assertSet('transform_language', 'php');

    expect($plugin->fresh())
        ->transform_language->toBeNull()
        ->transform_code->toBe('<?php function run($input) { return $input; }');
});

test('switching language swaps an untouched example', function (): void {
    Livewire::test('plugins.recipe', ['plugin' => transformRecipe()])
        ->call('toggleTransformTab')
        ->set('transform_language', 'python')
        ->assertSet('transform_code', fn (?string $code): bool => str_contains((string) $code, 'def run(input):'))
        ->set('transform_code', 'def run(input): return {"custom": True}')
        ->set('transform_language', 'php')
        ->assertSet('transform_code', 'def run(input): return {"custom": True}');
});

test('saving a transform requires code', function (): void {
    $plugin = transformRecipe();

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('toggleTransformTab')
        ->set('transform_code', '')
        ->call('saveMarkup')
        ->assertHasErrors('transform_code');

    expect($plugin->fresh()->transform_language)->toBeNull();
});

test('a language the provider cannot run can be edited and saved with a callout', function (string $language): void {
    $plugin = transformRecipe(['transform_language' => $language, 'transform_code' => 'def run(input) input end']);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('switchTab', 'transform')
        ->assertSee('is not supported by the', false)
        ->assertSee('transform runner', false)
        ->assertSeeHtml('value="ruby"')
        ->set('transform_code', 'def run(input) {} end')
        ->call('saveMarkup')
        ->assertHasNoErrors();

    expect($plugin->fresh()->transform_code)->toBe('def run(input) {} end');
})->with(['ruby', 'python']);

test('the editor reports a runner without a manifest', function (): void {
    fakeTransformRunner(languages: []);

    Livewire::test('plugins.recipe', ['plugin' => transformRecipe(['transform_language' => 'php', 'transform_code' => '<?php function run($input) { return $input; }'])])
        ->call('switchTab', 'transform')
        ->assertSee('The transform runner is not available', false)
        ->assertDontSee('is not supported by the', false);
});

test('running a language the provider cannot run reports it', function (): void {
    Http::fake(['https://example.com' => Http::response(['a' => 1])]);

    Livewire::test('plugins.recipe', ['plugin' => transformRecipe(['transform_language' => 'ruby', 'transform_code' => 'def run(input) input end'])])
        ->call('runTransform')
        ->assertSet('transform_run.error', fn (?string $error): bool => str_contains((string) $error, 'not available'));
});

test('re-adding the tab recognises the language of kept code', function (string $code, string $language): void {
    Livewire::test('plugins.recipe', ['plugin' => transformRecipe(['transform_code' => $code])])
        ->call('toggleTransformTab')
        ->assertSet('transform_language', $language);
})->with([
    'php' => ['<?php function run($input) { return $input; }', 'php'],
    'python' => ["def run(input):\n    return input", 'python'],
    'ruby' => ["def run(input)\n  input\nend", 'ruby'],
    'javascript' => ['function run(input) { return input; }', 'node'],
]);

test('without the http provider the transform stays visible and editable with a hint', function (): void {
    config(['services.transform.provider' => null]);
    $plugin = transformRecipe(['transform_language' => 'php', 'transform_code' => 'function run($input) { return []; }']);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('switchTab', 'transform')
        ->assertSet('active_tab', 'transform')
        ->assertSee('No Transform Provider available', false)
        ->assertSeeHtml('https://usetrmnl.github.io/larapaper/usage/transform')
        ->set('transform_code', 'function run($input) { return $input; }')
        ->call('saveMarkup')
        ->assertHasNoErrors();

    expect($plugin->fresh())
        ->transform_language->toBe('php')
        ->transform_code->toBe('function run($input) { return $input; }');
});

test('run shows the output of the unsaved code without storing it', function (): void {
    fakeTransformRunner(fn (array $input, string $code): array => ['count' => count($input['items']), 'code' => $code]);
    Http::fake(['https://example.com' => Http::response(['items' => [1, 2, 3]])]);
    $plugin = transformRecipe(['data_payload' => ['old' => true]]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('toggleTransformTab')
        ->set('transform_code', '<?php function run($input) { return $input; }')
        ->call('runTransform')
        ->assertSet('transform_run.error', null)
        ->assertSet('transform_run.output', fn (string $output): bool => json_decode($output, true) === ['count' => 3, 'code' => '<?php function run($input) { return $input; }'])
        ->assertSee('Result');

    expect($plugin->fresh())
        ->data_payload->toBe(['old' => true])
        ->transform_language->toBeNull();
});

test('run shows transform errors with diagnostics', function (): void {
    fakeTransformRunner(fn (): mixed => Http::response(['error' => 'transform exited non-zero: upstream changed'], 422));
    Http::fake(['https://example.com' => Http::response(['a' => 1])]);

    Livewire::test('plugins.recipe', ['plugin' => transformRecipe()])
        ->call('toggleTransformTab')
        ->call('runTransform')
        ->assertSet('transform_run.error', fn (?string $error): bool => filled($error))
        ->assertSet('transform_run.logs', fn (string $logs): bool => str_contains($logs, 'upstream changed'));
});

test('run gives a static transform only the trmnl context', function (): void {
    fakeTransformRunner(fn (array $input): array => ['keys' => array_keys($input)]);

    Livewire::test('plugins.recipe', ['plugin' => transformRecipe(['data_strategy' => 'static', 'data_payload' => ['items' => [1]]])])
        ->call('toggleTransformTab')
        ->call('runTransform')
        ->assertSet('transform_run.output', fn (string $output): bool => json_decode($output, true) === ['keys' => ['trmnl']]);
});

test('saving a static transform recipe keeps the transform output', function (): void {
    fakeTransformRunner(fn (): array => ['executed' => true]);
    $plugin = transformRecipe([
        'data_strategy' => 'static',
        'data_payload' => ['count' => 2],
        'transform_language' => 'node',
        'transform_code' => 'function run(input) { return { count: 2 }; }',
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->set('data_payload', '{"edited": true}')
        ->set('name', 'Renamed')
        ->call('editSettings')
        ->assertHasNoErrors();

    expect($plugin->fresh())
        ->name->toBe('Renamed')
        ->data_payload->toBe(['count' => 2]);
    Http::assertNotSent(fn ($request): bool => $request->url() === 'http://runner.test/run');
});

test('refreshing a static transform runs it and shows a failure while keeping the last output', function (): void {
    fakeTransformRunner(fn (): mixed => Http::response(['error' => 'static boom'], 422));
    $plugin = transformRecipe([
        'data_strategy' => 'static',
        'data_payload' => ['count' => 2],
        'transform_language' => 'node',
        'transform_code' => 'function run(input) { throw new Error("static boom"); }',
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('updateData')
        ->assertNotDispatched('data-update-error')
        ->assertSee('Transform failing since', false)
        ->assertSee('static boom', false)
        ->assertSet('data_payload', fn (string $payload): bool => json_decode($payload, true) === ['count' => 2]);
});

test('run is forbidden without the http provider', function (): void {
    config(['services.transform.provider' => null]);

    Livewire::test('plugins.recipe', ['plugin' => transformRecipe()])
        ->set('transform_language', 'php')
        ->set('transform_code', '<?php function run($input) { return $input; }')
        ->call('runTransform')
        ->assertForbidden();
});

test('a failed transform refresh shows the error and keeps the raw data visible', function (): void {
    fakeTransformRunner(fn (): mixed => Http::response(['error' => 'transform exited non-zero: upstream changed'], 422));
    Http::fake(['https://example.com' => Http::response(['a' => 1])]);
    $plugin = transformRecipe([
        'transform_language' => 'php',
        'transform_code' => 'function run($input) { throw new RuntimeException("upstream changed"); }',
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('updateData')
        ->assertNotDispatched('data-update-error')
        ->assertSee('Transform failing since', false)
        ->assertSee('upstream changed', false)
        ->assertSee('Untransformed', false)
        ->assertSet('data_payload', fn (string $payload): bool => json_decode($payload, true) === ['a' => 1]);
});
