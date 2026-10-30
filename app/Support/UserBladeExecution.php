<?php

namespace App\Support;

use App\Models\User;

class UserBladeExecution
{
    public const string DISABLED_MESSAGE = 'Blade markup is disabled while multi-user mode is enabled. Use Liquid, or set INSECURE_BLADE_ALLOW_PHP_EXECUTION=true.';

    public static function allowed(): bool
    {
        if (User::query()->count() < 2) {
            return true;
        }

        return (bool) config('app.blade.allow_php_execution');
    }
}
