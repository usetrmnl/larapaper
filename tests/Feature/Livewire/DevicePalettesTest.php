<?php

declare(strict_types=1);

use App\Models\DevicePalette;
use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('device palettes page can be rendered', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('device-palettes.index'))->assertOk();
});

test('component loads all device palettes on mount', function (): void {
    $user = User::factory()->create();
    $initialCount = DevicePalette::count();
    DevicePalette::create(['name' => 'palette-1', 'grays' => 2, 'framework_class' => '']);
    DevicePalette::create(['name' => 'palette-2', 'grays' => 4, 'framework_class' => '']);
    DevicePalette::create(['name' => 'palette-3', 'grays' => 16, 'framework_class' => '']);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index');

    $palettes = $component->get('devicePalettes');
    expect($palettes)->toHaveCount($initialCount + 3);
});

test('can open modal to create new device palette', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->call('openDevicePaletteModal');

    $component
        ->assertSet('editingDevicePaletteId', null)
        ->assertSet('viewingDevicePaletteId', null)
        ->assertSet('name', null)
        ->assertSet('grays', 2);
});

test('can create a new device palette', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'test-palette')
        ->set('description', 'Test Palette Description')
        ->set('grays', 16)
        ->set('colors', ['#FF0000', '#00FF00'])
        ->set('framework_class', 'TestFramework')
        ->call('saveDevicePalette');

    $component->assertHasNoErrors();

    expect(DevicePalette::where('name', 'test-palette')->exists())->toBeTrue();

    $palette = DevicePalette::where('name', 'test-palette')->first();
    expect($palette->description)->toBe('Test Palette Description');
    expect($palette->grays)->toBe(16);
    expect($palette->colors)->toBe(['#FF0000', '#00FF00']);
    expect($palette->framework_class)->toBe('TestFramework');
    expect($palette->source)->toBe('manual');
});

test('can create a grayscale-only palette without colors', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'grayscale-palette')
        ->set('grays', 256)
        ->set('colors', [])
        ->call('saveDevicePalette');

    $component->assertHasNoErrors();

    $palette = DevicePalette::where('name', 'grayscale-palette')->first();
    expect($palette->colors)->toBeNull();
    expect($palette->grays)->toBe(256);
});

test('can open modal to edit existing device palette', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'existing-palette',
        'description' => 'Existing Description',
        'grays' => 4,
        'colors' => ['#FF0000', '#00FF00'],
        'framework_class' => 'Framework',
        'source' => 'manual',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->call('openDevicePaletteModal', $palette->id);

    $component
        ->assertSet('editingDevicePaletteId', $palette->id)
        ->assertSet('name', 'existing-palette')
        ->assertSet('description', 'Existing Description')
        ->assertSet('grays', 4)
        ->assertSet('colors', ['#FF0000', '#00FF00'])
        ->assertSet('framework_class', 'Framework');
});

test('can update an existing device palette', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'original-palette',
        'grays' => 2,
        'framework_class' => '',
        'source' => 'manual',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->call('openDevicePaletteModal', $palette->id)
        ->set('name', 'updated-palette')
        ->set('description', 'Updated Description')
        ->set('grays', 16)
        ->set('colors', ['#0000FF'])
        ->call('saveDevicePalette');

    $component->assertHasNoErrors();

    $palette->refresh();
    expect($palette->name)->toBe('updated-palette');
    expect($palette->description)->toBe('Updated Description');
    expect($palette->grays)->toBe(16);
    expect($palette->colors)->toBe(['#0000FF']);
});

test('can delete a device palette', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'to-delete',
        'grays' => 2,
        'framework_class' => '',
        'source' => 'manual',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->call('deleteDevicePalette', $palette->id);

    expect(DevicePalette::find($palette->id))->toBeNull();
    $component->assertSet('devicePalettes', fn ($palettes) => $palettes->where('id', $palette->id)->isEmpty());
});

