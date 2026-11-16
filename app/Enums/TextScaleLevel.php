<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * TRMNL framework text scale, applied as the screen's `screen--text-scale-{value}` class.
 * Scales typography only; the interface scale (layout and text) is {@see ScaleLevel}.
 */
enum TextScaleLevel: string
{
    case SMALL = 'small';
    case REGULAR = 'regular';
    case LARGE = 'large';
    case XLARGE = 'xlarge';

    public function label(): string
    {
        return match ($this) {
            self::SMALL => 'Small',
            self::REGULAR => 'Regular',
            self::LARGE => 'Large',
            self::XLARGE => 'X-Large',
        };
    }
}
