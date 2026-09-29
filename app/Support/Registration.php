<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

class Registration
{
    public static function isOpen(): bool
    {
        if (! Schema::hasTable('users')) {
            return false;
        }

        if (User::query()->doesntExist()) {
            return true;
        }

        return (bool) config('app.multiuser_registration.enabled');
    }
}
