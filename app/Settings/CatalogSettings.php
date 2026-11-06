<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class CatalogSettings extends Settings
{
    public bool $oss_3rd_party_warning_dismissed = false;

    public static function group(): string
    {
        return 'catalog';
    }
}
