<?php

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('clearCurrentImage nulls current_image and current_image_metadata', function (): void {
    $plugin = Plugin::factory()->create([
        'current_image' => 'cached-uuid',
        'current_image_metadata' => ['width' => 800, 'height' => 480],
    ]);

    $plugin->clearCurrentImage();

    $plugin->refresh();
    expect($plugin->current_image)->toBeNull();
    expect($plugin->current_image_metadata)->toBeNull();
});

test('plugin has required attributes', function (): void {
    $plugin = Plugin::factory()->create([
        'name' => 'Test Plugin',
        'data_payload' => ['key' => 'value'],
    ]);

    expect($plugin)
        ->name->toBe('Test Plugin')
        ->data_payload->toBe(['key' => 'value'])
        ->uuid->toBeString()
        ->uuid->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

test('plugin automatically generates uuid on creation', function (): void {
    $plugin = Plugin::factory()->create();

    expect($plugin->uuid)
        ->toBeString()
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

test('plugin can have custom uuid', function (): void {
    $uuid = Illuminate\Support\Str::uuid();
    $plugin = Plugin::factory()->create(['uuid' => $uuid]);

    expect($plugin->uuid)->toBe($uuid);
});

test('plugin data_payload is cast to array', function (): void {
    $data = ['key' => 'value'];
    $plugin = Plugin::factory()->create(['data_payload' => $data]);

    expect($plugin->data_payload)
        ->toBeArray()
        ->toBe($data);
});

test('plugin can have polling body for POST requests', function (): void {
    $plugin = Plugin::factory()->create([
        'polling_verb' => 'post',
        'polling_body' => '{"query": "query { user { id name } }"}',
    ]);

    expect($plugin->polling_body)->toBe('{"query": "query { user { id name } }"}');
});

test('updateDataPayload sends POST request with body when polling_verb is post', function (): void {
    Http::fake([
        'https://example.com/api' => Http::response(['success' => true], 200),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/api',
        'polling_verb' => 'post',
        'polling_body' => '{"query": "query { user { id name } }"}',
    ]);

    $plugin->updateDataPayload();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/api' &&
           $request->method() === 'POST' &&
           $request->body() === '{"query": "query { user { id name } }"}');
});

test('updateDataPayload sends POST request with body with correct content type when not JSON content', function (): void {
    Http::fake([
        'https://example.com/api' => Http::response(['success' => true], 200),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/api',
        'polling_verb' => 'post',
        'polling_body' => '<query><user id="123" name="John Doe"/></query>',
        'polling_header' => 'Content-Type: text/xml',
    ]);

    $plugin->updateDataPayload();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/api' &&
           $request->method() === 'POST' &&
           $request->hasHeader('Content-Type', 'text/xml') &&
           $request->body() === '<query><user id="123" name="John Doe"/></query>');
});

test('updateDataPayload handles multiple URLs with IDX_ prefixes', function (): void {
    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => "https://api1.example.com/data\nhttps://api2.example.com/weather\nhttps://api3.example.com/news",
        'polling_verb' => 'get',
        'configuration' => [
            'api_key' => 'test123',
        ],
    ]);

    // Mock HTTP responses
    Http::fake([
        'https://api1.example.com/data' => Http::response(['data' => 'test1'], 200),
        'https://api2.example.com/weather' => Http::response(['temp' => 25], 200),
        'https://api3.example.com/news' => Http::response(['headline' => 'test'], 200),
    ]);

    $plugin->updateDataPayload();

    expect($plugin->data_payload)->toHaveKey('IDX_0');
    expect($plugin->data_payload)->toHaveKey('IDX_1');
    expect($plugin->data_payload)->toHaveKey('IDX_2');
    expect($plugin->data_payload['IDX_0'])->toBe(['data' => 'test1']);
    expect($plugin->data_payload['IDX_1'])->toBe(['temp' => 25]);
    expect($plugin->data_payload['IDX_2'])->toBe(['headline' => 'test']);
});

