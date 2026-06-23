<?php

use App\Jobs\GenerateScreenJob;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use App\Services\ImageGenerationService;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    EpaperPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
});

test('device can fetch display data with valid credentials', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'current_screen_image' => 'test-image',
    ]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk()
        ->assertJson([
            'status' => '0',
            'filename' => 'test-image.bmp',
            'refresh_rate' => 900,
            'reset_firmware' => false,
            'update_firmware' => false,
            'firmware_url' => null,
            'special_function' => 'sleep',
            'maximum_compatibility' => false,
        ]);

    expect($device->fresh())
        ->last_rssi_level->toBe(-70)
        ->last_battery_voltage->toBe(3.8)
        ->last_firmware_version->toBe('1.0.0');
});

test('display endpoint includes image_url_timeout when configured', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
    ]);

    config(['services.trmnl.image_url_timeout' => 300]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk()
        ->assertJson([
            'image_url_timeout' => 300,
        ]);
});

test('display endpoint omits image_url_timeout when not configured', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
    ]);

    config(['services.trmnl.image_url_timeout' => null]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk()
        ->assertJsonMissing(['image_url_timeout']);
});

test('display endpoint includes maximum_compatibility value when true for device', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'maximum_compatibility' => true,
    ]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk()
        ->assertJson([
            'maximum_compatibility' => true,
        ]);
});

test('new device is auto-assigned to user with auto-assign enabled', function (): void {
    $user = User::factory()->create(['assign_new_devices' => true]);

    $response = $this->withHeaders([
        'id' => '00:11:22:33:44:55',
        'access-token' => 'new-device-key',
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();

    $device = Device::where('mac_address', '00:11:22:33:44:55')->first();
    expect($device)
        ->not->toBeNull()
        ->user_id->toBe($user->id)
        ->api_key->toBe('new-device-key');
});

test('new device is auto-assigned and mirrors specified device', function (): void {
    // Create a source device that will be mirrored
    $sourceDevice = Device::factory()->create([
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'api_key' => 'source-api-key',
        'current_screen_image' => 'source-image',
    ]);

    // Create user with auto-assign enabled and mirror device set
    $user = User::factory()->create([
        'assign_new_devices' => true,
        'assign_new_device_id' => $sourceDevice->id,
    ]);

    // Make request from new device
    $response = $this->withHeaders([
        'id' => '00:11:22:33:44:55',
        'access-token' => 'new-device-key',
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();

    // Verify the new device was created and mirrors the source device
    $newDevice = Device::where('mac_address', '00:11:22:33:44:55')->first();
    expect($newDevice)
        ->not->toBeNull()
        ->user_id->toBe($user->id)
        ->api_key->toBe('new-device-key')
        ->mirror_device_id->toBe($sourceDevice->id);

    // Verify the response contains the source device's image
    $response->assertJson([
        'filename' => 'source-image.bmp',
    ]);
});

test('device setup endpoint returns correct data', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'friendly_id' => 'test-device',
    ]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
    ])->get('/api/setup');

    $response->assertOk()
        ->assertJson([
            'api_key' => 'test-api-key',
            'friendly_id' => 'test-device',
            'message' => 'Welcome to TRMNL BYOS',
        ]);
});

test('device can submit logs', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
    ]);

    $logData = [
        'log' => [
            'logs_array' => [
                ['log_message' => 'Test log message', 'level' => 'info'],
            ],
        ],
    ];

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
    ])->postJson('/api/log', $logData);

    $response->assertOk()
        ->assertJson(['status' => '200']);

    expect($device->fresh()->last_log_request)
        ->toBe($logData);

    expect($device->logs()->count())->toBe(1);
});

test('device can submit logs in revised format', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
    ]);

    $logData = [
        'logs' => [
            ['message' => 'Test log message', 'level' => 'info'],
        ],
    ];

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
    ])->postJson('/api/log', $logData);

    $response->assertOk()
        ->assertJson(['status' => '200']);

    expect($device->fresh()->last_log_request)
        ->toBe($logData);

    expect($device->logs()->count())->toBe(1);
});

// test('authenticated user can update device display', function () {
//    $user = User::factory()->create();
//    $device = Device::factory()->create(['user_id' => $user->id]);
//
//    Sanctum::actingAs($user, ['update-screen']);
//
//    $response = $this->postJson('/api/display/update', [
//        'device_id' => $device->id,
//        'markup' => '<div>Test markup</div>'
//    ]);
//
//    $response->assertOk();
// });

test('user cannot update display for devices they do not own', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user, ['update-screen']);

    $response = $this->postJson('/api/display/update', [
        'device_id' => $device->id,
        'markup' => '<div>Test markup</div>',
    ]);

    $response->assertForbidden();
});

