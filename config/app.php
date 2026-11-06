<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'LaraPaper'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-user registration (MULTIUSER_REGISTRATION_ENABLED)
    |--------------------------------------------------------------------------
    |
    | The first account can always be created. When this flag is true, further
    | accounts can register and user-authored Blade is disabled unless
    | allow_php_execution is set. Those accounts share this server.
    |
    */

    'multiuser_registration' => [
        'enabled' => filter_var(env('MULTIUSER_REGISTRATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Insecure Blade PHP execution
    |--------------------------------------------------------------------------
    |
    | User-authored Blade is compiled to PHP. In multi-user mode that is remote
    | code execution for every registered user. When unset, Blade is allowed
    | for a single user and disabled once a second user exists. Set true or
    | false to force it on or off regardless of the number of users.
    |
    */

    'blade' => [
        'allow_php_execution' => in_array(env('INSECURE_BLADE_ALLOW_PHP_EXECUTION'), [null, ''], true)
            ? null
            : filter_var(env('INSECURE_BLADE_ALLOW_PHP_EXECUTION'), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Passkeys (PASSKEYS_ENABLED)
    |--------------------------------------------------------------------------
    |
    | When false, passkey UI is hidden and Fortify does not register passkey
    | routes. Defined here (not in a dedicated file) so the value is available
    | before config/fortify.php is loaded.
    |
    */

    'passkeys' => [
        'enabled' => filter_var(env('PASSKEYS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'pixel_logo_enabled' => env('PIXELLOGO_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds for outbound HTTP client requests when fetching
    | recipe polling data. Increase for slow upstream APIs.
    |
    */

    'http_client_timeout' => (int) env('HTTP_CLIENT_TIMEOUT', 15),

    'force_https' => env('FORCE_HTTPS', false),
    'puppeteer_docker' => env('PUPPETEER_DOCKER', false),
    'puppeteer_mode' => env('PUPPETEER_MODE', 'local'),
    'puppeteer_wait_for_network_idle' => env('PUPPETEER_WAIT_FOR_NETWORK_IDLE', true),
    'puppeteer_window_size_strategy' => env('PUPPETEER_WINDOW_SIZE_STRATEGY', 'v2'),

    'notifications' => [
        'battery_low' => [
            'warn_at_percent' => env('NOTIFICATION_BATTERYLOW_WARNATPERCENT', 20),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | This value is the version of your application, which will be used when
    | displaying the version number in the UI. This is set during the Docker
    | build process from the release tag.
    |
    */

    'version' => env('APP_VERSION', null),

    'catalog_url' => env('CATALOG_URL', 'https://raw.githubusercontent.com/bnussbau/trmnl-recipe-catalog/refs/heads/main/catalog.yaml'),

    'github_repo' => env('GITHUB_REPO', 'usetrmnl/larapaper'),
];
