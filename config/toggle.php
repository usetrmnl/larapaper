<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default toggle driver. Supported: "config", "database"
    |
    | When set to "database", the package will check the database first,
    | then fall back to config if the flag is not found in the database.
    |
    */

    'driver' => env('TOGGLE_DRIVER', 'config'),

    /*
    |--------------------------------------------------------------------------
    | Default Value Behavior
    |--------------------------------------------------------------------------
    |
    | This option controls what happens when a flag is not defined anywhere.
    |
    | Supported: "false" (return false), "true" (return true), "exception" (throw)
    |
    */

    'default' => env('TOGGLE_DEFAULT', 'false'),

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Toggle values are cached for performance. Configure the cache store
    | and TTL (time-to-live) in seconds here.
    |
    */

    'cache' => [
        'enabled' => env('TOGGLE_CACHE_ENABLED', true),
        'store' => env('TOGGLE_CACHE_STORE', null), // null = default cache store
        'ttl' => env('TOGGLE_CACHE_TTL', 3600), // 1 hour
        'prefix' => 'toggle:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table
    |--------------------------------------------------------------------------
    |
    | The table name used when the database driver is enabled.
    |
    */

    'table' => 'toggles',

    /*
    |--------------------------------------------------------------------------
    | Feature Flags (Config-driven)
    |--------------------------------------------------------------------------
    |
    | Define your config-driven feature flags here. When used alongside
    | "database_flags", these will always resolve from the config driver
    | (read-only). Otherwise, the global "driver" setting applies.
    |
    | Example:
    |     'new-checkout' => env('TOGGLE_NEW_CHECKOUT', false),
    |
    */

    'flags' => [
        // 'example-flag' => env('TOGGLE_EXAMPLE_FLAG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database-driven Flags
    |--------------------------------------------------------------------------
    |
    | List flag names that should be resolved from the database. These flags
    | are mutable at runtime via Toggle::enable() and Toggle::disable().
    | If a flag is not found in the database, it will fall back to config.
    |
    | Flags listed here will always use the database driver regardless of the
    | global driver setting.
    |
    | Example:
    |     'maintenance-banner',
    |     'beta-access',
    |
    */

    'database_flags' => [
        //
    ],

];