test('updateDataPayload skips empty lines in polling_url and maintains sequential IDX keys', function (): void {
    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        // empty lines and extra spaces between the URL to generate empty entries
        'polling_url' => "https://api1.example.com/data\n  \n\nhttps://api2.example.com/weather\n ",
        'polling_verb' => 'get',
    ]);

    // Mock only the valid URLs
    Http::fake([
        'https://api1.example.com/data' => Http::response(['item' => 'first'], 200),
        'https://api2.example.com/weather' => Http::response(['item' => 'second'], 200),
    ]);

    $plugin->updateDataPayload();

    // payload should only have 2 items, and they should be indexed 0 and 1
    expect($plugin->data_payload)->toHaveCount(2);
    expect($plugin->data_payload)->toHaveKey('IDX_0');
    expect($plugin->data_payload)->toHaveKey('IDX_1');

    // data is correct
    expect($plugin->data_payload['IDX_0'])->toBe(['item' => 'first']);
    expect($plugin->data_payload['IDX_1'])->toBe(['item' => 'second']);

    // no empty index exists
    expect($plugin->data_payload)->not->toHaveKey('IDX_2');
});

test('updateDataPayload handles single URL without nesting', function (): void {
    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://api.example.com/data',
        'polling_verb' => 'get',
        'configuration' => [
            'api_key' => 'test123',
        ],
    ]);

    // Mock HTTP response
    Http::fake([
        'https://api.example.com/data' => Http::response(['data' => 'test'], 200),
    ]);

    $plugin->updateDataPayload();

    expect($plugin->data_payload)->toBe(['data' => 'test']);
    expect($plugin->data_payload)->not->toHaveKey('IDX_0');
});

test('updateDataPayload resolves Liquid variables in polling_header', function (): void {
    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://api.example.com/data',
        'polling_verb' => 'get',
        'polling_header' => "Authorization: Bearer {{ api_key }}\nX-Custom-Header: {{ custom_value }}",
        'configuration' => [
            'api_key' => 'test123',
            'custom_value' => 'custom_header_value',
        ],
    ]);

    // Mock HTTP response
    Http::fake([
        'https://api.example.com/data' => Http::response(['data' => 'test'], 200),
    ]);

    $plugin->updateDataPayload();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.example.com/data' &&
           $request->method() === 'GET' &&
           $request->header('Authorization')[0] === 'Bearer test123' &&
           $request->header('X-Custom-Header')[0] === 'custom_header_value');
});

test('updateDataPayload resolves Liquid variables in polling_body', function (): void {
    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => 'https://api.example.com/data',
        'polling_verb' => 'post',
        'polling_body' => '{"query": "query { user { id name } }", "api_key": "{{ api_key }}", "user_id": "{{ user_id }}"}',
        'configuration' => [
            'api_key' => 'test123',
            'user_id' => '456',
        ],
    ]);

    // Mock HTTP response
    Http::fake([
        'https://api.example.com/data' => Http::response(['data' => 'test'], 200),
    ]);

    $plugin->updateDataPayload();

    Http::assertSent(function ($request): bool {
        $expectedBody = '{"query": "query { user { id name } }", "api_key": "test123", "user_id": "456"}';

        return $request->url() === 'https://api.example.com/data' &&
               $request->method() === 'POST' &&
               $request->body() === $expectedBody;
    });
});

test('webhook plugin is stale if webhook event occurred', function (): void {
    $plugin = Plugin::factory()->create([
        'data_strategy' => 'webhook',
        'data_payload_updated_at' => now()->subMinutes(10),
        'data_stale_minutes' => 60, // Should be ignored for webhook
    ]);

    expect($plugin->isDataStale())->toBeTrue();

});

test('webhook plugin data not stale if no webhook event occurred for 1 hour', function (): void {
    $plugin = Plugin::factory()->create([
        'data_strategy' => 'webhook',
        'data_payload_updated_at' => now()->subMinutes(60),
        'data_stale_minutes' => 60, // Should be ignored for webhook
    ]);

    expect($plugin->isDataStale())->toBeFalse();

});

test('plugin configuration is cast to array', function (): void {
    $config = ['timezone' => 'UTC', 'refresh_interval' => 30];
    $plugin = Plugin::factory()->create(['configuration' => $config]);

    expect($plugin->configuration)
        ->toBeArray()
        ->toBe($config);
});