test('display.update returns success JSON for owned devices', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user, ['update-screen']);

    $response = $this->postJson('/api/display/update', [
        'device_id' => $device->id,
        'markup' => '<div>Test markup</div>',
    ]);

    $response->assertOk()->assertExactJson(['message' => 'success']);
});

test('invalid device credentials return error', function (): void {
    $response = $this->withHeaders([
        'id' => 'invalid-mac',
        'access-token' => 'invalid-token',
    ])->get('/api/display');

    $response->assertNotFound()
        ->assertJson(['message' => 'MAC Address not registered (or not set), or invalid access token']);
});

test('log endpoint requires valid device credentials', function (): void {
    $response = $this->withHeaders([
        'id' => 'invalid-mac',
        'access-token' => 'invalid-token',
    ])->postJson('/api/log', ['log' => []]);

    $response->assertNotFound()
        ->assertJson(['message' => 'Device not found or invalid access token']);
});

test('update_firmware flag is only returned once', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'proxy_cloud_response' => [
            'update_firmware' => true,
            'firmware_url' => 'https://example.com/firmware.bin',
        ],
    ]);

    // First request should return update_firmware as true
    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk()
        ->assertJson([
            'update_firmware' => true,
            'firmware_url' => 'https://example.com/firmware.bin',
        ]);

    // Second request should return update_firmware as false
    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk()
        ->assertJson([
            'update_firmware' => false,
            'firmware_url' => 'https://example.com/firmware.bin',
        ]);

    // Verify the proxy_cloud_response was updated
    $device->refresh();
    expect($device->proxy_cloud_response['update_firmware'])->toBeFalse();
});

test('display endpoint handles proxy cloud response without firmware keys', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'proxy_cloud_response' => [
            'image_url' => 'https://example.com/test-image.bmp',
            'filename' => 'test-image',
        ],
    ]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk()
        ->assertJson([
            'update_firmware' => false,
            'firmware_url' => null,
        ]);
});

test('authenticated user can fetch device status', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'mac_address' => '00:11:22:33:44:55',
        'name' => 'Test Device',
        'friendly_id' => 'test-device',
        'last_rssi_level' => -70,
        'last_battery_voltage' => 3.8,
        'last_firmware_version' => '1.0.0',
        'current_screen_image' => 'test-image',
        'default_refresh_interval' => 900,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/display/status?device_id='.$device->id);

    $response->assertOk()
        ->assertJson([
            'id' => $device->id,
            'mac_address' => '00:11:22:33:44:55',
            'name' => 'Test Device',
            'friendly_id' => 'test-device',
            'last_rssi_level' => -70,
            'last_battery_voltage' => 3.8,
            'last_firmware_version' => '1.0.0',
            'battery_percent' => 67,
            'wifi_strength' => 2,
            'current_screen_image' => 'test-image',
            'default_refresh_interval' => 900,
        ]);
});

test('user cannot fetch status for devices they do not own', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/display/status?device_id='.$device->id);

    $response->assertForbidden();
});

test('display status endpoint requires device_id parameter', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/display/status');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['device_id']);
});

test('display status endpoint requires valid device_id', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/display/status?device_id=999');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['device_id']);
});

test('device can mirror another device', function (): void {
    // Create source device with a playlist and image
    $sourceDevice = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'source-api-key',
        'current_screen_image' => 'source-image',
    ]);

    // Create mirroring device
    $mirrorDevice = Device::factory()->create([
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'api_key' => 'mirror-api-key',
        'mirror_device_id' => $sourceDevice->id,
    ]);

    // Make request from mirror device
    $response = $this->withHeaders([
        'id' => $mirrorDevice->mac_address,
        'access-token' => $mirrorDevice->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk()
        ->assertJson([
            'status' => '0',
            'filename' => 'source-image.bmp',
            'refresh_rate' => 900,
            'reset_firmware' => false,
            'update_firmware' => false,
            'firmware_url' => null,
            'special_function' => 'sleep',
        ]);

    // Verify mirror device stats were updated
    expect($mirrorDevice->fresh())
        ->last_rssi_level->toBe(-70)
        ->last_battery_voltage->toBe(3.8)
        ->last_firmware_version->toBe('1.0.0');
});

test('device can fetch current screen data', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'current_screen_image' => 'test-image',
    ]);

    $response = $this->withHeaders([
        'access-token' => $device->api_key,
    ])->get('/api/current_screen');

    $response->assertOk()
        ->assertJson([
            'status' => 200,
            'filename' => 'test-image.bmp',
            'refresh_rate' => 900,
            'reset_firmware' => false,
            'update_firmware' => false,
            'firmware_url' => null,
            'special_function' => 'sleep',
        ]);
});

