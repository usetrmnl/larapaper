<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

it('shows the flux segmented device admin nav on the devices page', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('devices'));

    $response->assertSuccessful();
    $response->assertSee('data-flux-radio-group', false);
    $response->assertSee('wire:model.live="section"', false);
});

it('shows the flux segmented device admin nav on the device models page', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('device-models.index'));

    $response->assertSuccessful();
    $response->assertSee('data-flux-radio-group', false);
    $response->assertSee('wire:model.live="section"', false);
});

it('marks the app header devices nav item current on the device models page', function (): void {
    $user = User::factory()->create();
    $devicesHref = preg_quote(route('devices'), '/');
    $html = $this->actingAs($user)->get(route('device-models.index'))->getContent();

    $currentAttr = 'data-current="data-current"';
    $matches = preg_match('/<a[^>]+href="'.$devicesHref.'"[^>]*\b'.preg_quote($currentAttr, '/').'/', $html) === 1
        || preg_match('/<a[^>]*\b'.preg_quote($currentAttr, '/').'[^>]+href="'.$devicesHref.'"/', $html) === 1;

    expect($matches)->toBeTrue();
});

it('shows the flux segmented device admin nav on the device palettes page', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('device-palettes.index'));

    $response->assertSuccessful();
    $response->assertSee('data-flux-radio-group', false);
    $response->assertSee('wire:model.live="section"', false);
});

it('marks the app header devices nav item current on the device palettes page', function (): void {
    $user = User::factory()->create();
    $devicesHref = preg_quote(route('devices'), '/');
    $html = $this->actingAs($user)->get(route('device-palettes.index'))->getContent();

    $currentAttr = 'data-current="data-current"';
    $matches = preg_match('/<a[^>]+href="'.$devicesHref.'"[^>]*\b'.preg_quote($currentAttr, '/').'/', $html) === 1
        || preg_match('/<a[^>]*\b'.preg_quote($currentAttr, '/').'[^>]+href="'.$devicesHref.'"/', $html) === 1;

    expect($matches)->toBeTrue();
});

it('redirects when the device admin segment changes', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('device-resource-nav')
        ->set('section', 'device-models')
        ->assertRedirect(route('device-models.index'));
});
