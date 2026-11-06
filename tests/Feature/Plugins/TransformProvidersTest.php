<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugin\Transform\TransformException;
use App\Services\Plugin\Transform\TransformService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.transform.enabled' => true]);
});

test('external provider sends the runner contract and a dedicated bearer', function (): void {
    config(['services.transform.provider' => 'http', 'services.transform.runner_url' => 'http://runner.test', 'services.transform.runner_token' => 'fixture-only']);
    Http::fake(['runner.test/run' => Http::response(['output' => ['score' => 74]])]);
    $result = app(TransformService::class)->execute('python', 'def run(input): return input', ['value' => 74]);
    expect($result->succeeded())->toBeTrue()->and($result->output)->toBe(['score' => 74]);
    Http::assertSent(fn ($request) => $request->url() === 'http://runner.test/run'
        && $request->hasHeader('Authorization', 'Bearer fixture-only')
        && $request['input'] === ['value' => 74] && $request['language'] === 'python'
        && $request['timeout'] === 5);
});

test('external provider rejects invalid and unsuccessful responses without exposing their bodies', function (array $body, int $status): void {
    config(['services.transform.provider' => 'http', 'services.transform.runner_url' => 'http://runner.test']);
    Http::fake(['runner.test/run' => Http::response($body, $status)]);
    $result = app(TransformService::class)->execute('php', 'code', []);
    expect($result->succeeded())->toBeFalse()->and($result->error)->not->toContain('private-fixture');
})->with([
    [['output' => 1], 200], [['error' => 'private-fixture'], 200],
    [['error' => 'private-fixture'], 422], [['output' => []], 302],
]);

test('external provider rejects oversized chunked output', function (): void {
    config(['services.transform.provider' => 'http', 'services.transform.runner_url' => 'http://runner.test', 'services.transform.max_output_bytes' => 64]);
    Http::fake(['runner.test/run' => Http::response(['output' => ['big' => str_repeat('x', 65)]])]);
    $result = app(TransformService::class)->execute('php', 'code', []);
    expect($result->succeeded())->toBeFalse()->and($result->error)->toContain('limit');
});

test('missing external runner and unknown providers fail rather than return raw input', function (): void {
    config(['services.transform.provider' => 'http', 'services.transform.runner_url' => null]);
    Http::fake();
    expect(app(TransformService::class)->execute('php', 'code', [])->succeeded())->toBeFalse();
    Http::assertNothingSent();
    config(['services.transform.provider' => 'unknown']);
    expect(fn () => app(TransformService::class)->execute('php', 'code', []))->toThrow(InvalidArgumentException::class);
});

test('a failed transform preserves good data and its original timestamp', function (): void {
    $owner = User::factory()->create();
    $stamp = now()->subHour();
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'data_strategy' => 'polling', 'polling_url' => 'https://source.test',
        'polling_verb' => 'get', 'transform_enabled' => true, 'transform_language' => 'php',
        'transform_code' => 'function run($input) { throw new Exception("fixture"); }',
        'data_payload' => ['score' => 74], 'data_payload_updated_at' => $stamp]);
    Http::fake(['source.test' => Http::response(['score' => 0])]);
    expect(fn () => $plugin->updateDataPayload())->toThrow(TransformException::class);
    $plugin->refresh();
    expect($plugin->data_payload)->toBe(['score' => 74])->and($plugin->data_payload_updated_at->timestamp)->toBe($stamp->timestamp);
});

test('stored transform code does not execute until the recipe opts in', function (): void {
    $plugin = Plugin::factory()->create(['data_strategy' => 'polling', 'polling_url' => 'https://source.test',
        'polling_verb' => 'get', 'transform_language' => 'php', 'transform_enabled' => false,
        'transform_code' => 'function run($input) { return ["executed"=>true]; }']);
    Http::fake(['source.test' => Http::response(['raw' => true])]);
    $plugin->updateDataPayload();
    expect($plugin->fresh()->data_payload)->toBe(['raw' => true]);
});

test('transform input receives recipe fields but not system or process environment', function (): void {
    $plugin = Plugin::factory()->create(['data_strategy' => 'polling', 'polling_url' => 'https://source.test',
        'polling_verb' => 'get', 'transform_enabled' => true, 'transform_language' => 'php',
        'configuration' => ['display_label' => 'Janus'],
        'transform_code' => 'function run($input) { return ["label" => $input["trmnl"]["plugin_settings"]["custom_fields_values"]["display_label"], "has_system" => isset($input["trmnl"]["system"])]; }']);
    Http::fake(['source.test' => Http::response(['trmnl' => ['system' => ['forged' => true]]])]);
    $plugin->updateDataPayload();
    expect($plugin->fresh()->data_payload)->toBe(['label' => 'Janus', 'has_system' => false]);
});

test('local transform cannot lift memory limits through ini_set', function (): void {
    $result = app(TransformService::class)->execute('php', 'function run($input) { ini_set("memory_limit", "-1"); return []; }', []);
    expect($result->succeeded())->toBeFalse();
});

