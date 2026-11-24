<?php

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Support\Str;

test('config modal correctly loads multi_string defaults into UI boxes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [[
                'keyname' => 'tags',
                'field_type' => 'multi_string',
                'name' => 'Reading Days',
                'default' => 'alpha,beta',
            ]],
        ],
        'configuration' => ['tags' => 'alpha,beta'],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSet('multiValues.tags', ['alpha', 'beta']);
});

test('config modal validates against commas in multi_string boxes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [[
                'keyname' => 'tags',
                'field_type' => 'multi_string',
                'name' => 'Reading Days',
            ]],
        ],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->set('multiValues.tags.0', 'no,commas,allowed')
        ->call('saveConfiguration')
        ->assertHasErrors(['multiValues.tags.0' => 'regex']);

    // Assert DB remains unchanged
    expect($plugin->fresh()->configuration['tags'] ?? '')->not->toBe('no,commas,allowed');
});

test('config modal merges multi_string boxes into a single CSV string on save', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [[
                'keyname' => 'items',
                'field_type' => 'multi_string',
                'name' => 'Reading Days',
            ]],
        ],
        'configuration' => [],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->set('multiValues.items.0', 'First')
        ->call('addMultiItem', 'items')
        ->set('multiValues.items.1', 'Second')
        ->call('saveConfiguration')
        ->assertHasNoErrors();

    expect($plugin->fresh()->configuration['items'])->toBe('First,Second');
});

test('config modal resetForm clears dirty state and increments resetIndex', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'data_strategy' => 'static',
        'configuration' => ['simple_key' => 'original_value'],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->set('configuration.simple_key', 'dirty_value')
        ->call('resetForm')
        ->assertSet('configuration.simple_key', 'original_value')
        ->assertSet('resetIndex', 1);
});

test('config modal dispatches update event for parent warning refresh', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'data_strategy' => 'static',
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->call('saveConfiguration')
        ->assertDispatched('config-updated');
});

test('config modal saves password field values correctly', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [[
                'keyname' => 'api_key',
                'field_type' => 'password',
                'name' => 'API Key',
            ]],
        ],
        'configuration' => [],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->set('configuration.api_key', 'my-secret-password-123')
        ->call('saveConfiguration')
        ->assertHasNoErrors();

    expect($plugin->fresh()->configuration['api_key'])->toBe('my-secret-password-123');
});

test('config modal renders lat_lon field as a coordinate input', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [[
                'keyname' => 'location',
                'field_type' => 'lat_lon',
                'name' => 'Location',
            ]],
        ],
        'configuration' => [],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSee('Location')
        ->assertSee('40.7128,-74.0060')
        ->assertDontSee('not yet supported');
});

test('config modal saves lat_lon field values correctly', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [[
                'keyname' => 'location',
                'field_type' => 'lat_lon',
                'name' => 'Location',
            ]],
        ],
        'configuration' => [],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->set('configuration.location', '48.2083537,16.3725042')
        ->call('saveConfiguration')
        ->assertHasNoErrors();

    expect($plugin->fresh()->configuration['location'])->toBe('48.2083537,16.3725042');
});

test('config modal renders purified html in field description and help text', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [[
                'keyname' => 'title',
                'field_type' => 'string',
                'name' => 'Title',
                'description' => '<strong>Hello</strong> <script>alert(1)</script>',
                'help_text' => '<em>Help</em> <script>alert(2)</script>',
            ]],
        ],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSee('<strong>Hello</strong>', false)
        ->assertSee('<em>Help</em>', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<script>alert(2)</script>', false)
        ->set('configuration_template', [
            'custom_fields' => [[
                'keyname' => 'title',
                'field_type' => 'string',
                'name' => 'Title',
                'description' => '<strong>Edited</strong> <script>alert(3)</script>',
                'help_text' => '<em>Edited help</em> <script>alert(4)</script>',
            ]],
        ])
        ->assertSee('<strong>Edited</strong>', false)
        ->assertSee('<em>Edited help</em>', false)
        ->assertDontSee('<script>alert(3)</script>', false)
        ->assertDontSee('<script>alert(4)</script>', false);
});

