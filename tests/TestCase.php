<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Enable passkeys for the current test, including the named routes the login UI needs.
     *
     * Fortify only registers passkey routes at boot. Runtime `config()` changes
     * cannot add them, and rebooting still reads PASSKEYS_ENABLED from `.env`.
     */
    protected function bootWithPasskeysEnabled(): void
    {
        config(['app.passkeys.enabled' => true]);

        Features::passkeys([
            'confirmPassword' => true,
        ]);

        $features = array_values(array_filter(config('fortify.features', [])));
        $passkeysFeature = Features::passkeys();

        if (! in_array($passkeysFeature, $features, true)) {
            $features[] = $passkeysFeature;
        }

        config(['fortify.features' => $features]);

        if (! Route::has('passkey.login-options')) {
            Route::get('/passkeys/login/options', fn () => response()->noContent())
                ->middleware('web')
                ->name('passkey.login-options');
        }

        if (! Route::has('passkey.login')) {
            Route::post('/passkeys/login', fn () => response()->noContent())
                ->middleware('web')
                ->name('passkey.login');
        }

        Route::getRoutes()->refreshNameLookups();
    }
}
