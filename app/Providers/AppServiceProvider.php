<?php

namespace App\Providers;

use App\Services\OidcProvider;
use App\Services\Plugin\Transform\HttpTransformProvider;
use App\Services\Plugin\Transform\TransformProvider;
use App\Services\QrCodeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('qr-code', fn (): QrCodeService => new QrCodeService);

        $this->app->bind(TransformProvider::class, HttpTransformProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->isProduction() && config('app.force_https')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('device-setup', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('device-api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        Request::macro('hasValidSignature', function ($absolute = true, array $ignoreQuery = []) {
            $https = clone $this;
            $https->server->set('HTTPS', 'on');

            $http = clone $this;
            $http->server->set('HTTPS', 'off');
            if (URL::hasValidSignature($https, $absolute, $ignoreQuery)) {
                return true;
            }

            return URL::hasValidSignature($http, $absolute, $ignoreQuery);
        });

        if (config('filesystems.disks.public.driver') === 'local' && config('app.url') === 'http://localhost') {
            config(['filesystems.disks.public.url' => asset('storage')]);
        }

        // Register OIDC provider with Socialite
        Socialite::extend('oidc', function (Application $app): OidcProvider {
            $config = $app->make('config')->get('services.oidc', []);

            return new OidcProvider(
                $app->make(Request::class),
                $config['client_id'] ?? null,
                $config['client_secret'] ?? null,
                $config['redirect'] ?? null,
                $config['scopes'] ?? ['openid', 'profile', 'email']
            );
        });
    }
}
