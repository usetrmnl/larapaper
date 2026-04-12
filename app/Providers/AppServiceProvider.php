<?php

namespace App\Providers;

use App\Services\OidcProvider;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
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
        $this->app->bind('qr-code', fn () => new QrCodeService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->isProduction() && config('app.force_https')) {
            URL::forceScheme('https');
        }

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
        Socialite::extend('oidc', function (\Illuminate\Contracts\Foundation\Application $app): OidcProvider {
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