test('local provider enforces timeout and output budgets', function (): void {
    config(['services.transform.timeout_seconds' => 1]);
    $result = app(TransformService::class)->execute('php', 'function run($input) { while (true) {} }', []);
    expect($result->succeeded())->toBeFalse()->and($result->durationMs)->toBeLessThan(5000);
    config(['services.transform.max_output_bytes' => 1024]);
    $result = app(TransformService::class)->execute('php', 'function run($input) { return ["big" => str_repeat("x", 2000)]; }', []);
    expect($result->succeeded())->toBeFalse();
});

test('local HTTP fetching requires the explicit network setting', function (): void {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $router = tempnam(sys_get_temp_dir(), 'transform-http-');
    file_put_contents($router, '<?php header("Content-Type: application/json"); echo json_encode(["plate_clear"=>true]);');
    $server = new Symfony\Component\Process\Process([PHP_BINARY, '-S', $address, $router]);
    $server->start();
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $probe = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
            if ($probe) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(10000);
        }
        expect($ready)->toBeTrue();
        $code = 'function run($input) { return ["response"=>@file_get_contents($input["url"])]; }';
        $input = ['url' => 'http://'.$address];
        $service = app(TransformService::class);
        expect($service->execute('php', $code, $input)->output['response'])->toBeFalse();
        config(['services.transform.allow_network' => true]);
        $result = $service->execute('php', $code, $input);
        expect($result->succeeded())->toBeTrue()->and(json_decode($result->output['response'], true))->toBe(['plate_clear' => true]);
    } finally {
        $server->stop();
        unlink($router);
    }
});

test('a device keeps its compatible screen when a transform refresh fails', function (): void {
    $owner = User::factory()->create();
    $device = App\Models\Device::factory()->create(['user_id' => $owner->id]);
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'plugin_type' => 'recipe', 'data_strategy' => 'polling',
        'polling_url' => 'https://source.test', 'polling_verb' => 'get', 'data_stale_minutes' => 1,
        'transform_enabled' => true, 'transform_language' => 'php', 'transform_code' => 'function run($input) { return 1; }',
        'data_payload' => ['score' => 74], 'data_payload_updated_at' => now()->subHour()]);
    $plugin->update(['current_image' => 'last-good',
        'current_image_metadata' => App\Services\ImageGenerationService::buildImageMetadataFromDevice($device)]);
    $playlist = App\Models\Playlist::factory()->create(['device_id' => $device->id, 'is_active' => true, 'weekdays' => null, 'active_from' => null, 'active_until' => null]);
    App\Models\PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'plugin_id' => $plugin->id, 'is_active' => true]);
    Http::fake(['source.test' => Http::response(['score' => 0])]);
    $cycle = app(App\Actions\Api\RunDeviceDisplayCycle::class);
    $method = new ReflectionMethod($cycle, 'processPlaylist');
    $result = $method->invoke($cycle, $device);
    expect($result['identity'])->toBe('plugin:'.$plugin->id)->and($device->fresh()->current_screen_image)->toBe('last-good');
    expect($plugin->fresh()->data_payload)->toBe(['score' => 74]);
});

test('a failed mashup transform leaves the current device screen intact', function (): void {
    $owner = User::factory()->create();
    $device = App\Models\Device::factory()->create(['user_id' => $owner->id, 'current_screen_image' => 'last-good-mashup']);
    $plugin = Plugin::factory()->create(['user_id' => $owner->id, 'data_strategy' => 'polling', 'polling_url' => 'https://source.test',
        'polling_verb' => 'get', 'transform_enabled' => true, 'transform_language' => 'php',
        'transform_code' => 'function run($input) { return 1; }', 'data_payload' => ['score' => 74], 'data_payload_updated_at' => now()->subHour()]);
    $playlist = App\Models\Playlist::factory()->create(['device_id' => $device->id, 'is_active' => true, 'weekdays' => null, 'active_from' => null, 'active_until' => null]);
    App\Models\PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'plugin_id' => $plugin->id, 'mashup' => ['layout' => '1Lx1R', 'plugin_ids' => [$plugin->id]], 'is_active' => true]);
    Http::fake(['source.test' => Http::response(['score' => 0])]);
    $cycle = app(App\Actions\Api\RunDeviceDisplayCycle::class);
    $method = new ReflectionMethod($cycle, 'processPlaylist');
    $method->invoke($cycle, $device);
    expect($device->fresh()->current_screen_image)->toBe('last-good-mashup')->and($plugin->fresh()->data_payload)->toBe(['score' => 74]);
});

test('polled tool responses can be mapped directly into a Liquid screen', function (): void {
    $plugin = Plugin::factory()->create(['plugin_type' => 'recipe', 'data_strategy' => 'polling', 'polling_url' => 'https://source.test',
        'polling_verb' => 'post', 'transform_enabled' => true, 'transform_language' => 'php', 'markup_language' => 'liquid',
        'render_markup' => '<div>{{ data.pm25 }} µg/m³</div>',
        'transform_code' => 'function run($input) { return json_decode($input["data"]["structuredContent"]["result"], true); }']);
    Http::fake(['source.test' => Http::response(['success' => true, 'data' => ['structuredContent' => ['result' => '{"pm25":4}']]])]);
    $plugin->updateDataPayload();
    expect($plugin->data_payload)->toBe(['pm25' => 4])->and($plugin->render('full', false))->toContain('4 µg/m³');
});
