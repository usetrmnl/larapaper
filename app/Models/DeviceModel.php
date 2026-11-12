<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read array<string, string> $css_variables
 * @property-read string|null $css_name
 * @property-read DevicePalette|null $palette
 */
final class DeviceModel extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'colors' => 'integer',
        'bit_depth' => 'integer',
        'scale_factor' => 'float',
        'rotation' => 'integer',
        'offset_x' => 'integer',
        'offset_y' => 'integer',
        'published_at' => 'datetime',
        'css_variables' => 'array',
    ];

    public function getColorDepthAttribute(): ?string
    {
        if (! $this->bit_depth) {
            return null;
        }

        if ($this->bit_depth === 3) {
            return '2bit';
        }

        // if higher than 4 return 4bit
        if ($this->bit_depth > 4) {
            return '4bit';
        }

        return $this->bit_depth.'bit';
    }

    /**
     * Returns the scale level based on the device width.
     */
    public function getScaleLevelAttribute(): ?string
    {
        if (! $this->width) {
            return null;
        }

        if ($this->width > 800 && $this->width <= 1000) {
            return 'large';
        }

        if ($this->width > 1000 && $this->width <= 1400) {
            return 'xlarge';
        }

        if ($this->width > 1400) {
            return 'xxlarge';
        }

        return null;
    }

    /**
     * Returns css_name for v2 (per-device sizing); for v1 returns 'og' to preserve legacy single-variant behaviour.
     */
    protected function cssName(): Attribute
    {
        return Attribute::get(
            fn (mixed $value): ?string => config('app.puppeteer_window_size_strategy') === 'v2' ? ($value !== null ? (string) $value : null) : 'og'
        );
    }

    public function palette(): BelongsTo
    {
        return $this->belongsTo(DevicePalette::class, 'palette_id');
    }

    /**
     * Framework CSS class for this model's palette, or null when it has none.
     */
    public function paletteClass(): ?string
    {
        return $this->palette?->frameworkClass();
    }

    /**
     * Returns css_variables with --screen-w and --screen-h filled from width/height
     * when puppeteer_window_size_strategy is v2 and they are not set.
     *
     * @return Attribute<array<string, string>, array<string, string>>
     */
    protected function cssVariables(): Attribute
    {
        /** @var Attribute<array<string, string>, array<string, string>> */
        return Attribute::get(
            /** @return array<string, string> */
            function (mixed $value, array $attributes): array {
                $vars = is_array($value) ? $value : (is_string($value) ? (json_decode($value, true) ?? []) : []);

                if (config('app.puppeteer_window_size_strategy') !== 'v2') {
                    return $vars;
                }

                $width = $attributes['width'] ?? null;
                $height = $attributes['height'] ?? null;

                if (empty($vars['--screen-w']) && $width !== null && $width !== '') {
                    $vars['--screen-w'] = is_numeric($width) ? (int) $width.'px' : (string) $width;
                }
                if (empty($vars['--screen-h']) && $height !== null && $height !== '') {
                    $vars['--screen-h'] = is_numeric($height) ? (int) $height.'px' : (string) $height;
                }

                /** @var array<string, string> $vars */
                return $vars;
            });
    }
}