test('current_screen endpoint requires valid device credentials', function (): void {
    $response = $this->withHeaders([
        'access-token' => 'invalid-token',
    ])->get('/api/current_screen');

    $response->assertNotFound()
        ->assertJson(['message' => 'Device not found or invalid access token']);
});

test('authenticated user can fetch their devices', function (): void {
    $user = User::factory()->create();
    $devices = Device::factory()->count(2)->create([
        'user_id' => $user->id,
        'last_battery_voltage' => 3.72,
        'last_rssi_level' => -63,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/devices');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'friendly_id',
                    'mac_address',
                    'battery_voltage',
                    'rssi',
                ],
            ],
        ])
        ->assertJsonCount(2, 'data');

    // Verify the first device's data
    $response->assertJson([
        'data' => [
            [
                'id' => $devices[0]->id,
                'name' => $devices[0]->name,
                'friendly_id' => $devices[0]->friendly_id,
                'mac_address' => $devices[0]->mac_address,
                'battery_voltage' => 3.72,
                'rssi' => -63,
            ],
        ],
    ]);
});

test('plugin caches image until data is stale', function (): void {
    // Create source device with a playlist
    $device = Device::factory()->create([
        'mac_address' => '55:11:22:33:44:55',
        'api_key' => 'source-api-key',
        'proxy_cloud' => false,
    ]);

    $plugin = Plugin::factory()->create([
        'name' => 'Zen Quotes',
        'polling_url' => null,
        'data_stale_minutes' => 1,
        'data_strategy' => 'polling',
        'polling_verb' => 'get',
        'render_markup_view' => 'trmnl',
        'is_native' => false,
        'data_payload_updated_at' => null,
    ]);

    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'name' => 'update_test',
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
        'order' => 1,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    // initial request, generates the image
    $firstResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $firstResponse->assertOk();
    expect($firstResponse['filename'])->not->toBe('setup-logo.bmp');

    // second request after 15 seconds, shouldn't generate a new image
    $plugin->update(['data_payload_updated_at' => now()->addSeconds(-15)]);
    $secondResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    expect($secondResponse['filename'])
        ->toBe($firstResponse['filename']);

    // third request after 75 seconds, should generate a new image
    $plugin->update(['data_payload_updated_at' => now()->addSeconds(-75)]);
    $thirdResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    expect($thirdResponse['filename'])
        ->not->toBe($firstResponse['filename']);
});

test('plugins in playlist are rendered in order', function (): void {
    // Create source device with a playlist
    $device = Device::factory()->create([
        'mac_address' => '55:11:22:33:44:55',
        'api_key' => 'source-api-key',
        'proxy_cloud' => true,
    ]);

    // Create two plugins
    $firstPlugin = Plugin::factory()->create([
        'name' => 'First Plugin',
        'polling_url' => null,
        'data_stale_minutes' => 1,
        'data_strategy' => 'polling',
        'polling_verb' => 'get',
        'render_markup_view' => 'trmnl',
        'is_native' => false,
        'data_payload_updated_at' => null,
    ]);

    $secondPlugin = Plugin::factory()->create([
        'name' => 'Second Plugin',
        'polling_url' => null,
        'data_stale_minutes' => 1,
        'data_strategy' => 'polling',
        'polling_verb' => 'get',
        'render_markup_view' => 'trmnl',
        'is_native' => false,
        'data_payload_updated_at' => null,
    ]);

    // Create playlist
    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'name' => 'Two Plugins Test',
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    // Add plugins to playlist in specific order
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $firstPlugin->id,
        'order' => 1,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $secondPlugin->id,
        'order' => 2,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    // First request should show the first plugin
    $firstResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
    ])->get('/api/display');

    $firstResponse->assertOk();
    $firstImageFilename = $firstResponse['filename'];
    expect($firstImageFilename)->not->toBe('setup-logo.bmp');

    // Get the first plugin's playlist item and verify it was marked as displayed
    $firstPluginItem = PlaylistItem::where('plugin_id', $firstPlugin->id)->first();
    expect($firstPluginItem->last_displayed_at)->not->toBeNull();

    // Distinct seconds for last_displayed_at so playlist rotation is deterministic.
    $this->travel(1)->seconds();

    // Second request should show the second plugin
    $secondResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $secondResponse->assertOk();
    expect($secondResponse['filename'])
        ->not->toBe($firstImageFilename)
        ->not->toBe('setup-logo.bmp');

    // Get the second plugin's playlist item and verify it was marked as displayed
    $secondPluginItem = PlaylistItem::where('plugin_id', $secondPlugin->id)->first();
    expect($secondPluginItem->last_displayed_at)->not->toBeNull();

    // Third request should show the first plugin again
    $thirdResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $thirdResponse->assertOk();
    expect($thirdResponse['filename'])
        ->not->toBe($secondResponse['filename']);
});