test('plugin can get configuration value by key', function (): void {
    $config = ['timezone' => 'UTC', 'refresh_interval' => 30];
    $plugin = Plugin::factory()->create(['configuration' => $config]);

    expect($plugin->getConfiguration('timezone'))->toBe('UTC');
    expect($plugin->getConfiguration('refresh_interval'))->toBe(30);
    expect($plugin->getConfiguration('nonexistent', 'default'))->toBe('default');
});

test('plugin configuration template is cast to array', function (): void {
    $template = [
        'custom_fields' => [
            [
                'name' => 'Timezone',
                'keyname' => 'timezone',
                'field_type' => 'time_zone',
                'description' => 'Select your timezone',
            ],
        ],
    ];
    $plugin = Plugin::factory()->create(['configuration_template' => $template]);

    expect($plugin->configuration_template)
        ->toBeArray()
        ->toBe($template);
});

test('resolveLiquidVariables resolves variables from configuration', function (): void {
    $plugin = Plugin::factory()->create([
        'configuration' => [
            'api_key' => '12345',
            'username' => 'testuser',
            'count' => 42,
        ],
    ]);

    // Test simple variable replacement
    $template = 'API Key: {{ api_key }}';
    $result = $plugin->resolveLiquidVariables($template);
    expect($result)->toBe('API Key: 12345');

    // Test multiple variables
    $template = 'User: {{ username }}, Count: {{ count }}';
    $result = $plugin->resolveLiquidVariables($template);
    expect($result)->toBe('User: testuser, Count: 42');

    // Test with missing variable (should keep original)
    $template = 'Missing: {{ missing }}';
    $result = $plugin->resolveLiquidVariables($template);
    expect($result)->toBe('Missing: ');

    // Test with Liquid control structures
    $template = '{% if count > 40 %}High{% else %}Low{% endif %}';
    $result = $plugin->resolveLiquidVariables($template);
    expect($result)->toBe('High');
});

test('resolveLiquidVariables handles invalid Liquid syntax gracefully', function (): void {
    $plugin = Plugin::factory()->create([
        'configuration' => [
            'api_key' => '12345',
        ],
    ]);

    // Test with unclosed Liquid tag (should throw exception)
    $template = 'Unclosed tag: {{ config.api_key';

    expect(fn () => $plugin->resolveLiquidVariables($template))
        ->toThrow(Keepsuit\Liquid\Exceptions\SyntaxException::class);
});

test('plugin can extract default values from custom fields configuration template', function (): void {
    $configurationTemplate = [
        'custom_fields' => [
            [
                'keyname' => 'reading_days',
                'field_type' => 'string',
                'name' => 'Reading Days',
                'description' => 'Select days of the week to read',
                'default' => 'Monday,Friday,Saturday,Sunday',
            ],
            [
                'keyname' => 'refresh_interval',
                'field_type' => 'number',
                'name' => 'Refresh Interval',
                'description' => 'How often to refresh data',
                'default' => 30,
            ],
            [
                'keyname' => 'timezone',
                'field_type' => 'time_zone',
                'name' => 'Timezone',
                'description' => 'Select your timezone',
                // No default value
            ],
        ],
    ];

    $plugin = Plugin::factory()->create([
        'configuration_template' => $configurationTemplate,
        'configuration' => [
            'reading_days' => 'Monday,Friday,Saturday,Sunday',
            'refresh_interval' => 30,
        ],
    ]);

    expect($plugin->configuration)
        ->toBeArray()
        ->toHaveKey('reading_days')
        ->toHaveKey('refresh_interval')
        ->not->toHaveKey('timezone');

    expect($plugin->getConfiguration('reading_days'))->toBe('Monday,Friday,Saturday,Sunday');
    expect($plugin->getConfiguration('refresh_interval'))->toBe(30);
    expect($plugin->getConfiguration('timezone'))->toBeNull();
});

