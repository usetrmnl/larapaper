<?php

use App\Support\CustomFieldConditions;

test('hides fields for the matching strategy value and falls back to the default', function (): void {
    $fields = strategyConditionalFields();

    expect(visibleFieldKeys($fields, []))->toEqualCanonicalizing([
        'strategy',
        'polling_url',
        'polling_verb',
        'polling_headers',
        'polling_body',
    ])
        ->and(visibleFieldKeys($fields, ['strategy' => 'webhook']))->toEqualCanonicalizing([
            'strategy',
            'webhook_url',
        ])
        ->and(visibleFieldKeys($fields, ['strategy' => 'static']))->toEqualCanonicalizing([
            'strategy',
            'static_data',
        ]);
});

test('does not use the default when the controlling field is an empty string', function (): void {
    $fields = strategyConditionalFields();

    expect(visibleFieldKeys($fields, ['strategy' => '']))->toContain('static_data', 'webhook_url', 'polling_url');
});

test('matches a stored option label to the conditional slug', function (): void {
    $fields = strategyConditionalFields();

    expect(visibleFieldKeys($fields, ['strategy' => 'Polling']))->not->toContain('static_data', 'webhook_url')
        ->and(visibleFieldKeys($fields, ['strategy' => 'Polling']))->toContain('polling_url');
});

test('keeps a visible-list field hidden until the controlling value reveals it', function (): void {
    $fields = [
        [
            'keyname' => 'layout',
            'field_type' => 'select',
            'name' => 'Layout',
            'default' => 'default',
            'conditional_validation' => [
                ['when' => 'advanced', 'visible' => ['advanced_settings_field']],
            ],
        ],
        [
            'keyname' => 'advanced_settings_field',
            'field_type' => 'string',
            'name' => 'Advanced Settings',
            'optional' => true,
        ],
    ];

    expect(CustomFieldConditions::isVisible($fields[1], $fields, []))->toBeFalse()
        ->and(CustomFieldConditions::isVisible($fields[1], $fields, ['layout' => 'advanced']))->toBeTrue();
});

test('treats a field listed under visible as hidden by default when it is also listed under hidden', function (): void {
    $fields = [
        [
            'keyname' => 'mode',
            'field_type' => 'select',
            'name' => 'Mode',
            'default' => 'a',
            'conditional_validation' => [
                ['when' => 'a', 'hidden' => ['extra'], 'visible' => ['extra']],
                ['when' => 'b', 'hidden' => ['extra']],
            ],
        ],
        [
            'keyname' => 'extra',
            'field_type' => 'string',
            'name' => 'Extra',
            'optional' => true,
        ],
    ];

    expect(CustomFieldConditions::isVisible($fields[1], $fields, ['mode' => 'a']))->toBeTrue()
        ->and(CustomFieldConditions::isVisible($fields[1], $fields, ['mode' => 'b']))->toBeFalse();
});

test('requires only the location field selected by the data provider', function (): void {
    $fields = weatherConditionalFields();
    $latLon = $fields[1];
    $location = $fields[2];

    expect(CustomFieldConditions::isRequired($latLon, $fields, []))->toBeTrue()
        ->and(CustomFieldConditions::isRequired($location, $fields, []))->toBeFalse()
        ->and(CustomFieldConditions::isRequired($latLon, $fields, ['data_provider' => 'weatherapi']))->toBeFalse()
        ->and(CustomFieldConditions::isRequired($location, $fields, ['data_provider' => 'weatherapi']))->toBeTrue()
        ->and(CustomFieldConditions::isRequired($latLon, $fields, ['data_provider' => 'Tempest']))->toBeTrue();
});

test('does not require a hidden field', function (): void {
    $fields = strategyConditionalFields();
    $staticData = collect($fields)->firstWhere('keyname', 'static_data');

    expect(CustomFieldConditions::isRequired($staticData, $fields, ['strategy' => 'polling']))->toBeFalse()
        ->and(CustomFieldConditions::isRequired($staticData, $fields, ['strategy' => 'static']))->toBeTrue();
});

