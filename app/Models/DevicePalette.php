<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array|null $colors
 * @property string|null $framework_class
 */
final class DevicePalette extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'grays' => 'integer',
        'colors' => 'array',
    ];

    /**
     * Framework screen class, or null when the palette does not define one.
     */
    public function frameworkClass(): ?string
    {
        $frameworkClass = $this->framework_class;

        if (! is_string($frameworkClass) || $frameworkClass === '') {
            return null;
        }

        return $frameworkClass;
    }
}