test('resolveLiquidVariables resolves configuration variables correctly', function (): void {
    $plugin = Plugin::factory()->create([
        'configuration' => [
            'Latitude' => '48.2083',
            'Longitude' => '16.3731',
            'api_key' => 'test123',
        ],
    ]);

    $template = 'https://suntracker.me/?lat={{ Latitude }}&lon={{ Longitude }}';
    $expected = 'https://suntracker.me/?lat=48.2083&lon=16.3731';

    expect($plugin->resolveLiquidVariables($template))->toBe($expected);
});

test('resolveLiquidVariables handles missing variables gracefully', function (): void {
    $plugin = Plugin::factory()->create([
        'configuration' => [
            'Latitude' => '48.2083',
        ],
    ]);

    $template = 'https://suntracker.me/?lat={{ Latitude }}&lon={{ Longitude }}&key={{ api_key }}';
    $expected = 'https://suntracker.me/?lat=48.2083&lon=&key=';

    expect($plugin->resolveLiquidVariables($template))->toBe($expected);
});

test('resolveLiquidVariables handles empty configuration', function (): void {
    $plugin = Plugin::factory()->create([
        'configuration' => [],
    ]);

    $template = 'https://suntracker.me/?lat={{ Latitude }}&lon={{ Longitude }}';
    $expected = 'https://suntracker.me/?lat=&lon=';

    expect($plugin->resolveLiquidVariables($template))->toBe($expected);
});

test('resolveLiquidVariables uses external renderer when preferred_renderer is trmnl-liquid and template contains for loop', function (): void {
    Illuminate\Support\Facades\Process::fake([
        '*' => Illuminate\Support\Facades\Process::result(
            output: 'https://api1.example.com/data\nhttps://api2.example.com/data',
            exitCode: 0
        ),
    ]);

    config(['services.trmnl.liquid_enabled' => true]);
    config(['services.trmnl.liquid_path' => '/usr/local/bin/trmnl-liquid-cli']);

    $plugin = Plugin::factory()->create([
        'preferred_renderer' => 'trmnl-liquid',
        'configuration' => [
            'recipe_ids' => '1,2',
        ],
    ]);

    $template = <<<'LIQUID'
{% assign ids = recipe_ids | split: "," %}
{% for id in ids %}
https://api{{ id }}.example.com/data
{% endfor %}
LIQUID;

    $result = $plugin->resolveLiquidVariables($template);

    // Trim trailing newlines that may be added by the process
    expect(mb_trim($result))->toBe('https://api1.example.com/data\nhttps://api2.example.com/data');

    Illuminate\Support\Facades\Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, 'trmnl-liquid-cli') &&
               str_contains($command, '--template') &&
               str_contains($command, '--context');
    });
});

test('resolveLiquidVariables uses internal renderer when preferred_renderer is not trmnl-liquid', function (): void {
    $plugin = Plugin::factory()->create([
        'preferred_renderer' => 'php',
        'configuration' => [
            'recipe_ids' => '1,2',
        ],
    ]);

    $template = <<<'LIQUID'
{% assign ids = recipe_ids | split: "," %}
{% for id in ids %}
https://api{{ id }}.example.com/data
{% endfor %}
LIQUID;

    // Should use internal renderer even with for loop
    $result = $plugin->resolveLiquidVariables($template);

    // Internal renderer should process the template
    expect($result)->toBeString();
});

test('resolveLiquidVariables uses internal renderer when external renderer is disabled', function (): void {
    config(['services.trmnl.liquid_enabled' => false]);

    $plugin = Plugin::factory()->create([
        'preferred_renderer' => 'trmnl-liquid',
        'configuration' => [
            'recipe_ids' => '1,2',
        ],
    ]);

    $template = <<<'LIQUID'
{% assign ids = recipe_ids | split: "," %}
{% for id in ids %}
https://api{{ id }}.example.com/data
{% endfor %}
LIQUID;

    // Should use internal renderer when external is disabled
    $result = $plugin->resolveLiquidVariables($template);

    expect($result)->toBeString();
});