test('display endpoint keeps current screen when all active playlist items are skipped', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '55:11:22:33:44:77',
        'api_key' => 'all-skip-api-key',
        'proxy_cloud' => false,
        'current_screen_image' => 'stable-screen',
    ]);

    $imageMetadata = ImageGenerationService::buildImageMetadataFromDevice($device);

    $firstSkipPlugin = Plugin::factory()->create([
        'name' => 'First Skip Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'polling_verb' => 'get',
        'data_stale_minutes' => 5,
        'data_payload' => ['TRMNL_SKIP_DISPLAY' => true],
        'data_payload_updated_at' => now(),
        'current_image' => 'first-skip-image',
        'current_image_metadata' => $imageMetadata,
    ]);

    $secondSkipPlugin = Plugin::factory()->create([
        'name' => 'Second Skip Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'polling_verb' => 'get',
        'data_stale_minutes' => 5,
        'data_payload' => ['TRMNL_SKIP_DISPLAY' => true],
        'data_payload_updated_at' => now(),
        'current_image' => 'second-skip-image',
        'current_image_metadata' => $imageMetadata,
    ]);

    Storage::disk('public')->put('images/generated/stable-screen.bmp', 'stable');

    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'name' => 'All Skip Test',
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    $firstSkippedItem = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $firstSkipPlugin->id,
        'order' => 1,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    $secondSkippedItem = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $secondSkipPlugin->id,
        'order' => 2,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();

    expect($response['filename'])->toBe('stable-screen.bmp')
        ->and($firstSkippedItem->fresh()->last_displayed_at)->toBeNull()
        ->and($secondSkippedItem->fresh()->last_displayed_at)->toBeNull();
});

test('display endpoint skips playlist items when rendered markup requests TRMNL skip', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '55:11:22:33:44:99',
        'api_key' => 'markup-skip-api-key',
        'proxy_cloud' => false,
    ]);

    $imageMetadata = ImageGenerationService::buildImageMetadataFromDevice($device);

    $skipPlugin = Plugin::factory()->create([
        'name' => 'Markup Skip Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'polling_verb' => 'get',
        'data_stale_minutes' => 5,
        'data_payload' => ['headline' => 'Skip me'],
        'data_payload_updated_at' => now(),
        'current_image' => null,
        'markup_language' => 'blade',
        'render_markup' => <<<'BLADE'
<div>Hidden</div>
<script>
window.TRMNL_SKIP_DISPLAY = true;
</script>
BLADE,
    ]);

    $nextPlugin = Plugin::factory()->create([
        'name' => 'Next Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'polling_verb' => 'get',
        'data_stale_minutes' => 5,
        'data_payload' => ['headline' => 'Visible item'],
        'data_payload_updated_at' => now(),
        'current_image' => 'next-markup-plugin-image',
        'current_image_metadata' => $imageMetadata,
    ]);

    Storage::disk('public')->put('images/generated/next-markup-plugin-image.bmp', 'next');

    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'name' => 'Markup Skip Test',
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    $skippedItem = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $skipPlugin->id,
        'order' => 1,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $nextPlugin->id,
        'order' => 2,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();

    expect($response['filename'])->toBe('next-markup-plugin-image.bmp')
        ->and($skippedItem->fresh()->last_displayed_at)->toBeNull();
});