test('recipe page renders purified author bio html', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [[
                'keyname' => 'author_bio',
                'field_type' => 'author_bio',
                'name' => 'Author',
                'description' => '<strong>Hello</strong> <a href="https://docs.example.com">Docs</a> <script>alert(1)</script>',
                'github_url' => 'https://github.com/octocat',
                'learn_more_url' => 'https://example.com',
                'email_address' => 'author@example.com',
            ]],
        ],
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertSee('<strong>Hello</strong>', false)
        ->assertSee('href="https://docs.example.com" target="_blank" rel="noreferrer noopener"', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('octocat')
        ->assertSee('href="https://github.com/octocat"', false)
        ->assertSee('mailto:author@example.com', false)
        ->set('configuration_template', [
            'custom_fields' => [[
                'keyname' => 'author_bio',
                'field_type' => 'author_bio',
                'name' => 'Author',
                'description' => '<em>Edited</em> <a href="https://docs.example.com">Docs</a> <script>alert(1)</script>',
            ]],
        ])
        ->assertSee('<em>Edited</em>', false)
        ->assertSee('href="https://docs.example.com" target="_blank" rel="noreferrer noopener"', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

test('config modal clears cached current_image and current_image_metadata on save', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Test Plugin',
        'data_strategy' => 'static',
        'current_image' => 'cached-uuid-1234',
        'current_image_metadata' => ['width' => 800, 'height' => 480],
        'configuration_template' => [
            'custom_fields' => [[
                'keyname' => 'api_token',
                'field_type' => 'string',
                'name' => 'API Token',
            ]],
        ],
        'configuration' => ['api_token' => 'old_secret'],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->set('configuration.api_token', 'new_secret')
        ->call('saveConfiguration')
        ->assertHasNoErrors();

    $freshPlugin = $plugin->fresh();
    expect($freshPlugin->configuration['api_token'])->toBe('new_secret')
        ->and($freshPlugin->current_image)->toBeNull()
        ->and($freshPlugin->current_image_metadata)->toBeNull();
});

test('config modal shows a required badge on required field labels', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Required Field Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'api_key',
                    'field_type' => 'string',
                    'name' => 'API Key',
                ],
                [
                    'keyname' => 'nickname',
                    'field_type' => 'string',
                    'name' => 'Nickname',
                    'optional' => true,
                ],
            ],
        ],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSee('API Key')
        ->assertSee('Required')
        ->assertSee('Nickname');
});

test('config modal does not show a required badge when every field is optional', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Optional Field Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'nickname',
                    'field_type' => 'string',
                    'name' => 'Nickname',
                    'optional' => true,
                ],
            ],
        ],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSee('Nickname')
        ->assertDontSee('Required');
});

test('config modal shows a required badge on groups that contain a required field', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Required Group Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'api_key',
                    'field_type' => 'string',
                    'name' => 'API Key',
                    'group' => 'Authentication',
                ],
                [
                    'keyname' => 'environment',
                    'field_type' => 'string',
                    'name' => 'Environment',
                    'optional' => true,
                    'group' => 'Authentication',
                ],
            ],
        ],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSee('Authentication')
        ->assertSee('Required')
        ->assertSee('API Key');
});

test('config modal does not show a required badge on groups with only optional fields', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Optional Group Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'notes',
                    'field_type' => 'text',
                    'name' => 'Notes',
                    'optional' => true,
                    'group' => 'Advanced',
                ],
            ],
        ],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSee('Advanced')
        ->assertSee('Notes')
        ->assertDontSee('Required');
});

test('config modal renders collapsible group headings for grouped custom fields', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Grouped Settings Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'api_key',
                    'field_type' => 'string',
                    'name' => 'API Key',
                    'group' => 'Authentication',
                ],
                [
                    'keyname' => 'refresh_interval',
                    'field_type' => 'number',
                    'name' => 'Refresh Interval',
                    'group' => 'Display Settings',
                ],
            ],
        ],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSee('Authentication')
        ->assertSee('Display Settings')
        ->assertSee('API Key')
        ->assertSee('Refresh Interval');
});

