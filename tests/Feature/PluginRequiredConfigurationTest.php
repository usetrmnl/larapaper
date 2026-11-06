<?php

use App\Models\Plugin;
use App\Models\User;

test('hasMissingRequiredConfigurationFields returns true when required field is null', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'api_key',
                'field_type' => 'string',
                'name' => 'API Key',
                'description' => 'Your API key',
                // Not marked as optional, so it's required
            ],
            [
                'keyname' => 'timezone',
                'field_type' => 'time_zone',
                'name' => 'Timezone',
                'description' => 'Select your timezone',
                'optional' => true, // Marked as optional
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [
            'timezone' => 'UTC', // Only timezone is set, api_key is missing
        ],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeTrue();
});

test('hasMissingRequiredConfigurationFields returns false when all required fields are set', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'api_key',
                'field_type' => 'string',
                'name' => 'API Key',
                'description' => 'Your API key',
                // Not marked as optional, so it's required
            ],
            [
                'keyname' => 'timezone',
                'field_type' => 'time_zone',
                'name' => 'Timezone',
                'description' => 'Select your timezone',
                'optional' => true, // Marked as optional
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [
            'api_key' => 'test-api-key', // Required field is set
            'timezone' => 'UTC',
        ],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeFalse();
});

test('hasMissingRequiredConfigurationFields returns false when no custom fields exist', function (): void {
    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => [],
        'configuration' => [],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeFalse();
});

test('hasMissingRequiredConfigurationFields returns true when explicitly required field is null', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'api_key',
                'field_type' => 'string',
                'name' => 'API Key',
                'description' => 'Your API key',
                // Not marked as optional, so it's required
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [
            'api_key' => null, // Explicitly set to null
        ],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeTrue();
});

test('hasMissingRequiredConfigurationFields returns true when required field is empty string', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'api_key',
                'field_type' => 'string',
                'name' => 'API Key',
                'description' => 'Your API key',
                // Not marked as optional, so it's required
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [
            'api_key' => '', // Empty string
        ],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeTrue();
});

test('hasMissingRequiredConfigurationFields returns true when required array field is empty', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'selected_items',
                'field_type' => 'select',
                'name' => 'Selected Items',
                'description' => 'Select items',
                'multiple' => true,
                // Not marked as optional, so it's required
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [
            'selected_items' => [], // Empty array
        ],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeTrue();
});

test('hasMissingRequiredConfigurationFields returns false when author_bio field is present but other required field is set', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'author_bio',
                'name' => 'About This Plugin',
                'field_type' => 'author_bio',
            ],
            [
                'keyname' => 'plugin_field',
                'name' => 'Field Name',
                'field_type' => 'string',
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [
            'plugin_field' => 'set', // Required field is set
        ],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeFalse();
});

test('hasMissingRequiredConfigurationFields returns false when field has default value', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'api_key',
                'field_type' => 'string',
                'name' => 'API Key',
                'description' => 'Your API key',
                'default' => 'default-api-key', // Has default value
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [], // Empty configuration, but field has default
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeFalse();
});

test('hasMissingRequiredConfigurationFields returns true when required xhrSelect field is missing', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'team',
                'field_type' => 'xhrSelect',
                'name' => 'Baseball Team',
                'description' => 'Select your team',
                'endpoint' => config('services.trmnl.base_url').'/custom_plugin_example_xhr_select.json',
                // Not marked as optional, so it's required
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [], // Empty configuration
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeTrue();
});

test('hasMissingRequiredConfigurationFields returns false when required xhrSelect field is set', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'team',
                'field_type' => 'xhrSelect',
                'name' => 'Baseball Team',
                'description' => 'Select your team',
                'endpoint' => config('services.trmnl.base_url').'/custom_plugin_example_xhr_select.json',
                // Not marked as optional, so it's required
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [
            'team' => '123', // Required field is set
        ],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeFalse();
});

test('hasMissingRequiredConfigurationFields returns true when required multi_string field is missing', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'tags',
                'field_type' => 'multi_string',
                'name' => 'Tags',
                'description' => 'Enter tags separated by commas',
                // Not marked as optional, so it's required
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [], // Empty configuration
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeTrue();
});

test('hasMissingRequiredConfigurationFields returns false when required multi_string field is set', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'tags',
                'field_type' => 'multi_string',
                'name' => 'Tags',
                'description' => 'Enter tags separated by commas',
                // Not marked as optional, so it's required
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [
            'tags' => 'tag1, tag2, tag3', // Required field is set with comma-separated values
        ],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeFalse();
});

test('hasMissingRequiredConfigurationFields returns true when required multi_string field is empty string', function (): void {
    $user = User::factory()->create();

    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'tags',
                'field_type' => 'multi_string',
                'name' => 'Tags',
                'description' => 'Enter tags separated by commas',
                // Not marked as optional, so it's required
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => $configurationTemplate,
        'configuration' => [
            'tags' => '', // Empty string
        ],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeTrue();
});

test('hasMissingRequiredConfigurationFields ignores a required field hidden by the selected strategy', function (): void {
    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'strategy',
                    'field_type' => 'select',
                    'name' => 'Strategy',
                    'default' => 'polling',
                    'conditional_validation' => [
                        ['when' => 'polling', 'hidden' => ['static_data']],
                        ['when' => 'static', 'hidden' => ['polling_url']],
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
                ],
            ],
        ],
        'configuration' => [],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeFalse();

    $plugin->update(['configuration' => ['strategy' => 'static']]);

    expect($plugin->fresh()->hasMissingRequiredConfigurationFields())->toBeTrue();
});

test('hasMissingRequiredConfigurationFields requires the field selected by the data provider', function (): void {
    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'data_provider',
                    'field_type' => 'select',
                    'name' => 'Data Provider',
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
                    'name' => 'Latitude/Longitude',
                    'optional' => true,
                ],
                [
                    'keyname' => 'location',
                    'field_type' => 'string',
                    'name' => 'Location',
                    'optional' => true,
                ],
            ],
        ],
        'configuration' => [],
    ]);

    expect($plugin->hasMissingRequiredConfigurationFields())->toBeTrue();

    $plugin->update([
        'configuration' => [
            'data_provider' => 'weatherapi',
            'location' => 'Atlanta, GA, USA',
        ],
    ]);

    expect($plugin->fresh()->hasMissingRequiredConfigurationFields())->toBeFalse();
});
