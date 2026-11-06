<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('recipe polling section shows transform controls when enabled', function (): void {
    config(['services.transform.enabled' => true]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com',
        'polling_verb' => 'get',
        'data_stale_minutes' => 60,
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertSee('Transform script', false);
});

test('recipe polling section hides transform controls when disabled', function (): void {
    config(['services.transform.enabled' => false]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com',
        'polling_verb' => 'get',
        'data_stale_minutes' => 60,
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertDontSee('Transform script', false);
});

test('recipe edit settings persists transform fields', function (): void {
    config(['services.transform.enabled' => true]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com',
        'polling_verb' => 'get',
        'data_stale_minutes' => 60,
        'transform_code' => null,
        'transform_language' => null,
    ]);

    $code = <<<'PHP'
<?php

function run($input)
{
    return $input;
}
PHP;

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->set('transform_active', true)
        ->set('transform_language', 'php')
        ->set('transform_code', $code)
        ->call('editSettings')
        ->assertHasNoErrors();

    $plugin->refresh();

    expect($plugin->transform_language)->toBe('php')
        ->and($plugin->transform_code)->toBe($code);
});

test('recipe insert example populates the run() template', function (): void {
    config(['services.transform.enabled' => true]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com',
        'polling_verb' => 'get',
        'data_stale_minutes' => 60,
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('renderTransformExample')
        ->assertSet('transform_code', fn ($code): bool => str_contains((string) $code, 'function run($input)'));
});

test('disabled server ignores forged transform settings', function (): void {
    config(['services.transform.enabled' => false]);
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'data_strategy' => 'polling', 'polling_url' => 'https://example.test',
        'polling_verb' => 'get', 'data_stale_minutes' => 15]);
    Livewire::test('plugins.recipe', ['plugin' => $plugin])->set('transform_active', true)
        ->set('transform_language', 'php')->set('transform_code', 'function run($input) { return []; }')
        ->call('editSettings')->assertHasNoErrors();
    expect($plugin->fresh()->transform_enabled)->toBeFalse()->and($plugin->fresh()->transform_code)->toBeNull();
});

test('local provider refuses activation of a language it cannot run', function (): void {
    config(['services.transform.enabled' => true]);
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'data_strategy' => 'polling', 'polling_url' => 'https://example.test',
        'polling_verb' => 'get', 'data_stale_minutes' => 15]);
    Livewire::test('plugins.recipe', ['plugin' => $plugin])->set('transform_active', true)
        ->set('transform_language', 'python')->set('transform_code', 'def run(input): return input')
        ->call('editSettings')->assertHasErrors('transform_language');
    expect($plugin->fresh()->transform_enabled)->toBeFalse();
});

test('recipe refresh reports syntax diagnostics to its owner without replacing cached data', function (): void {
    config(['services.transform.enabled' => true]);
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'data_strategy' => 'polling', 'polling_url' => 'https://source.test',
        'polling_verb' => 'get', 'data_stale_minutes' => 15, 'transform_enabled' => true, 'transform_language' => 'php',
        'transform_code' => 'function run($input) { syntax error here }', 'data_payload' => ['score' => 74]]);
    Illuminate\Support\Facades\Http::fake(['source.test' => Illuminate\Support\Facades\Http::response(['score' => 0])]);
    Livewire::test('plugins.recipe', ['plugin' => $plugin])->call('updateData')
        ->assertDispatched('data-update-error', fn ($event, $params) => str_contains($params['message'], 'Parse error'));
    expect($plugin->fresh()->data_payload)->toBe(['score' => 74]);
});
