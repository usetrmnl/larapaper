<?php

declare(strict_types=1);

use App\Jobs\GenerateScreenJob;
use App\Mcp\Servers\McpServer;
use App\Mcp\Tools\CreateRecipeTool;
use App\Mcp\Tools\UpdateRecipeSettingsTool;
use App\Models\Device;
use App\Models\Plugin;
use App\Models\User;
use App\Support\UserBladeExecution;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use OffloadProject\Toggle\Facades\Toggle;

test('single-user blade markup is executed', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->for($user)->create([
        'plugin_type' => 'recipe',
        'markup_language' => 'blade',
        'render_markup' => "{{ 'ok' }}",
        'data_strategy' => 'static',
    ]);

    expect($plugin->render(standalone: false))->toBe('ok');
});

test('single-user blade markup is not executed when insecure php execution is explicitly disabled', function (): void {
    config(['app.blade.allow_php_execution' => false]);

    $user = User::factory()->create();
    $plugin = Plugin::factory()->for($user)->create([
        'plugin_type' => 'recipe',
        'markup_language' => 'blade',
        'render_markup' => "{{ 'ok' }}",
        'data_strategy' => 'static',
    ]);

    expect(fn () => $plugin->render(standalone: false))
        ->toThrow(InvalidArgumentException::class, UserBladeExecution::DISABLED_MESSAGE);
});

test('blade markup is executed when another user exists and insecure php execution is enabled', function (): void {
    config(['app.blade.allow_php_execution' => true]);

    User::factory()->create();
    $user = User::factory()->create();
    $plugin = Plugin::factory()->for($user)->create([
        'plugin_type' => 'recipe',
        'markup_language' => 'blade',
        'render_markup' => "{{ 'ok' }}",
        'data_strategy' => 'static',
    ]);

    expect($plugin->render(standalone: false))->toBe('ok');
});

test('blade recipes are not executed when another user exists', function (): void {
    User::factory()->create();
    $user = User::factory()->create();
    $plugin = Plugin::factory()->for($user)->create([
        'plugin_type' => 'recipe',
        'markup_language' => 'blade',
        'render_markup' => "{{ 'ok' }}",
        'data_strategy' => 'static',
    ]);

    expect(fn () => $plugin->render(standalone: false))
        ->toThrow(InvalidArgumentException::class, UserBladeExecution::DISABLED_MESSAGE);
});

test('display update passes markup through without executing blade when another user exists', function (): void {
    Bus::fake();

    User::factory()->create();
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user, ['update-screen']);

    $markup = "<div>Hello</div>{{ 'ok' }}";

    $this->postJson('/api/display/update', [
        'device_id' => $device->id,
        'markup' => $markup,
    ])->assertOk()->assertExactJson(['message' => 'success']);

    Bus::assertDispatchedSync(GenerateScreenJob::class, function (GenerateScreenJob $job) use ($markup): bool {
        return (new ReflectionProperty(GenerateScreenJob::class, 'markup'))->getValue($job) === $markup;
    });
});

test('display update executes blade markup for a single-user instance', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user, ['update-screen']);

    $this->postJson('/api/display/update', [
        'device_id' => $device->id,
        'markup' => "{{ 'ok' }}",
    ])->assertOk();

    Bus::assertDispatchedSync(GenerateScreenJob::class, function (GenerateScreenJob $job): bool {
        return (new ReflectionProperty(GenerateScreenJob::class, 'markup'))->getValue($job) === 'ok';
    });
});

test('screen update passes markup through without executing blade when another user exists', function (): void {
    Bus::fake();

    User::factory()->create();
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'mac_address' => '00:11:22:33:44:55',
    ]);

    Sanctum::actingAs($user, ['update-screen']);

    $markup = "<div>Hello</div>{{ 'ok' }}";

    $this->withHeaders([
        'id' => $device->mac_address,
    ])->postJson('/api/screens', [
        'image' => [
            'content' => $markup,
        ],
    ])->assertOk();

    Bus::assertDispatchedSync(GenerateScreenJob::class, function (GenerateScreenJob $job) use ($markup): bool {
        return (new ReflectionProperty(GenerateScreenJob::class, 'markup'))->getValue($job) === $markup;
    });
});

test('markup playground lets users write blade but does not generate a screen when another user exists', function (): void {
    User::factory()->create();
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('plugins.markup')
        ->assertSee('Blade markup is disabled')
        ->assertSee('INSECURE_BLADE_ALLOW_PHP_EXECUTION')
        ->set('markup_code', "{{ 'ok' }}")
        ->assertSet('markup_code', "{{ 'ok' }}")
        ->set('checked_devices', [1])
        ->call('submit')
        ->assertHasErrors('generate_screen');
});

