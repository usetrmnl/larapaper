<?php

declare(strict_types=1);

use App\Models\Plugin;
use Illuminate\Support\Facades\Vite;

test('loads the built font stylesheet in the admin UI and rendered screens', function (string $target): void {
    $html = $target === 'admin'
        ? view('partials.head')->render()
        : Plugin::factory()->create([
            'plugin_type' => 'recipe',
            'markup_language' => 'blade',
            'render_markup' => '<div>Hello</div>',
        ])->render();

    $fontStylesheet = Vite::asset('resources/css/fonts.css');

    expect($fontStylesheet)->toStartWith('http')
        ->and($html)->toContain('href="'.$fontStylesheet.'"')
        ->not->toContain('fonts.bunny.net');

    if ($target === 'screen') {
        expect($html)->not->toContain(Vite::asset('resources/css/app.css'));
    }
})->with(['admin', 'screen']);
