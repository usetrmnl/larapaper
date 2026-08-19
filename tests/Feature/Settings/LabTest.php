<?php

use App\Models\User;
use Livewire\Livewire;
use OffloadProject\Toggle\Facades\Toggle;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    config(['toggle.driver' => 'database']);

    // Ensure we have at least one toggle in the database for testing
    // Note: Toggle::enable() will create it if it doesn't exist
    Toggle::enable('test-feature');
});

test('lab settings page is accessible', function (): void {
    $this->get(route('settings.lab'))
        ->assertOk()
        ->assertSee('Lab')
        ->assertSee('Experimental features');
});

test('can toggle feature on and off', function (): void {
    Toggle::disable('test-feature');
    expect(Toggle::active('test-feature'))->toBeFalse();

    Livewire::test('pages::settings.lab')
        ->assertSee('test-feature')
        ->call('toggle', 'test-feature', true);

    expect(Toggle::active('test-feature'))->toBeTrue();

    Livewire::test('pages::settings.lab')
        ->call('toggle', 'test-feature', false);

    expect(Toggle::active('test-feature'))->toBeFalse();
});

test('lab settings page shows mcp title and description from config', function (): void {
    Toggle::enable('mcp');

    Livewire::test('pages::settings.lab')
        ->assertSee('MCP')
        ->assertSee('Expose a Model Context Protocol server so AI tools can list, create, update, and render your recipes. Requires an MCP API token.');
});