test('display endpoint continues normal playlist rotation after skipping an item', function (): void {
    Http::fake([
        'https://example.com/rotation-skip' => Http::response([
            'TRMNL_SKIP_DISPLAY' => true,
        ], 200),
    ]);

    $device = Device::factory()->create([
        'mac_address' => '55:11:22:33:44:88',
        'api_key' => 'skip-rotation-api-key',
        'proxy_cloud' => false,
    ]);

    $imageMetadata = ImageGenerationService::buildImageMetadataFromDevice($device);

    $skipPlugin = Plugin::factory()->create([
        'name' => 'Rotation Skip Plugin',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/rotation-skip',
        'polling_verb' => 'get',
        'data_stale_minutes' => 5,
        'render_markup_view' => 'trmnl',
        'data_payload_updated_at' => null,
        'current_image' => null,
    ]);

    $secondPlugin = Plugin::factory()->create([
        'name' => 'Second Visible Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'polling_verb' => 'get',
        'data_stale_minutes' => 5,
        'data_payload' => ['headline' => 'Visible item B'],
        'data_payload_updated_at' => now(),
        'current_image' => 'rotation-visible-b',
        'current_image_metadata' => $imageMetadata,
    ]);

    $thirdPlugin = Plugin::factory()->create([
        'name' => 'Third Visible Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'polling_verb' => 'get',
        'data_stale_minutes' => 5,
        'data_payload' => ['headline' => 'Visible item C'],
        'data_payload_updated_at' => now(),
        'current_image' => 'rotation-visible-c',
        'current_image_metadata' => $imageMetadata,
    ]);

    Storage::disk('public')->put('images/generated/rotation-visible-b.bmp', 'visible-b');
    Storage::disk('public')->put('images/generated/rotation-visible-c.bmp', 'visible-c');

    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'name' => 'Skip Rotation Test',
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    $skippedItem = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $skipPlugin->id,
        'order' => 1,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    $secondVisibleItem = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $secondPlugin->id,
        'order' => 2,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    $thirdVisibleItem = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $thirdPlugin->id,
        'order' => 3,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    $firstResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $firstResponse->assertOk();
    expect($firstResponse['filename'])->toBe('rotation-visible-b.bmp')
        ->and($skippedItem->fresh()->last_displayed_at)->toBeNull()
        ->and($secondVisibleItem->fresh()->last_displayed_at)->not->toBeNull()
        ->and($thirdVisibleItem->fresh()->last_displayed_at)->toBeNull();

    $this->travel(1)->seconds();

    $secondResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $secondResponse->assertOk();
    expect($secondResponse['filename'])->toBe('rotation-visible-c.bmp')
        ->and($skippedItem->fresh()->last_displayed_at)->toBeNull()
        ->and($secondVisibleItem->fresh()->last_displayed_at)->not->toBeNull()
        ->and($thirdVisibleItem->fresh()->last_displayed_at)->not->toBeNull();
});

test('display endpoint does not re-poll fresh skip payloads on later rotation passes', function (): void {
    Http::fake([
        'https://example.com/fresh-skip' => Http::response([
            'TRMNL_SKIP_DISPLAY' => true,
        ], 200),
    ]);

    $device = Device::factory()->create([
        'mac_address' => '55:11:22:33:44:89',
        'api_key' => 'fresh-skip-api-key',
        'proxy_cloud' => false,
    ]);

    $imageMetadata = ImageGenerationService::buildImageMetadataFromDevice($device);

    $skipPlugin = Plugin::factory()->create([
        'name' => 'Fresh Skip Plugin',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/fresh-skip',
        'polling_verb' => 'get',
        'data_stale_minutes' => 5,
        'data_payload_updated_at' => null,
        'current_image' => null,
    ]);

    $visiblePlugin = Plugin::factory()->create([
        'name' => 'Visible After Skip Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'polling_verb' => 'get',
        'data_stale_minutes' => 5,
        'data_payload' => ['headline' => 'Visible item'],
        'data_payload_updated_at' => now(),
        'current_image' => 'visible-after-skip-image',
        'current_image_metadata' => $imageMetadata,
    ]);

    Storage::disk('public')->put('images/generated/visible-after-skip-image.bmp', 'visible-after-skip');

    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'name' => 'Fresh Skip Polling Test',
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $skipPlugin->id,
        'order' => 1,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $visiblePlugin->id,
        'order' => 2,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    $firstResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $firstResponse->assertOk();
    expect($firstResponse['filename'])->toBe('visible-after-skip-image.bmp');

    $this->travel(1)->seconds();

    $secondResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $secondResponse->assertOk();
    expect($secondResponse['filename'])->toBe('visible-after-skip-image.bmp');

    Http::assertSentCount(1);
});