test('resolveLiquidVariables uses internal renderer when template does not contain for loop', function (): void {
    config(['services.trmnl.liquid_enabled' => true]);
    config(['services.trmnl.liquid_path' => '/usr/local/bin/trmnl-liquid-cli']);

    $plugin = Plugin::factory()->create([
        'preferred_renderer' => 'trmnl-liquid',
        'configuration' => [
            'api_key' => 'test123',
        ],
    ]);

    $template = 'https://api.example.com/data?key={{ api_key }}';

    // Should use internal renderer when no for loop
    $result = $plugin->resolveLiquidVariables($template);

    expect($result)->toBe('https://api.example.com/data?key=test123');

    Illuminate\Support\Facades\Process::assertNothingRan();
});

test('resolveLiquidVariables detects for loop with standard opening tag', function (): void {
    Illuminate\Support\Facades\Process::fake([
        '*' => Illuminate\Support\Facades\Process::result(
            output: 'resolved',
            exitCode: 0
        ),
    ]);

    config(['services.trmnl.liquid_enabled' => true]);
    config(['services.trmnl.liquid_path' => '/usr/local/bin/trmnl-liquid-cli']);

    $plugin = Plugin::factory()->create([
        'preferred_renderer' => 'trmnl-liquid',
        'configuration' => [],
    ]);

    // Test {% for pattern
    $template = '{% for item in items %}test{% endfor %}';
    $plugin->resolveLiquidVariables($template);

    Illuminate\Support\Facades\Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'trmnl-liquid-cli');
    });
});

test('resolveLiquidVariables detects for loop with whitespace stripping tag', function (): void {
    Illuminate\Support\Facades\Process::fake([
        '*' => Illuminate\Support\Facades\Process::result(
            output: 'resolved',
            exitCode: 0
        ),
    ]);

    config(['services.trmnl.liquid_enabled' => true]);
    config(['services.trmnl.liquid_path' => '/usr/local/bin/trmnl-liquid-cli']);

    $plugin = Plugin::factory()->create([
        'preferred_renderer' => 'trmnl-liquid',
        'configuration' => [],
    ]);

    // Test {%- for pattern (with whitespace stripping)
    $template = '{%- for item in items %}test{% endfor %}';
    $plugin->resolveLiquidVariables($template);

    Illuminate\Support\Facades\Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'trmnl-liquid-cli');
    });
});

test('updateDataPayload resolves entire polling_url field first then splits by newline', function (): void {
    Http::fake([
        'https://api1.example.com/data' => Http::response(['data' => 'test1'], 200),
        'https://api2.example.com/data' => Http::response(['data' => 'test2'], 200),
    ]);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'polling_url' => "https://api1.example.com/data\nhttps://api2.example.com/data",
        'polling_verb' => 'get',
        'configuration' => [
            'recipe_ids' => '1,2',
        ],
    ]);

    $plugin->updateDataPayload();

    // Should have split the multi-line URL and generated two requests
    expect($plugin->data_payload)->toHaveKey('IDX_0');
    expect($plugin->data_payload)->toHaveKey('IDX_1');
    expect($plugin->data_payload['IDX_0'])->toBe(['data' => 'test1']);
    expect($plugin->data_payload['IDX_1'])->toBe(['data' => 'test2']);
});

test('updateDataPayload handles multi-line polling_url with for loop using external renderer', function (): void {
    Illuminate\Support\Facades\Process::fake([
        '*' => Illuminate\Support\Facades\Process::result(
            output: "https://api1.example.com/data\nhttps://api2.example.com/data",
            exitCode: 0
        ),
    ]);

    Http::fake([
        'https://api1.example.com/data' => Http::response(['data' => 'test1'], 200),
        'https://api2.example.com/data' => Http::response(['data' => 'test2'], 200),
    ]);

    config(['services.trmnl.liquid_enabled' => true]);
    config(['services.trmnl.liquid_path' => '/usr/local/bin/trmnl-liquid-cli']);

    $plugin = Plugin::factory()->create([
        'data_strategy' => 'polling',
        'preferred_renderer' => 'trmnl-liquid',
        'polling_url' => <<<'LIQUID'
{% assign ids = recipe_ids | split: "," %}
{% for id in ids %}
https://api{{ id }}.example.com/data
{% endfor %}
LIQUID
        ,
        'polling_verb' => 'get',
        'configuration' => [
            'recipe_ids' => '1,2',
        ],
    ]);

    $plugin->updateDataPayload();

    // Should have used external renderer and generated two URLs
    expect($plugin->data_payload)->toHaveKey('IDX_0');
    expect($plugin->data_payload)->toHaveKey('IDX_1');
    expect($plugin->data_payload['IDX_0'])->toBe(['data' => 'test1']);
    expect($plugin->data_payload['IDX_1'])->toBe(['data' => 'test2']);

    Illuminate\Support\Facades\Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'trmnl-liquid-cli');
    });
});

