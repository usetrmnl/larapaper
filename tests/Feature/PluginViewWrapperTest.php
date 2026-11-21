<?php

declare(strict_types=1);

use App\Models\Plugin;

function makeRecipePlugin(string $markup, string $markupLanguage = 'liquid'): Plugin
{
    return Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'markup_language' => $markupLanguage,
        'render_markup' => $markup,
        'data_strategy' => 'static',
    ]);
}

test('recipe markup is wrapped in a view for the rendered size', function (string $markupLanguage, string $size): void {
    $plugin = makeRecipePlugin('<div class="layout">Hello</div>', $markupLanguage);

    expect(preg_replace('/\s+/', ' ', $plugin->render($size, false)))
        ->toBe('<div class="view view--'.$size.'"> <div class="layout">Hello</div> </div>');
})->with(['liquid', 'blade'])->with(['full', 'half_horizontal', 'half_vertical', 'quadrant']);

test('legacy view wrapper in recipe markup is neutralized', function (string $markupLanguage, string $markup): void {
    $plugin = makeRecipePlugin($markup, $markupLanguage);

    $html = $plugin->render('half_horizontal', false);

    expect(mb_substr_count($html, 'class="view '))->toBe(1)
        ->and($html)->toContain('<div class="view view--half_horizontal">')
        ->and(preg_replace('/\s+/', '', $html))->toContain('<divclass=""style="display:contents"><divclass="layout">Hello</div></div>');
})->with([
    'liquid' => ['liquid', '<div class="view view--{{ size }}"><div class="layout">Hello</div></div>'],
    'blade' => ['blade', '<x-trmnl::view size="{{ $size }}"><div class="layout">Hello</div></x-trmnl::view>'],
]);

test('legacy view wrapper keeps other classes and merges existing style', function (): void {
    $plugin = makeRecipePlugin('<div id="root" class="view view--full extra" style="color: red">x</div>');

    expect($plugin->render('full', false))
        ->toContain('<div id="root" class="extra" style="display: contents; color: red">x</div>');
});

test('classes that only resemble view are left untouched', function (): void {
    $markup = '<div class="preview view-foo">x</div><div data-class="view">y</div>';
    $plugin = makeRecipePlugin($markup);

    expect($plugin->render('full', false))->toContain($markup)
        ->and($plugin->render('full', false))->not->toContain('display: contents');
});

test('recipe views are wrapped in a single view for the rendered size', function (string $view): void {
    $plugin = Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'markup_language' => null,
        'render_markup' => null,
        'render_markup_view' => $view,
        'data_strategy' => 'static',
        'data_payload' => [],
    ]);

    $html = $plugin->render('quadrant', false);

    expect(mb_trim($html))->toStartWith('<div class="view view--quadrant">')
        ->and(mb_substr_count($html, 'class="view '))->toBe(1);
})->with(['recipes.sunrise-sunset', 'recipes.zen']);
