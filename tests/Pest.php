<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

arch()
    ->preset()
    ->laravel()
    ->ignoring([
        App\Http\Controllers\Auth\OidcController::class,
        App\Models\DeviceModel::class,
    ]);

arch()
    ->expect('App')
    ->not->toUse(['die', 'dd', 'dump', 'ray']);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// expect()->extend('toBeOne', function () {
//    return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Enable transforms against a faked runner at `http://runner.test`. `$run` receives the
 * request input and code and returns the output object, or a faked response (e.g. an error).
 * `GET /manifest` reports `$languages` as `language_capabilities` (default: every language).
 * Calling it again in the same test replaces the handler and languages.
 *
 * @param  list<string>|null  $languages
 */
function fakeTransformRunner(?Closure $run = null, ?array $languages = null): void
{
    config(['services.transform.provider' => 'http', 'services.transform.runner_url' => 'http://runner.test']);
    Illuminate\Support\Facades\Cache::forget('transform:languages');

    $registered = app()->bound('tests.transform-runner');

    app()->instance('tests.transform-runner', [
        'run' => $run ?? fn (array $input): array => $input,
        'languages' => $languages,
    ]);

    // Every matching stub is evaluated, so the runner is only stubbed once per test.
    if ($registered) {
        return;
    }

    Illuminate\Support\Facades\Http::fake([
        'runner.test/manifest' => fn () => Illuminate\Support\Facades\Http::response([
            'name' => 'Fake Transform Runner',
            'version' => 'test',
            'language_capabilities' => app('tests.transform-runner')['languages'] ?? App\Services\Plugin\Transform\HttpTransformProvider::LANGUAGES,
        ]),
        'runner.test/run' => function (Illuminate\Http\Client\Request $request) {
            $result = app('tests.transform-runner')['run']($request['input'], $request['code']);

            return is_array($result) ? Illuminate\Support\Facades\Http::response(['output' => $result]) : $result;
        },
    ]);
}