test('plugin render uses user timezone when set', function (): void {
    $user = User::factory()->create([
        'timezone' => 'America/New_York',
    ]);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'liquid',
        'render_markup' => '{{ trmnl.user.time_zone_iana }}',
    ]);

    $rendered = $plugin->render();

    expect($rendered)->toContain('America/New_York');
});

test('plugin render falls back to app timezone when user timezone is not set', function (): void {
    $user = User::factory()->create([
        'timezone' => null,
    ]);

    config(['app.timezone' => 'Europe/London']);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'liquid',
        'render_markup' => '{{ trmnl.user.time_zone_iana }}',
    ]);

    $rendered = $plugin->render();

    expect($rendered)->toContain('Europe/London');
});

test('plugin render calculates correct UTC offset from user timezone', function (): void {
    $user = User::factory()->create([
        'timezone' => 'America/New_York', // UTC-5 (EST) or UTC-4 (EDT)
    ]);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'liquid',
        'render_markup' => '{{ trmnl.user.utc_offset }}',
    ]);

    $rendered = $plugin->render();

    // America/New_York offset should be -18000 (EST) or -14400 (EDT) in seconds
    $expectedOffset = (string) Carbon::now('America/New_York')->getOffset();
    expect($rendered)->toContain($expectedOffset);
});

test('plugin render calculates correct UTC offset from app timezone when user timezone is null', function (): void {
    $user = User::factory()->create([
        'timezone' => null,
    ]);

    config(['app.timezone' => 'Europe/London']);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'liquid',
        'render_markup' => '{{ trmnl.user.utc_offset }}',
    ]);

    $rendered = $plugin->render();

    // Europe/London offset should be 0 (GMT) or 3600 (BST) in seconds
    $expectedOffset = (string) Carbon::now('Europe/London')->getOffset();
    expect($rendered)->toContain($expectedOffset);
});

test('plugin render includes utc_offset and time_zone_iana in trmnl.user context', function (): void {
    $user = User::factory()->create([
        'timezone' => 'America/Chicago', // UTC-6 (CST) or UTC-5 (CDT)
    ]);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'markup_language' => 'liquid',
        'render_markup' => '{{ trmnl.user.time_zone_iana }}|{{ trmnl.user.utc_offset }}',
    ]);

    $rendered = $plugin->render();

    expect($rendered)
        ->toContain('America/Chicago')
        ->and($rendered)->toMatch('/\|-?\d+/'); // Should contain a pipe followed by a number (offset in seconds)
});

/**
 * Plugin security: XSS Payload Dataset
 * [Input, Expected Result, Forbidden String]
 */
dataset('xss_vectors', [
    'standard_script' => ['Safe <script>alert(1)</script>', 'Safe ', '<script>'],
    'attribute_event_handlers' => ['<a onmouseover="alert(1)">Link</a>', '<a>Link</a>', 'onmouseover'],
    'javascript_protocol' => ['<a href="javascript:alert(1)">Click</a>', '<a>Click</a>', 'javascript:'],
    'iframe_injection' => ['Watch <iframe src="https://x.com"></iframe>', 'Watch ', '<iframe>'],
    'img_onerror_fallback' => ['Photo <img src=x onerror=alert(1)>', 'Photo <img src="x" alt="x">', 'onerror'],
]);