test('can duplicate a device palette', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'original-palette',
        'description' => 'Original Description',
        'grays' => 4,
        'colors' => ['#FF0000', '#00FF00'],
        'framework_class' => 'Framework',
        'source' => 'manual',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->call('duplicateDevicePalette', $palette->id);

    $component
        ->assertSet('editingDevicePaletteId', null)
        ->assertSet('name', 'original-palette_copy')
        ->assertSet('description', 'Original Description (Copy)')
        ->assertSet('grays', 4)
        ->assertSet('colors', ['#FF0000', '#00FF00'])
        ->assertSet('framework_class', 'Framework');
});

test('can add a color to the colors array', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('colorInput', '#FF0000')
        ->call('addColor');

    $component
        ->assertHasNoErrors()
        ->assertSet('colors', ['#FF0000'])
        ->assertSet('colorInput', '');
});

test('cannot add duplicate colors', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('colors', ['#FF0000'])
        ->set('colorInput', '#FF0000')
        ->call('addColor');

    $component
        ->assertHasNoErrors()
        ->assertSet('colors', ['#FF0000']);
});

test('can add multiple colors', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('colorInput', '#FF0000')
        ->call('addColor')
        ->set('colorInput', '#00FF00')
        ->call('addColor')
        ->set('colorInput', '#0000FF')
        ->call('addColor');

    $component
        ->assertSet('colors', ['#FF0000', '#00FF00', '#0000FF']);
});

test('can remove a color from the colors array', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('colors', ['#FF0000', '#00FF00', '#0000FF'])
        ->call('removeColor', 1);

    $component->assertSet('colors', ['#FF0000', '#0000FF']);
});

test('removing color reindexes array', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('colors', ['#FF0000', '#00FF00', '#0000FF'])
        ->call('removeColor', 0);

    $colors = $component->get('colors');
    expect($colors)->toBe(['#00FF00', '#0000FF']);
    expect(array_keys($colors))->toBe([0, 1]);
});

test('can open modal in view-only mode for api-sourced palette', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'api-palette',
        'grays' => 2,
        'framework_class' => '',
        'source' => 'api',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->call('openDevicePaletteModal', $palette->id, true);

    $component
        ->assertSet('viewingDevicePaletteId', $palette->id)
        ->assertSet('editingDevicePaletteId', null);
});

test('name is required when creating device palette', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('grays', 16)
        ->call('saveDevicePalette');

    $component->assertHasErrors(['name']);
});

test('name must be unique when creating device palette', function (): void {
    $user = User::factory()->create();
    DevicePalette::create([
        'name' => 'existing-name',
        'grays' => 2,
        'framework_class' => '',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'existing-name')
        ->set('grays', 16)
        ->call('saveDevicePalette');

    $component->assertHasErrors(['name']);
});

test('name can be same when updating device palette', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'original-name',
        'grays' => 2,
        'framework_class' => '',
        'source' => 'manual',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->call('openDevicePaletteModal', $palette->id)
        ->set('grays', 16)
        ->call('saveDevicePalette');

    $component->assertHasNoErrors();
});

test('grays is required when creating device palette', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'test-palette')
        ->set('grays', null)
        ->call('saveDevicePalette');

    $component->assertHasErrors(['grays']);
});

test('grays must be at least 1', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'test-palette')
        ->set('grays', 0)
        ->call('saveDevicePalette');

    $component->assertHasErrors(['grays']);
});

test('grays must be at most 256', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'test-palette')
        ->set('grays', 257)
        ->call('saveDevicePalette');

    $component->assertHasErrors(['grays']);
});

test('colors must be valid hex format', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'test-palette')
        ->set('grays', 16)
        ->set('colors', ['invalid-color', '#FF0000'])
        ->call('saveDevicePalette');

    $component->assertHasErrors(['colors.0']);
});

test('color input must be valid hex format when adding color', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('colorInput', 'invalid-color')
        ->call('addColor');

    $component->assertHasErrors(['colorInput']);
});

test('color input accepts valid hex format', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('colorInput', '#FF0000')
        ->call('addColor');

    $component->assertHasNoErrors();
});

test('color input accepts lowercase hex format', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('colorInput', '#ff0000')
        ->call('addColor');

    $component->assertHasNoErrors();
});

