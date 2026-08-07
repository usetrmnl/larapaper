<?php

use App\Services\Plugin\ServerlessTransformService;
use Illuminate\Support\Facades\Http;

it('returns transformed output when runner responds with 200', function (): void {
    config(['services.transform_runner.url' => 'http://runner:3000']);
    Http::fake(['*/run' => Http::response(['output' => ['doubled' => 10]], 200)]);

    $result = (new ServerlessTransformService())->run('...', 'php', ['value' => 5]);

    expect($result)->toBe(['doubled' => 10]);
});

it('returns input unchanged when runner URL is not configured', function (): void {
    config(['services.transform_runner.url' => null]);
    Http::fake();

    $input = ['key' => 'value'];
    $result = (new ServerlessTransformService())->run('...', 'php', $input);

    expect($result)->toBe($input);
    Http::assertNothingSent();
});

it('returns error payload when runner returns 422', function (): void {
    config(['services.transform_runner.url' => 'http://runner:3000']);
    Http::fake(['*/run' => Http::response(['error' => 'syntax error'], 422)]);

    $result = (new ServerlessTransformService())->run('bad code', 'php', ['key' => 'value']);

    expect($result)->toHaveKey('error');
});

it('returns error payload when runner returns 500', function (): void {
    config(['services.transform_runner.url' => 'http://runner:3000']);
    Http::fake(['*/run' => Http::response([], 500)]);

    $result = (new ServerlessTransformService())->run('...', 'php', ['key' => 'value']);

    expect($result)->toHaveKey('error');
});

it('returns runner body when runner output is not an array', function (): void {
    config(['services.transform_runner.url' => 'http://runner:3000']);
    Http::fake(['*/run' => Http::response(['output' => null], 200)]);

    $result = (new ServerlessTransformService())->run('...', 'php', ['key' => 'value']);

    expect($result)->toBe(['output' => null]);
});

it('returns error payload when connection fails', function (): void {
    config(['services.transform_runner.url' => 'http://runner:3000']);
    Http::fake(['*/run' => function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
    }]);

    $result = (new ServerlessTransformService())->run('...', 'php', ['key' => 'value']);

    expect($result)->toHaveKey('error');
});

it('returns error payload when runner returns 400 for unsupported language', function (): void {
    config(['services.transform_runner.url' => 'http://runner:3000']);
    Http::fake(['*/run' => Http::response(['error' => 'unsupported language'], 400)]);

    $result = (new ServerlessTransformService())->run('<?php echo "x";', 'php', ['key' => 'value']);

    expect($result)->toHaveKey('error');
});

it('isEnabled returns true when runner URL is configured', function (): void {
    config(['services.transform_runner.url' => 'http://runner:3000']);
    expect((new ServerlessTransformService())->isEnabled())->toBeTrue();
});

it('isEnabled returns false when runner URL is not configured', function (): void {
    config(['services.transform_runner.url' => null]);
    expect((new ServerlessTransformService())->isEnabled())->toBeFalse();
});