test('reveals a field when a multi-select contains the when value', function (): void {
    $fields = [
        [
            'keyname' => 'colors',
            'field_type' => 'select',
            'name' => 'Colors',
            'multiple' => true,
            'conditional_validation' => [
                ['when' => 'blue', 'visible' => ['shade']],
            ],
        ],
        [
            'keyname' => 'shade',
            'field_type' => 'string',
            'name' => 'Shade',
            'optional' => true,
        ],
    ];

    expect(CustomFieldConditions::isVisible($fields[1], $fields, ['colors' => ['red', 'blue']]))->toBeTrue()
        ->and(CustomFieldConditions::isVisible($fields[1], $fields, ['colors' => ['red']]))->toBeFalse();
});

test('matches a boolean controlling value to when true', function (): void {
    $fields = [
        [
            'keyname' => 'enabled',
            'field_type' => 'boolean',
            'name' => 'Enabled',
            'conditional_validation' => [
                ['when' => true, 'visible' => ['details']],
            ],
        ],
        [
            'keyname' => 'details',
            'field_type' => 'string',
            'name' => 'Details',
            'optional' => true,
        ],
    ];

    expect(CustomFieldConditions::isVisible($fields[1], $fields, ['enabled' => true]))->toBeTrue()
        ->and(CustomFieldConditions::isVisible($fields[1], $fields, ['enabled' => false]))->toBeFalse()
        ->and(CustomFieldConditions::isVisible($fields[1], $fields, []))->toBeFalse();
});

test('uses a boolean field default of true when the value is unset', function (): void {
    $fields = [
        [
            'keyname' => 'enabled',
            'field_type' => 'boolean',
            'name' => 'Enabled',
            'default' => true,
            'conditional_validation' => [
                ['when' => true, 'visible' => ['details']],
            ],
        ],
        [
            'keyname' => 'details',
            'field_type' => 'string',
            'name' => 'Details',
            'optional' => true,
        ],
    ];

    expect(CustomFieldConditions::isVisible($fields[1], $fields, []))->toBeTrue();
});

/**
 * @param  array<int, array<string, mixed>>  $fields
 * @param  array<string, mixed>  $configuration
 * @return list<string>
 */
function visibleFieldKeys(array $fields, array $configuration): array
{
    return array_values(array_map(
        fn (array $field): string => (string) $field['keyname'],
        array_filter(
            $fields,
            fn (array $field): bool => CustomFieldConditions::isVisible($field, $fields, $configuration),
        ),
    ));
}

/**
 * @return array<int, array<string, mixed>>
 */
function strategyConditionalFields(): array
{
    return [
        [
            'keyname' => 'strategy',
            'field_type' => 'select',
            'name' => 'Strategy',
            'default' => 'polling',
            'conditional_validation' => [
                ['when' => 'polling', 'hidden' => ['static_data', 'webhook_url']],
                ['when' => 'webhook', 'hidden' => ['polling_url', 'polling_verb', 'polling_headers', 'polling_body', 'static_data']],
                ['when' => 'static', 'hidden' => ['polling_url', 'polling_verb', 'polling_headers', 'polling_body', 'webhook_url']],
            ],
        ],
        ['keyname' => 'polling_url', 'field_type' => 'string', 'name' => 'Polling URL'],
        ['keyname' => 'polling_verb', 'field_type' => 'string', 'name' => 'Polling Verb'],
        ['keyname' => 'polling_headers', 'field_type' => 'string', 'name' => 'Polling Headers'],
        ['keyname' => 'polling_body', 'field_type' => 'string', 'name' => 'Polling Body'],
        ['keyname' => 'static_data', 'field_type' => 'string', 'name' => 'Static Data'],
        ['keyname' => 'webhook_url', 'field_type' => 'string', 'name' => 'Webhook URL'],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function weatherConditionalFields(): array
{
    return [
        [
            'keyname' => 'data_provider',
            'field_type' => 'select',
            'name' => 'Data Provider',
            'default' => 'tempest',
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
    ];
}
