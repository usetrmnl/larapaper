<?php

declare(strict_types=1);

use App\Plugins\Enums\PluginOutput;
use App\Plugins\PluginContent;

test('html factory sets type and markup', function (): void {
    $content = PluginContent::html('<p>Hello</p>');

    expect($content->type)->toBe(PluginOutput::Html)
        ->and($content->html)->toBe('<p>Hello</p>')
        ->and($content->binary)->toBeNull()
        ->and($content->uuid)->toBeNull()
        ->and($content->extension)->toBeNull();
});

test('image factory lowercases extension', function (): void {
    $content = PluginContent::image('bytes', 'PNG');

    expect($content->type)->toBe(PluginOutput::Image)
        ->and($content->binary)->toBe('bytes')
        ->and($content->extension)->toBe('png')
        ->and($content->html)->toBeNull()
        ->and($content->uuid)->toBeNull();
});

test('processedImage factory lowercases extension', function (): void {
    $content = PluginContent::processedImage('550e8400-e29b-41d4-a716-446655440000', 'BMP');

    expect($content->type)->toBe(PluginOutput::ProcessedImage)
        ->and($content->uuid)->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($content->extension)->toBe('bmp')
        ->and($content->html)->toBeNull()
        ->and($content->binary)->toBeNull();
});
