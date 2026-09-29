<?php

namespace App\Support;

class UserBladeExecution
{
    public const string DISABLED_MESSAGE = 'Blade markup is disabled while multi-user mode is enabled. Use Liquid, or set INSECURE_BLADE_ALLOW_PHP_EXECUTION=true.';

    public static function allowed(): bool
    {
        if (! config('app.multiuser_registration.enabled')) {
            return true;
        }

        return (bool) config('app.blade.allow_php_execution');
    }
}