test('config modal renders ungrouped fields outside collapsible groups', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Mixed Fields Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'general_title',
                    'field_type' => 'string',
                    'name' => 'General Title',
                ],
                [
                    'keyname' => 'api_secret',
                    'field_type' => 'password',
                    'name' => 'API Secret',
                    'group' => 'Advanced Settings',
                ],
            ],
        ],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSee('General Title')
        ->assertSee('Advanced Settings')
        ->assertSee('API Secret');
});

test('config modal saves values for fields inside groups', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Grouped Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'headline',
                    'field_type' => 'string',
                    'name' => 'Headline',
                ],
                [
                    'keyname' => 'api_key',
                    'field_type' => 'string',
                    'name' => 'API Key',
                    'group' => 'Credentials',
                ],
                [
                    'keyname' => 'tags',
                    'field_type' => 'multi_string',
                    'name' => 'Tags',
                    'group' => 'Credentials',
                ],
            ],
        ],
        'configuration' => [
            'headline' => 'Initial Headline',
            'api_key' => 'old_key',
            'tags' => 'tag1',
        ],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->set('configuration.headline', 'New Headline')
        ->set('configuration.api_key', 'new_key')
        ->set('multiValues.tags.0', 'updated_tag')
        ->call('saveConfiguration')
        ->assertDispatched('config-updated')
        ->assertHasNoErrors();

    $freshPlugin = $plugin->fresh();
    expect($freshPlugin->configuration['headline'])->toBe('New Headline')
        ->and($freshPlugin->configuration['api_key'])->toBe('new_key')
        ->and($freshPlugin->configuration['tags'])->toBe('updated_tag');
});

test('config modal defaults unset boolean fields to false and does not mark them required', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Boolean Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'enabled',
                    'field_type' => 'boolean',
                    'name' => 'Enabled',
                ],
                [
                    'keyname' => 'show_title',
                    'field_type' => 'boolean',
                    'name' => 'Show Title',
                    'default' => true,
                ],
            ],
        ],
        'configuration' => [],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSet('configuration.enabled', false)
        ->assertSet('configuration.show_title', true)
        ->assertSee('Enabled')
        ->assertSee('Show Title')
        ->assertDontSee('Required')
        ->call('saveConfiguration')
        ->assertHasNoErrors();

    expect($plugin->fresh()->configuration)->toMatchArray([
        'enabled' => false,
        'show_title' => true,
    ]);
});

test('config modal hides fields for the default strategy and reveals them when the strategy changes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Strategy Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'strategy',
                    'field_type' => 'select',
                    'name' => 'Strategy',
                    'options' => ['Polling', 'Webhook', 'Static'],
                    'default' => 'polling',
                    'conditional_validation' => [
                        ['when' => 'polling', 'hidden' => ['static_data', 'webhook_url']],
                        ['when' => 'webhook', 'hidden' => ['polling_url', 'static_data']],
                        ['when' => 'static', 'hidden' => ['polling_url', 'webhook_url']],
                    ],
                ],
                [
                    'keyname' => 'polling_url',
                    'field_type' => 'string',
                    'name' => 'Polling URL',
                    'optional' => true,
                ],
                [
                    'keyname' => 'webhook_url',
                    'field_type' => 'string',
                    'name' => 'Webhook URL',
                    'optional' => true,
                ],
                [
                    'keyname' => 'static_data',
                    'field_type' => 'string',
                    'name' => 'Static Data',
                    'optional' => true,
                ],
            ],
        ],
        'configuration' => [],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSet('configuration.strategy', 'polling')
        ->assertSee('wire:model.live="configuration.strategy"', false)
        ->assertSee('Polling URL')
        ->assertDontSee('Webhook URL')
        ->assertDontSee('Static Data')
        ->set('configuration.strategy', 'webhook')
        ->assertSee('Webhook URL')
        ->assertDontSee('Polling URL')
        ->assertDontSee('Static Data')
        ->set('configuration.strategy', 'static')
        ->assertSee('Static Data')
        ->assertDontSee('Polling URL')
        ->assertDontSee('Webhook URL');
});