test('recipe editor can switch a recipe to blade when rendering is disabled', function (): void {
    User::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/data',
        'polling_verb' => 'get',
        'markup_language' => 'liquid',
        'render_markup' => '<div>Hi</div>',
        'data_stale_minutes' => 15,
    ]);

    $markup = "@foreach (['a'] as \$item)\n{{ \$item }}\n@endforeach";

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->set('markup_language', 'blade')
        ->set('markup_code', $markup)
        ->call('saveMarkup')
        ->assertHasNoErrors();

    $plugin->refresh();

    expect($plugin->markup_language)->toBe('blade')
        ->and($plugin->render_markup)->toBe($markup);
});

test('recipe editor keeps blade and warns when another user exists', function (): void {
    User::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/data',
        'polling_verb' => 'get',
        'markup_language' => 'blade',
        'render_markup' => '<div>Hi</div>',
        'data_stale_minutes' => 15,
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertSet('markup_language', 'blade')
        ->assertSee('Blade markup is disabled')
        ->assertSee('INSECURE_BLADE_ALLOW_PHP_EXECUTION')
        ->call('saveMarkup')
        ->assertHasNoErrors();

    expect($plugin->fresh()->markup_language)->toBe('blade');
});

test('recipe editor preview does not parse blade as liquid when blade is disabled', function (): void {
    User::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'markup_language' => 'blade',
        'render_markup' => "@foreach (['a'] as \$item)\n{{ \$item }}\n@endforeach",
        'data_stale_minutes' => 15,
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('renderPreview', 'full')
        ->assertDispatched('preview-error', fn (string $name, array $params): bool => $name === 'preview-error'
            && ($params['message'] ?? null) === UserBladeExecution::DISABLED_MESSAGE)
        ->assertNotDispatched('preview-updated');
});

test('recipe editor does not render a preview image for blade when blade is disabled', function (): void {
    User::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'markup_language' => 'blade',
        'render_markup' => '<div>Hi</div>',
        'data_stale_minutes' => 15,
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->call('renderImage')
        ->assertDispatched('preview-error', fn (string $name, array $params): bool => $name === 'preview-error'
            && ($params['message'] ?? null) === UserBladeExecution::DISABLED_MESSAGE)
        ->assertNotDispatched('preview-image-updated');
});

test('recipe editor still previews liquid when blade rendering is disabled', function (): void {
    User::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'markup_language' => 'liquid',
        'render_markup' => '<div class="title">Preview</div>',
        'data_stale_minutes' => 15,
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertDontSee('Blade markup is disabled')
        ->call('renderPreview', 'full')
        ->assertDispatched('preview-updated')
        ->assertNotDispatched('preview-error');
});

test('recipe editor treats example view recipes as blade when blade is disabled', function (): void {
    User::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'data_payload' => ['departures' => []],
        'markup_language' => null,
        'render_markup' => null,
        'render_markup_view' => 'recipes.train',
        'data_stale_minutes' => 15,
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertSet('markup_language', 'blade')
        ->assertSee('Blade markup is disabled')
        ->assertSee('INSECURE_BLADE_ALLOW_PHP_EXECUTION')
        ->call('renderPreview', 'full')
        ->assertDispatched('preview-updated')
        ->assertNotDispatched('preview-error');
});

test('mcp can store blade markup when another user exists', function (): void {
    Toggle::enable('mcp');

    User::factory()->create();
    $user = User::factory()->create();

    McpServer::actingAs($user)
        ->tool(CreateRecipeTool::class, [
            'name' => 'Blade By Default',
            'data_strategy' => 'static',
        ])
        ->assertOk()
        ->assertSee('blade');

    $recipe = Plugin::query()->where('user_id', $user->id)->where('name', 'Blade By Default')->first();

    expect($recipe)->not->toBeNull()
        ->and($recipe->markup_language)->toBe('blade');

    McpServer::actingAs($user)
        ->tool(UpdateRecipeSettingsTool::class, [
            'id' => $recipe->id,
            'markup_language' => 'liquid',
        ])
        ->assertOk();

    expect($recipe->fresh()->markup_language)->toBe('liquid');

    McpServer::actingAs($user)
        ->tool(UpdateRecipeSettingsTool::class, [
            'id' => $recipe->id,
            'markup_language' => 'blade',
        ])
        ->assertOk();

    expect($recipe->fresh()->markup_language)->toBe('blade');
});
