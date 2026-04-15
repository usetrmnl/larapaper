<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Playlist extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'weekdays' => 'array',
        'active_from' => 'datetime:H:i',
        'active_until' => 'datetime:H:i',
        'refresh_time' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlaylistItem::class);
    }

    public function isActiveNow(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // Get user's timezone or fall back to app timezone
        $timezone = $this->device->user->timezone ?? config('app.timezone');
        $now = now($timezone);

        // Check weekday (using timezone-aware time)
        if ($this->weekdays !== null && ! in_array($now->dayOfWeek, $this->weekdays)) {
            return false;
        }

        if ($this->active_from !== null && $this->active_until !== null) {
            // Create timezone-aware datetime objects for active_from and active_until
            $activeFrom = $now->copy()
                ->setTimeFrom($this->active_from)
                ->timezone($timezone);

            $activeUntil = $now->copy()
                ->setTimeFrom($this->active_until)
                ->timezone($timezone);

            // Handle time ranges that span across midnight
            if ($activeFrom > $activeUntil) {
                // Time range spans midnight (e.g., 09:01 to 03:58)
                if ($now >= $activeFrom || $now <= $activeUntil) {
                    return true;
                }
            } elseif ($now >= $activeFrom && $now <= $activeUntil) {
                return true;
            }

            return false;
        }

        return true;
    }

    public function getPlaylistConstraintRating(): int
    {
        $score = 0;

        if ($this->active_from !== null && $this->active_until !== null) {
            $score += 2;
        }

        if ($this->weekdays !== null && count($this->weekdays) > 0) {
            $score += 1;
        }

        return $score;
    }

    public function getNextPlaylistItem(): ?PlaylistItem
    {
        if (! $this->isActiveNow()) {
            return null;
        }

        // Get active playlist items ordered by display order
        /** @var \Illuminate\Support\Collection|PlaylistItem[] $playlistItems */
        $playlistItems = $this->items()
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        if ($playlistItems->isEmpty()) {
            return null;
        }

        // Get the last displayed item
        $lastDisplayed = $playlistItems
            ->sortByDesc('last_displayed_at')
            ->first();

        if (! $lastDisplayed || ! $lastDisplayed->last_displayed_at) {
            // If no item has been displayed yet, return the first one
            return $playlistItems->first();
        }

        // Find the next item in sequence
        $currentOrder = $lastDisplayed->order;
        $nextItem = $playlistItems
            ->where('order', '>', $currentOrder)
            ->first();

        // If there's no next item, loop back to the first one
        if (! $nextItem) {
            return $playlistItems->first();
        }

        return $nextItem;
    }
}