test('config modal shows a required badge only on the field the data provider requires', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Weather Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'data_provider',
                    'field_type' => 'select',
                    'name' => 'Data Provider',
                    'options' => ['Tempest', 'WeatherAPI'],
                    'default' => 'tempest',
                    'optional' => true,
                    'conditional_validation' => [
                        ['when' => 'tempest', 'required' => ['lat_lon']],
                        ['when' => 'weatherapi', 'required' => ['location']],
                    ],
                ],
                [
                    'keyname' => 'lat_lon',
                    'field_type' => 'string',
                    'name' => 'Latitude and Longitude',
                    'optional' => true,
                ],
                [
                    'keyname' => 'location',
                    'field_type' => 'string',
                    'name' => 'City',
                    'optional' => true,
                ],
            ],
        ],
        'configuration' => [],
    ]);

    $component = Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSet('configuration.data_provider', 'tempest')
        ->assertSeeInOrder(['Latitude and Longitude', 'Required', 'City']);

    expect(mb_substr_count($component->html(), 'Required'))->toBe(1);

    $component->set('configuration.data_provider', 'weatherapi')
        ->assertSeeInOrder(['Latitude and Longitude', 'City', 'Required']);

    expect(mb_substr_count($component->html(), 'Required'))->toBe(1);
});

test('config modal omits a group whose fields are all hidden', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Hidden Group Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'strategy',
                    'field_type' => 'select',
                    'name' => 'Strategy',
                    'options' => ['Polling', 'Webhook'],
                    'default' => 'polling',
                    'conditional_validation' => [
                        ['when' => 'polling', 'hidden' => ['webhook_url']],
                    ],
                ],
                [
                    'keyname' => 'webhook_url',
                    'field_type' => 'string',
                    'name' => 'Webhook URL',
                    'optional' => true,
                    'group' => 'Webhook Settings',
                ],
            ],
        ],
        'configuration' => [],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertDontSee('Webhook Settings')
        ->assertDontSee('Webhook URL')
        ->set('configuration.strategy', 'webhook')
        ->assertSee('Webhook Settings')
        ->assertSee('Webhook URL');
});

test('config modal shows a required badge on a group while its visible field is conditionally required', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Conditional Group Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'mode',
                    'field_type' => 'select',
                    'name' => 'Mode',
                    'options' => ['Simple', 'Detailed', 'Plain'],
                    'default' => 'plain',
                    'optional' => true,
                    'conditional_validation' => [
                        ['when' => 'simple', 'hidden' => ['api_key']],
                        ['when' => 'detailed', 'required' => ['api_key']],
                    ],
                ],
                [
                    'keyname' => 'api_key',
                    'field_type' => 'string',
                    'name' => 'API Key',
                    'optional' => true,
                    'group' => 'Credentials',
                ],
            ],
        ],
        'configuration' => ['mode' => 'detailed'],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->assertSeeInOrder(['Credentials', 'Required', 'API Key'])
        ->set('configuration.mode', 'plain')
        ->assertSee('Credentials')
        ->assertSee('API Key')
        ->assertDontSee('Required');
});

test('config modal keeps a hidden field value when saving', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::create([
        'uuid' => Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Preserve Hidden Plugin',
        'data_strategy' => 'static',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'strategy',
                    'field_type' => 'select',
                    'name' => 'Strategy',
                    'options' => ['Polling', 'Static'],
                    'default' => 'polling',
                    'conditional_validation' => [
                        ['when' => 'polling', 'hidden' => ['static_data']],
                    ],
                ],
                [
                    'keyname' => 'polling_url',
                    'field_type' => 'string',
                    'name' => 'Polling URL',
                    'optional' => true,
                ],
                [
                    'keyname' => 'static_data',
                    'field_type' => 'string',
                    'name' => 'Static Data',
                    'optional' => true,
                ],
            ],
        ],
        'configuration' => [
            'strategy' => 'polling',
            'polling_url' => 'https://example.com/poll',
            'static_data' => '{"kept":true}',
        ],
    ]);

    Livewire::test('plugins.config-modal', ['plugin' => $plugin])
        ->set('configuration.polling_url', 'https://example.com/updated')
        ->call('saveConfiguration')
        ->assertHasNoErrors();

    expect($plugin->fresh()->configuration)->toMatchArray([
        'strategy' => 'polling',
        'polling_url' => 'https://example.com/updated',
        'static_data' => '{"kept":true}',
    ]);
});
