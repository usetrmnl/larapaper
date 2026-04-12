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

it('shows the flux segmented device admin nav on the device palettes page', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('device-palettes.index'));

    $response->assertSuccessful();
    $response->assertSee('data-flux-radio-group', false);
    $response->assertSee('wire:model.live="section"', false);
});

it('redirects when the device admin segment changes', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('device-resource-nav')
        ->set('section', 'device-models')
        ->assertRedirect(route('device-models.index'));
});
