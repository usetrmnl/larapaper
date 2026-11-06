<?php

namespace App\Support;

use App\Models\User;

class UserBladeExecution
{
    public const string DISABLED_MESSAGE = 'Blade markup is disabled on this server. Use Liquid, or set INSECURE_BLADE_ALLOW_PHP_EXECUTION=true.';

    /**
     * Explicit INSECURE_BLADE_ALLOW_PHP_EXECUTION wins; otherwise Blade is only allowed for a single user.
     */
    public static function allowed(): bool
    {
        $configured = config('app.blade.allow_php_execution');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return User::query()->count() < 2;
    }
}