test('display endpoint does not reuse cached markup-skip images on later fresh rotation passes', function (): void {
    Http::fake([
        'https://example.com/markup-skip-refresh' => Http::response([
            'headline' => 'Skip based on rendered markup',
        ], 200),
    ]);

    $device = Device::factory()->create([
        'mac_address' => '55:11:22:33:44:90',
        'api_key' => 'markup-skip-fresh-cache-api-key',
        'proxy_cloud' => false,
    ]);

    $imageMetadata = ImageGenerationService::buildImageMetadataFromDevice($device);

    $skipPlugin = Plugin::factory()->create([
        'name' => 'Markup Skip Fresh Cache Plugin',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/markup-skip-refresh',
        'polling_verb' => 'get',
        'data_stale_minutes' => 120,
        'data_payload_updated_at' => null,
        'current_image' => 'stale-markup-skip-image',
        'current_image_metadata' => $imageMetadata,
        'markup_language' => 'blade',
        'render_markup' => <<<'BLADE'
<div>Hidden</div>
<script>
window.TRMNL_SKIP_DISPLAY = true;
</script>
BLADE,
    ]);

    $visiblePlugin = Plugin::factory()->create([
        'name' => 'Visible After Markup Skip Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'polling_verb' => 'get',
        'data_stale_minutes' => 5,
        'data_payload' => ['headline' => 'Visible item'],
        'data_payload_updated_at' => now(),
        'current_image' => 'visible-after-markup-skip-image',
        'current_image_metadata' => $imageMetadata,
    ]);

    Storage::disk('public')->put('images/generated/stale-markup-skip-image.bmp', 'stale-skip');
    Storage::disk('public')->put('images/generated/visible-after-markup-skip-image.bmp', 'visible-after-markup-skip');

    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'name' => 'Markup Skip Fresh Cache Test',
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    $skippedItem = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $skipPlugin->id,
        'order' => 1,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    $visibleItem = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $visiblePlugin->id,
        'order' => 2,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    $firstResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $firstResponse->assertOk();
    expect($firstResponse['filename'])->toBe('visible-after-markup-skip-image.bmp')
        ->and($skippedItem->fresh()->last_displayed_at)->toBeNull()
        ->and($skipPlugin->fresh()->current_image)->toBeNull()
        ->and($visibleItem->fresh()->last_displayed_at)->not->toBeNull();

    $this->travel(1)->seconds();

    $secondResponse = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $secondResponse->assertOk();
    expect($secondResponse['filename'])->toBe('visible-after-markup-skip-image.bmp')
        ->and($skippedItem->fresh()->last_displayed_at)->toBeNull()
        ->and($skipPlugin->fresh()->current_image)->toBeNull();

    Http::assertSentCount(1);
});

test('display endpoint updates last_refreshed_at timestamp', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
    ]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();

    $device->refresh();
    expect($device->last_refreshed_at)->not->toBeNull()
        ->and($device->last_refreshed_at->diffInSeconds(now()))->toBeLessThan(2);
});

test('display endpoint accepts battery-percent header and updates device', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:56',
        'api_key' => 'test-api-key-battery',
        'last_battery_voltage' => null,
    ]);

    $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'battery-percent' => '67',
    ])->get('/api/display')->assertOk();

    $device->refresh();
    expect($device->battery_percent)->toEqual(67);
});

test('display endpoint accepts Percent-Charged header and updates device', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:57',
        'api_key' => 'test-api-key-percent-charged',
        'last_battery_voltage' => null,
    ]);

    $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'Percent-Charged' => '51',
    ])->get('/api/display')->assertOk();

    $device->refresh();
    expect($device->battery_percent)->toEqual(51);
});

test('display endpoint updates last_refreshed_at timestamp for mirrored devices', function (): void {
    // Create source device
    $sourceDevice = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'source-api-key',
    ]);

    // Create mirroring device
    $mirrorDevice = Device::factory()->create([
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'api_key' => 'mirror-api-key',
        'mirror_device_id' => $sourceDevice->id,
    ]);

    $response = $this->withHeaders([
        'id' => $mirrorDevice->mac_address,
        'access-token' => $mirrorDevice->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();

    $mirrorDevice->refresh();
    expect($mirrorDevice->last_refreshed_at)->not->toBeNull()
        ->and($mirrorDevice->last_refreshed_at->diffInSeconds(now()))->toBeLessThan(2);
});

test('display endpoint handles mashup playlist items correctly', function (): void {
    // Create a device
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'proxy_cloud' => false,
    ]);

    // Create a playlist
    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'name' => 'update_test',
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    // Create three plugins for the mashup
    $plugin1 = Plugin::factory()->create([
        'name' => 'Plugin 1',
        'data_strategy' => 'webhook',
        'polling_url' => null,
        'data_stale_minutes' => 1,
        'render_markup_view' => 'trmnl',
    ]);

    $plugin2 = Plugin::factory()->create([
        'name' => 'Plugin 2',
        'data_strategy' => 'webhook',
        'polling_url' => null,
        'data_stale_minutes' => 1,
        'render_markup_view' => 'trmnl',
    ]);

    // Create a mashup playlist item with a 2Lx1R layout (2 plugins on left, 1 on right)
    $playlistItem = PlaylistItem::createMashup(
        $playlist,
        '1Lx1R',
        [$plugin1->id, $plugin2->id],
        'Test Mashup',
        1
    );

    // Make request to display endpoint
    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();

    // Verify the playlist item was marked as displayed
    $playlistItem->refresh();
    expect($playlistItem->last_displayed_at)->not->toBeNull();
});