test('plugin model sanitizes template fields on save', function (string $input, string $expected, string $forbidden): void {
    $user = User::factory()->create();

    // We test the Model logic directly. This triggers the static::saving hook.
    $plugin = Plugin::create([
        'user_id' => $user->id,
        'name' => 'Security Test',
        'data_stale_minutes' => 15,
        'data_strategy' => 'static',
        'polling_verb' => 'get',
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'test_field',
                    'description' => $input,
                    'help_text' => $input,
                ],
            ],
        ],
    ]);

    $field = $plugin->fresh()->configuration_template['custom_fields'][0];

    // Assert the saved data is clean
    expect($field['description'])->toBe($expected)
        ->and($field['help_text'])->toBe($expected)
        ->and($field['description'])->not->toContain($forbidden);
})->with('xss_vectors');

test('plugin model preserves multi_string csv format', function (): void {
    $user = User::factory()->create();

    $plugin = Plugin::create([
        'user_id' => $user->id,
        'name' => 'Multi-string Test',
        'data_stale_minutes' => 15,
        'data_strategy' => 'static',
        'polling_verb' => 'get',
        'configuration' => [
            'tags' => 'laravel,pest,security',
        ],
    ]);

    expect($plugin->fresh()->configuration['tags'])->toBe('laravel,pest,security');
});

test('plugin duplicate copies all attributes except id and uuid', function (): void {
    $user = User::factory()->create();

    $original = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Original Plugin',
        'data_stale_minutes' => 30,
        'data_strategy' => 'polling',
        'polling_url' => 'https://api.example.com/data',
        'polling_verb' => 'get',
        'polling_header' => 'Authorization: Bearer token123',
        'polling_body' => '{"query": "test"}',
        'render_markup' => '<div>Test markup</div>',
        'markup_language' => 'blade',
        'configuration' => ['api_key' => 'secret123'],
        'configuration_template' => [
            'custom_fields' => [
                [
                    'keyname' => 'api_key',
                    'field_type' => 'string',
                ],
            ],
        ],
        'no_bleed' => true,
        'dark_mode' => true,
        'data_payload' => ['test' => 'data'],
    ]);

    $duplicate = $original->duplicate();

    // Refresh to ensure casts are applied
    $original->refresh();
    $duplicate->refresh();

    expect($duplicate->id)->not->toBe($original->id)
        ->and($duplicate->uuid)->not->toBe($original->uuid)
        ->and($duplicate->name)->toBe('Original Plugin_copy')
        ->and($duplicate->user_id)->toBe($original->user_id)
        ->and($duplicate->data_stale_minutes)->toBe($original->data_stale_minutes)
        ->and($duplicate->data_strategy)->toBe($original->data_strategy)
        ->and($duplicate->polling_url)->toBe($original->polling_url)
        ->and($duplicate->polling_verb)->toBe($original->polling_verb)
        ->and($duplicate->polling_header)->toBe($original->polling_header)
        ->and($duplicate->polling_body)->toBe($original->polling_body)
        ->and($duplicate->render_markup)->toBe($original->render_markup)
        ->and($duplicate->markup_language)->toBe($original->markup_language)
        ->and($duplicate->configuration)->toBe($original->configuration)
        ->and($duplicate->configuration_template)->toBe($original->configuration_template)
        ->and($duplicate->no_bleed)->toBe($original->no_bleed)
        ->and($duplicate->dark_mode)->toBe($original->dark_mode)
        ->and($duplicate->data_payload)->toBe($original->data_payload)
        ->and($duplicate->render_markup_view)->toBeNull();
});

test('plugin duplicate sets trmnlp_id to null to avoid unique constraint violation', function (): void {
    $user = User::factory()->create();

    $original = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Plugin with trmnlp_id',
        'trmnlp_id' => 'test-trmnlp-id-123',
    ]);

    $duplicate = $original->duplicate();

    // Refresh to ensure casts are applied
    $original->refresh();
    $duplicate->refresh();

    expect($duplicate->trmnlp_id)->toBeNull()
        ->and($original->trmnlp_id)->toBe('test-trmnlp-id-123')
        ->and($duplicate->name)->toBe('Plugin with trmnlp_id_copy');
});

