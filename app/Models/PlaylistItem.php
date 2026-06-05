<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaylistItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_displayed_at' => 'datetime',
        'mashup' => 'json',
    ];

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class);
    }

    /**
     * Check if this playlist item is a mashup
     */
    public function isMashup(): bool
    {
        return ! is_null($this->mashup);
    }

    /**
     * Get the mashup name if this is a mashup
     */
    public function getMashupName(): ?string
    {
        return $this->mashup['mashup_name'] ?? null;
    }

    /**
     * Get the mashup layout type if this is a mashup
     */
    public function getMashupLayoutType(): ?string
    {
        return $this->mashup['mashup_layout'] ?? null;
    }

    /**
     * Get all plugin IDs for this mashup
     */
    public function getMashupPluginIds(): array
    {
        return $this->mashup['plugin_ids'] ?? [];
    }

    /**
     * Get the number of plugins required for the current layout
     */
    public function getRequiredPluginCount(): int
    {
        if (! $this->isMashup()) {
            return 1;
        }

        return match ($this->getMashupLayoutType()) {
            '1Lx1R', '1Tx1B' => 2,  // Left-Right or Top-Bottom split
            '1Lx2R', '2Lx1R', '2Tx1B', '1Tx2B' => 3,  // Two on one side, one on other
            '2x2' => 4,  // Quadrant
            default => 1,
        };
    }

    /**
     * Get the layout type (horizontal, vertical, or grid)
     */
    public function getLayoutType(): string
    {
        if (! $this->isMashup()) {
            return 'single';
        }

        return match ($this->getMashupLayoutType()) {
            '1Lx1R', '1Lx2R', '2Lx1R' => 'vertical',
            '1Tx1B', '2Tx1B', '1Tx2B' => 'horizontal',
            '2x2' => 'grid',
            default => 'single',
        };
    }

    /**
     * Get the layout size for a plugin based on its position
     */
    public function getLayoutSize(int $position = 0): string
    {
        if (! $this->isMashup()) {
            return 'full';
        }

        return match ($this->getMashupLayoutType()) {
            '1Lx1R' => 'half_vertical',  // Both sides are single plugins
            '1Tx1B' => 'half_horizontal',  // Both sides are single plugins
            '2Lx1R' => match ($position) {
                0, 1 => 'quadrant',  // Left side has 2 plugins
                2 => 'half_vertical',  // Right side has 1 plugin
                default => 'full'
            },
            '1Lx2R' => match ($position) {
                0 => 'half_vertical',  // Left side has 1 plugin
                1, 2 => 'quadrant',  // Right side has 2 plugins
                default => 'full'
            },
            '2Tx1B' => match ($position) {
                0, 1 => 'quadrant',  // Top side has 2 plugins
                2 => 'half_horizontal',  // Bottom side has 1 plugin
                default => 'full'
            },
            '1Tx2B' => match ($position) {
                0 => 'half_horizontal',  // Top side has 1 plugin
                1, 2 => 'quadrant',  // Bottom side has 2 plugins
                default => 'full'
            },
            '2x2' => 'quadrant',  // All positions are quadrants
            default => 'full'
        };
    }

    /**
     * Render all plugins with appropriate layout
     */
    public function render(?Device $device = null): string
    {
        if (! $this->isMashup()) {
            return view('trmnl-layouts.single', [
                'colorDepth' => $device?->colorDepth(),
                'deviceVariant' => $device?->deviceModel?->css_name ?? $device?->deviceVariant() ?? 'og',
                'scaleLevel' => $device?->scaleLevel(),
                'cssVariables' => $device?->deviceModel?->css_variables,
                'frameworkVersion' => $this->plugin instanceof Plugin
                    ? $this->plugin->resolvedFrameworkVersion()
                    : null,
                'slot' => $this->plugin instanceof Plugin
                    ? $this->plugin->render('full', false, $device)
                    : throw new Exception('Invalid plugin instance'),
            ])->render();
        }

        $pluginMarkups = [];
        $pluginIds = $this->getMashupPluginIds();
        $plugins = Plugin::whereIn('id', $pluginIds)->get();

        // Sort the collection to match plugin_ids order
        $plugins = $plugins->sortBy(fn ($plugin): int|string|false => array_search($plugin->id, $pluginIds))->values();

        foreach ($plugins as $index => $plugin) {
            $size = $this->getLayoutSize($index);
            $pluginMarkups[] = $plugin->render($size, false, $device);
        }

        // Framework CSS/JS loads once per screen; first plugin in mashup order sets the version.
        $frameworkVersion = $plugins->first()?->resolvedFrameworkVersion();

        return view('trmnl-layouts.mashup', [
            'colorDepth' => $device?->colorDepth(),
            'deviceVariant' => $device?->deviceModel?->css_name ?? $device?->deviceVariant() ?? 'og',
            'scaleLevel' => $device?->scaleLevel(),
            'cssVariables' => $device?->deviceModel?->css_variables,
            'frameworkVersion' => $frameworkVersion,
            'mashupLayout' => $this->getMashupLayoutType(),
            'slot' => implode('', $pluginMarkups),
        ])->render();
    }

    /**
     * Available mashup layouts with their descriptions
     */
    public static function getAvailableLayouts(): array
    {
        return [
            '1Lx1R' => '1 Left - 1 Right (2 plugins)',
            '1Lx2R' => '1 Left - 2 Right (3 plugins)',
            '2Lx1R' => '2 Left - 1 Right (3 plugins)',
            '1Tx1B' => '1 Top - 1 Bottom (2 plugins)',
            '2Tx1B' => '2 Top - 1 Bottom (3 plugins)',
            '1Tx2B' => '1 Top - 2 Bottom (3 plugins)',
            '2x2' => 'Quadrant (4 plugins)',
        ];
    }

    /**
     * Get the required number of plugins for a given layout
     */
    public static function getRequiredPluginCountForLayout(string $layout): int
    {
        return match ($layout) {
            '1Lx1R', '1Tx1B' => 2,
            '1Lx2R', '2Lx1R', '2Tx1B', '1Tx2B' => 3,
            '2x2' => 4,
            default => 1,
        };
    }

    /**
     * Create a new mashup with the given layout and plugins
     */
    public static function createMashup(Playlist $playlist, string $layout, array $pluginIds, string $name, $order): self
    {
        return static::create([
            'playlist_id' => $playlist->id,
            'plugin_id' => $pluginIds[0], // First plugin is the main plugin
            'mashup' => [
                'mashup_layout' => $layout,
                'mashup_name' => $name,
                'plugin_ids' => $pluginIds,
            ],
            'is_active' => true,
            'order' => $order,
        ]);
    }
}