test('device in sleep mode returns sleep image and correct refresh rate', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '19:00',
        'sleep_mode_to' => '23:00',
        'current_screen_image' => 'test-image',
    ]);

    // Freeze time to 20:00 (within sleep window)
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2000-01-01 20:00:00'));

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();

    // The filename should be a UUID-based PNG file since we're generating from template
    expect($response['filename'])->toMatch('/^[a-f0-9-]+\.png$/');
    expect($response['refresh_rate'])->toBeGreaterThan(0);

    Carbon\Carbon::setTestNow(); // Clear test time
});

test('device not in sleep mode returns normal image', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '19:00',
        'sleep_mode_to' => '23:00',
        'current_screen_image' => 'test-image',
    ]);

    // Freeze time to 18:00 (outside sleep window)
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2000-01-01 18:00:00'));

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk()
        ->assertJson([
            'filename' => 'test-image.bmp',
        ]);

    Carbon\Carbon::setTestNow(); // Clear test time
});

test('display status update requires sleep mode times when sleep mode is enabled', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'sleep_mode_enabled' => false,
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/display/status', [
        'device_id' => $device->id,
        'sleep_mode_enabled' => true,
        'sleep_mode_to' => '06:00',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sleep_mode_from']);
});

test('device returns sleep.png and correct refresh time when paused', function (): void {
    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'pause_until' => now()->addMinutes(60),
    ]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();
    $json = $response->json();

    // The filename should be a UUID-based PNG file since we're generating from template
    expect($json['filename'])->toMatch('/^[a-f0-9-]+\.png$/');
    expect($json['image_url'])->toContain('images/generated/');
    expect($json['refresh_rate'])->toBeLessThanOrEqual(3600); // ~60 min
});

test('screens endpoint accepts nullable file_name', function (): void {
    Queue::fake();

    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
    ]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
    ])->post('/api/screens', [
        'image' => [
            'content' => '<div>Test content</div>',
        ],
    ]);

    $response->assertOk();

    Queue::assertPushed(GenerateScreenJob::class);
});

test('screens endpoint returns 404 for invalid device credentials', function (): void {
    $response = $this->withHeaders([
        'id' => 'invalid-mac',
        'access-token' => 'invalid-key',
    ])->post('/api/screens', [
        'image' => [
            'content' => '<div>Test content</div>',
            'file_name' => 'test.blade.php',
        ],
    ]);

    $response->assertNotFound()
        ->assertJson([
            'message' => 'MAC Address not registered or invalid access token',
        ]);
});

test('setup endpoint assigns device model when model-id header is provided', function (): void {
    $user = User::factory()->create(['assign_new_devices' => true]);
    $deviceModel = DeviceModel::factory()->create([
        'name' => 'test-model',
        'label' => 'Test Model',
    ]);

    $response = $this->withHeaders([
        'id' => '00:11:22:33:44:55',
        'model-id' => 'test-model',
    ])->get('/api/setup');

    $response->assertOk()
        ->assertJson([
            'status' => 200,
            'message' => 'Welcome to TRMNL BYOS',
        ]);

    $device = Device::where('mac_address', '00:11:22:33:44:55')->first();
    expect($device)->not->toBeNull()
        ->and($device->device_model_id)->toBe($deviceModel->id);
});

test('setup endpoint handles non-existent device model gracefully', function (): void {
    $user = User::factory()->create(['assign_new_devices' => true]);

    $response = $this->withHeaders([
        'id' => '00:11:22:33:44:55',
        'model-id' => 'non-existent-model',
    ])->get('/api/setup');

    $response->assertOk()
        ->assertJson([
            'status' => 200,
            'message' => 'Welcome to TRMNL BYOS',
        ]);

    $device = Device::where('mac_address', '00:11:22:33:44:55')->first();
    expect($device)->not->toBeNull()
        ->and($device->device_model_id)->toBeNull();
});

test('setup endpoint matches MAC address case-insensitively', function (): void {
    // Create device with lowercase MAC address
    $device = Device::factory()->create([
        'mac_address' => 'a1:b2:c3:d4:e5:f6',
        'api_key' => 'test-api-key',
        'friendly_id' => 'test-device',
    ]);

    // Request with uppercase MAC address should still match
    $response = $this->withHeaders([
        'id' => 'A1:B2:C3:D4:E5:F6',
    ])->get('/api/setup');

    $response->assertOk()
        ->assertJson([
            'status' => 200,
            'api_key' => 'test-api-key',
            'friendly_id' => 'test-device',
            'message' => 'Welcome to TRMNL BYOS',
        ]);
});