test('plugin duplicate copies render_markup_view file content to render_markup', function (): void {
    $user = User::factory()->create();

    // Create a test blade file
    $testViewPath = resource_path('views/recipes/test-duplicate.blade.php');
    $testContent = '<div class="test-view">Test Content</div>';

    // Ensure directory exists
    if (! is_dir(dirname($testViewPath))) {
        mkdir(dirname($testViewPath), 0755, true);
    }

    file_put_contents($testViewPath, $testContent);

    try {
        $original = Plugin::factory()->create([
            'user_id' => $user->id,
            'name' => 'View Plugin',
            'render_markup' => null,
            'render_markup_view' => 'recipes.test-duplicate',
            'markup_language' => null,
        ]);

        $duplicate = $original->duplicate();

        expect($duplicate->render_markup)->toBe($testContent)
            ->and($duplicate->markup_language)->toBe('blade')
            ->and($duplicate->render_markup_view)->toBeNull()
            ->and($duplicate->name)->toBe('View Plugin_copy');
    } finally {
        // Clean up test file
        if (file_exists($testViewPath)) {
            unlink($testViewPath);
        }
    }
});

test('plugin duplicate handles liquid file extension', function (): void {
    $user = User::factory()->create();

    // Create a test liquid file
    $testViewPath = resource_path('views/recipes/test-duplicate-liquid.liquid');
    $testContent = '<div class="test-view">{{ data.message }}</div>';

    // Ensure directory exists
    if (! is_dir(dirname($testViewPath))) {
        mkdir(dirname($testViewPath), 0755, true);
    }

    file_put_contents($testViewPath, $testContent);

    try {
        $original = Plugin::factory()->create([
            'user_id' => $user->id,
            'name' => 'Liquid Plugin',
            'render_markup' => null,
            'render_markup_view' => 'recipes.test-duplicate-liquid',
            'markup_language' => null,
        ]);

        $duplicate = $original->duplicate();

        expect($duplicate->render_markup)->toBe($testContent)
            ->and($duplicate->markup_language)->toBe('liquid')
            ->and($duplicate->render_markup_view)->toBeNull();
    } finally {
        // Clean up test file
        if (file_exists($testViewPath)) {
            unlink($testViewPath);
        }
    }
});

test('plugin duplicate handles missing view file gracefully', function (): void {
    $user = User::factory()->create();

    $original = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Missing View Plugin',
        'render_markup' => null,
        'render_markup_view' => 'recipes.nonexistent-view',
        'markup_language' => null,
    ]);

    $duplicate = $original->duplicate();

    expect($duplicate->render_markup_view)->toBeNull()
        ->and($duplicate->name)->toBe('Missing View Plugin_copy');
});

test('plugin duplicate uses provided user_id', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $original = Plugin::factory()->create([
        'user_id' => $user1->id,
        'name' => 'Original Plugin',
    ]);

    $duplicate = $original->duplicate($user2->id);

    expect($duplicate->user_id)->toBe($user2->id)
        ->and($duplicate->user_id)->not->toBe($original->user_id);
});

test('plugin duplicate falls back to original user_id when no user_id provided', function (): void {
    $user = User::factory()->create();

    $original = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Original Plugin',
    ]);

    $duplicate = $original->duplicate();

    expect($duplicate->user_id)->toBe($original->user_id);
});

test('deleting plugin cascades direct playlist items', function (): void {
    $plugin = Plugin::factory()->create();
    $playlistItem = PlaylistItem::factory()->create([
        'plugin_id' => $plugin->id,
    ]);

    $plugin->delete();

    expect(PlaylistItem::query()->find($playlistItem->id))->toBeNull();
});

test('deleting plugin cascades mashup playlist items containing plugin id', function (): void {
    $playlist = Playlist::factory()->create();
    $mainPlugin = Plugin::factory()->create();
    $secondaryPlugin = Plugin::factory()->create();

    $mashupItem = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $mainPlugin->id,
        'mashup' => [
            'mashup_layout' => '1Lx1R',
            'mashup_name' => 'Cascade Test Mashup',
            'plugin_ids' => [$mainPlugin->id, $secondaryPlugin->id],
        ],
    ]);

    $secondaryPlugin->delete();

    expect(PlaylistItem::query()->find($mashupItem->id))->toBeNull();
});
