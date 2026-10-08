<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * TRMNL framework interface scale, applied as the screen's `screen--scale-{value}` class.
 */
enum ScaleLevel: string
{
    case XXSMALL = 'xxsmall';
    case XSMALL = 'xsmall';
    case SMALL = 'small';
    case REGULAR = 'regular';
    case LARGE = 'large';
    case XLARGE = 'xlarge';
    case XXLARGE = 'xxlarge';

    public function label(): string
    {
        return match ($this) {
            self::XXSMALL => 'XX-Small',
            self::XSMALL => 'X-Small',
            self::SMALL => 'Small',
            self::REGULAR => 'Regular',
            self::LARGE => 'Large',
            self::XLARGE => 'X-Large',
            self::XXLARGE => 'XX-Large',
        };
    }
}