test('display endpoint matches MAC address case-insensitively', function (): void {
    // Create device with lowercase MAC address
    $device = Device::factory()->create([
        'mac_address' => 'a1:b2:c3:d4:e5:f6',
        'api_key' => 'test-api-key',
        'current_screen_image' => 'test-image',
    ]);

    // Request with uppercase MAC address should still match
    $response = $this->withHeaders([
        'id' => 'A1:B2:C3:D4:E5:F6',
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk()
        ->assertJson([
            'status' => '0',
            'filename' => 'test-image.bmp',
        ]);
});

test('screens endpoint matches MAC address case-insensitively', function (): void {
    Queue::fake();

    // Create device with uppercase MAC address
    $device = Device::factory()->create([
        'mac_address' => 'A1:B2:C3:D4:E5:F6',
        'api_key' => 'test-api-key',
    ]);

    // Request with lowercase MAC address should still match
    $response = $this->withHeaders([
        'id' => 'a1:b2:c3:d4:e5:f6',
        'access-token' => $device->api_key,
    ])->post('/api/screens', [
        'image' => [
            'content' => '<div>Test content</div>',
        ],
    ]);

    $response->assertOk();
    Queue::assertPushed(GenerateScreenJob::class);
});

test('display endpoint handles plugin rendering errors gracefully', function (): void {
    EpaperPipeline::fake();

    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'proxy_cloud' => false,
    ]);

    // Create a plugin with Blade markup that will cause an exception when accessing data[0]
    // when data is not an array or doesn't have index 0
    $plugin = Plugin::factory()->create([
        'name' => 'Broken Recipe',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'data_stale_minutes' => 1,
        'markup_language' => 'blade', // Use Blade which will throw exception on invalid array access
        'render_markup' => '<div>{{ $data[0]["invalid"] }}</div>', // This will fail if data[0] doesn't exist
        'data_payload' => ['error' => 'Failed to fetch data'], // Not a list, so data[0] will fail
        'data_payload_updated_at' => now()->subMinutes(2), // Make it stale
        'current_image' => null,
    ]);

    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'name' => 'test_playlist',
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
        'order' => 1,
        'is_active' => true,
        'last_displayed_at' => null,
    ]);

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();

    // Verify error screen was generated and set on device
    $device->refresh();
    expect($device->current_screen_image)->not->toBeNull();

    // Verify the error image exists
    $errorImagePath = Storage::disk('public')->path("images/generated/{$device->current_screen_image}.png");
    // The EpaperPipeline is faked, so we just verify the UUID was set
    expect($device->current_screen_image)->toBeString();
});

test('display endpoint handles mashup rendering errors gracefully', function (): void {
    EpaperPipeline::fake();

    $device = Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'proxy_cloud' => false,
    ]);

    // Create plugins for mashup, one with invalid markup
    $plugin1 = Plugin::factory()->create([
        'name' => 'Working Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'data_stale_minutes' => 1,
        'render_markup_view' => 'trmnl',
        'data_payload_updated_at' => now()->subMinutes(2),
        'current_image' => null,
    ]);

    $plugin2 = Plugin::factory()->create([
        'name' => 'Broken Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'data_stale_minutes' => 1,
        'markup_language' => 'blade', // Use Blade which will throw exception on invalid array access
        'render_markup' => '<div>{{ $data[0]["invalid"] }}</div>', // This will fail
        'data_payload' => ['error' => 'Failed to fetch data'],
        'data_payload_updated_at' => now()->subMinutes(2),
        'current_image' => null,
    ]);

    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'name' => 'test_playlist',
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    // Create mashup playlist item
    $playlistItem = PlaylistItem::createMashup(
        $playlist,
        '1Lx1R',
        [$plugin1->id, $plugin2->id],
        'Test Mashup',
        1
    );

    $response = $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');

    $response->assertOk();

    // Verify error screen was generated and set on device
    $device->refresh();
    expect($device->current_screen_image)->not->toBeNull();

    // Verify the error image UUID was set
    expect($device->current_screen_image)->toBeString();
});

test('generateDefaultScreenImage creates error screen with plugin name', function (): void {
    EpaperPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');

    $device = Device::factory()->create();

    $errorUuid = ImageGenerationService::generateDefaultScreenImage($device, 'error', 'Test Recipe Name');

    expect($errorUuid)->not->toBeEmpty();

    // Verify the error image path would be created
    $errorPath = "images/generated/{$errorUuid}.png";
    // Since EpaperPipeline is faked, we just verify the UUID was generated
    expect($errorUuid)->toBeString();
});

test('generateDefaultScreenImage throws exception for invalid error image type', function (): void {
    $device = Device::factory()->create();

    expect(fn (): string => ImageGenerationService::generateDefaultScreenImage($device, 'invalid-error-type'))
        ->toThrow(InvalidArgumentException::class);
});

test('getDeviceSpecificDefaultImage returns null for error type when no device-specific image exists', function (): void {
    $device = new Device();
    $device->deviceModel = null;

    $result = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'error');
    expect($result)->toBeNull();
});
