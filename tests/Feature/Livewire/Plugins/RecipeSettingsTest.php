<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('recipe settings can save trmnlp_id', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'trmnlp_id' => null,
    ]);

    $trmnlpId = (string) Str::uuid();

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('trmnlp_id', $trmnlpId)
        ->call('saveTrmnlpId')
        ->assertHasNoErrors();

    expect($plugin->fresh()->trmnlp_id)->toBe($trmnlpId);
});

test('recipe settings validates trmnlp_id is unique per user', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $existingPlugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'trmnlp_id' => 'existing-id-123',
    ]);

    $newPlugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'trmnlp_id' => null,
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $newPlugin])
        ->set('trmnlp_id', 'existing-id-123')
        ->call('saveTrmnlpId')
        ->assertHasErrors(['trmnlp_id' => 'unique']);

    expect($newPlugin->fresh()->trmnlp_id)->toBeNull();
});

test('recipe settings allows same trmnlp_id for different users', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $plugin1 = Plugin::factory()->create([
        'user_id' => $user1->id,
        'trmnlp_id' => 'shared-id-123',
    ]);

    $plugin2 = Plugin::factory()->create([
        'user_id' => $user2->id,
        'trmnlp_id' => null,
    ]);

    $this->actingAs($user2);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin2])
        ->set('trmnlp_id', 'shared-id-123')
        ->call('saveTrmnlpId')
        ->assertHasNoErrors();

    expect($plugin2->fresh()->trmnlp_id)->toBe('shared-id-123');
});

test('recipe settings allows same trmnlp_id for the same plugin', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $trmnlpId = (string) Str::uuid();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'trmnlp_id' => $trmnlpId,
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('trmnlp_id', $trmnlpId)
        ->call('saveTrmnlpId')
        ->assertHasNoErrors();

    expect($plugin->fresh()->trmnlp_id)->toBe($trmnlpId);
});

test('recipe settings can clear trmnlp_id', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'trmnlp_id' => 'some-id',
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('trmnlp_id', '')
        ->call('saveTrmnlpId')
        ->assertHasNoErrors();

    expect($plugin->fresh()->trmnlp_id)->toBeNull();
});

test('recipe settings saves preferred_renderer when liquid enabled and recipe is liquid', function (): void {
    config(['services.trmnl.liquid_enabled' => true]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'liquid',
        'preferred_renderer' => null,
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('use_trmnl_liquid_renderer', true)
        ->call('saveTrmnlpId')
        ->assertHasNoErrors();

    expect($plugin->fresh()->preferred_renderer)->toBe('trmnl-liquid');
});

test('recipe settings clears preferred_renderer when checkbox unchecked', function (): void {
    config(['services.trmnl.liquid_enabled' => true]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'liquid',
        'preferred_renderer' => 'trmnl-liquid',
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('use_trmnl_liquid_renderer', false)
        ->call('saveTrmnlpId')
        ->assertHasNoErrors();

    expect($plugin->fresh()->preferred_renderer)->toBeNull();
});

test('recipe settings saves configuration_template from valid YAML', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => [],
    ]);

    $yaml = "- keyname: reading_days\n  field_type: text\n  name: Reading Days\n";

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('configurationTemplateYaml', $yaml)
        ->call('saveTrmnlpId')
        ->assertHasNoErrors();

    $expected = [
        'custom_fields' => [
            [
                'keyname' => 'reading_days',
                'field_type' => 'text',
                'name' => 'Reading Days',
            ],
        ],
    ];
    expect($plugin->fresh()->configuration_template)->toBe($expected);
});

test('recipe settings validates invalid YAML', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => [],
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('configurationTemplateYaml', "foo: bar: baz\n")
        ->call('saveTrmnlpId')
        ->assertHasErrors(['configurationTemplateYaml']);

    expect($plugin->fresh()->configuration_template)->toBe([]);
});

test('recipe settings validates YAML must evaluate to object or array', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => ['custom_fields' => []],
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('configurationTemplateYaml', '123')
        ->call('saveTrmnlpId')
        ->assertHasErrors(['configurationTemplateYaml']);

    expect($plugin->fresh()->configuration_template)->toBe(['custom_fields' => []]);
});

test('recipe settings validates each custom field has field_type and name', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => [],
    ]);

    $yaml = "- keyname: only_key\n  field_type: text\n  name: Has Name\n- keyname: missing_type\n  name: No type\n";

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('configurationTemplateYaml', $yaml)
        ->call('saveTrmnlpId')
        ->assertHasErrors(['configurationTemplateYaml']);

    expect($plugin->fresh()->configuration_template)->toBeEmpty();
});

test('recipe settings saves null framework_version when input is empty', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'framework_version' => '2.3.7',
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('framework_version', '')
        ->call('saveTrmnlpId')
        ->assertHasNoErrors();

    expect($plugin->fresh()->framework_version)->toBeNull();
});

test('recipe settings saves any valid framework_version from input', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'framework_version' => null,
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('framework_version', '3.0.5')
        ->call('saveTrmnlpId')
        ->assertHasNoErrors();

    expect($plugin->fresh()->framework_version)->toBe('3.0.5');
});

test('recipe settings rejects framework_version below 2.0.0', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'framework_version' => null,
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('framework_version', '1.2.3')
        ->call('saveTrmnlpId')
        ->assertHasErrors(['framework_version']);

    expect($plugin->fresh()->framework_version)->toBeNull();
});

test('recipe settings rejects framework_version 4.0.0 and above', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'framework_version' => null,
    ]);

    Livewire::test('plugins.recipes.settings', ['plugin' => $plugin])
        ->set('framework_version', '4.0.0')
        ->call('saveTrmnlpId')
        ->assertHasErrors(['framework_version']);

    expect($plugin->fresh()->framework_version)->toBeNull();
});