test('description can be null', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'test-palette')
        ->set('grays', 16)
        ->set('description', null)
        ->call('saveDevicePalette');

    $component->assertHasNoErrors();

    $palette = DevicePalette::where('name', 'test-palette')->first();
    expect($palette->description)->toBeNull();
});

test('framework class can be empty string', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'test-palette')
        ->set('grays', 16)
        ->set('framework_class', '')
        ->call('saveDevicePalette');

    $component->assertHasNoErrors();

    $palette = DevicePalette::where('name', 'test-palette')->first();
    expect($palette->framework_class)->toBe('');
});

test('empty colors array is saved as null', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'test-palette')
        ->set('grays', 16)
        ->set('colors', [])
        ->call('saveDevicePalette');

    $component->assertHasNoErrors();

    $palette = DevicePalette::where('name', 'test-palette')->first();
    expect($palette->colors)->toBeNull();
});

test('component resets form after saving', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'test-palette')
        ->set('description', 'Test Description')
        ->set('grays', 16)
        ->set('colors', ['#FF0000'])
        ->set('framework_class', 'TestFramework')
        ->call('saveDevicePalette');

    $component
        ->assertSet('name', null)
        ->assertSet('description', null)
        ->assertSet('grays', 2)
        ->assertSet('colors', [])
        ->assertSet('framework_class', '')
        ->assertSet('colorInput', '')
        ->assertSet('editingDevicePaletteId', null)
        ->assertSet('viewingDevicePaletteId', null);
});

test('component handles palette with null colors when editing', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'grayscale-palette',
        'grays' => 2,
        'colors' => null,
        'framework_class' => '',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->call('openDevicePaletteModal', $palette->id);

    $component->assertSet('colors', []);
});

test('component handles palette with string colors when editing', function (): void {
    $user = User::factory()->create();
    $palette = DevicePalette::create([
        'name' => 'string-colors-palette',
        'grays' => 2,
        'framework_class' => '',
    ]);
    // Manually set colors as JSON string to simulate edge case
    $palette->setRawAttributes(array_merge($palette->getAttributes(), [
        'colors' => json_encode(['#FF0000', '#00FF00']),
    ]));
    $palette->save();

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->call('openDevicePaletteModal', $palette->id);

    $component->assertSet('colors', ['#FF0000', '#00FF00']);
});

test('component refreshes palette list after creating', function (): void {
    $user = User::factory()->create();
    $initialCount = DevicePalette::count();
    DevicePalette::create(['name' => 'palette-1', 'grays' => 2, 'framework_class' => '']);
    DevicePalette::create(['name' => 'palette-2', 'grays' => 4, 'framework_class' => '']);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->set('name', 'new-palette')
        ->set('grays', 16)
        ->call('saveDevicePalette');

    $palettes = $component->get('devicePalettes');
    expect($palettes)->toHaveCount($initialCount + 3);
    expect(DevicePalette::count())->toBe($initialCount + 3);
});

test('component refreshes palette list after deleting', function (): void {
    $user = User::factory()->create();
    $initialCount = DevicePalette::count();
    $palette1 = DevicePalette::create([
        'name' => 'palette-1',
        'grays' => 2,
        'framework_class' => '',
        'source' => 'manual',
    ]);
    $palette2 = DevicePalette::create([
        'name' => 'palette-2',
        'grays' => 2,
        'framework_class' => '',
        'source' => 'manual',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('device-palettes.index')
        ->call('deleteDevicePalette', $palette1->id);

    $palettes = $component->get('devicePalettes');
    expect($palettes)->toHaveCount($initialCount + 1);
    expect(DevicePalette::count())->toBe($initialCount + 1);
});

test('update from API runs job and refreshes device palettes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Http::fake([
        'usetrmnl.com/api/palettes' => Http::response([
            'data' => [
                [
                    'id' => 'api-palette',
                    'name' => 'API Palette',
                    'grays' => 4,
                    'colors' => null,
                    'framework_class' => '',
                ],
            ],
        ], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response(['data' => []], 200),
    ]);

    $component = Livewire::test('device-palettes.index')
        ->call('updateFromApi');

    $devicePalettes = $component->get('devicePalettes');
    expect($devicePalettes->pluck('name')->toArray())->toContain('api-palette');
});
