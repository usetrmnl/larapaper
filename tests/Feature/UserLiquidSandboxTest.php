<?php

declare(strict_types=1);

use App\Liquid\PluginLiquidEnvironment;
use App\Models\Plugin;
use App\Models\User;
use Keepsuit\Liquid\Exceptions\LiquidException;

function liquidRecipe(string $markup, array $attributes = []): Plugin
{
    return Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'markup_language' => 'liquid',
        'render_markup' => $markup,
        ...$attributes,
    ]);
}

test('request-state tags from the laravel liquid extension are not available in recipe markup', function (string $markup): void {
    session()->put('secret_key', 'top-secret-session-value');

    expect(fn () => liquidRecipe($markup)->render(standalone: false))
        ->toThrow(LiquidException::class);
})->with([
    'session' => '{% session "secret_key" %}{{ value }}{% endsession %}',
    'env' => '{% env "testing" %}in-testing{% endenv %}',
    'auth' => '{% auth %}logged-in{% endauth %}',
    'guest' => '{% guest %}guest{% endguest %}',
    'csrf' => '{% csrf %}',
    'error' => '{% error "email" %}{{ message }}{% enderror %}',
]);

test('debug filters remain available in recipe markup', function (): void {
    $filterRegistry = PluginLiquidEnvironment::make()->filterRegistry;

    expect($filterRegistry->has('dump'))->toBeTrue()
        ->and($filterRegistry->has('dd'))->toBeTrue();
});

test('recipe markup cannot reach application config or other users', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create(['name' => 'Other Person', 'email' => 'other-person@example.com']);

    $output = liquidRecipe(<<<'LIQUID'
[{{ app.key }}][{{ config.app.key }}][{{ env.APP_KEY }}][{{ user.email }}][{{ trmnl | json }}]
LIQUID, ['user_id' => $owner->id])->render(standalone: false);

    expect($output)
        ->not->toContain((string) config('app.key'))
        ->not->toContain($otherUser->name)
        ->not->toContain($otherUser->email);
});

test('polling templates cannot read the session or view files', function (string $template): void {
    session()->put('secret_key', 'top-secret-session-value');

    $plugin = liquidRecipe('<div></div>');

    try {
        $resolved = $plugin->resolveLiquidVariables($template);
    } catch (LiquidException|InvalidArgumentException) {
        $resolved = '';
    }

    expect($resolved)
        ->not->toContain('top-secret-session-value')
        ->not->toContain('<html');
})->with([
    'session tag' => 'https://example.com/?s={% session "secret_key" %}{{ value }}{% endsession %}',
    'render view from disk' => "https://example.com/?v={% render 'welcome' %}",
]);
