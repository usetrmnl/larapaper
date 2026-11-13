<?php

namespace App\Http\Resources;

use App\Models\PlaylistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A playlist item as returned by the account API (GET /api/playlists/{id}/items).
 *
 * @mixin PlaylistItem
 */
class PlaylistItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'playlist_id' => $this->playlist_id,
            'plugin_id' => $this->plugin_id,
            'plugin_name' => $this->plugin?->name,
            'order' => $this->order,
            'is_active' => $this->is_active,
            'last_displayed_at' => $this->last_displayed_at?->toIso8601String(),
            'is_mashup' => $this->isMashup(),
            'mashup' => $this->isMashup() ? [
                'layout' => $this->getMashupLayoutType(),
                'name' => $this->getMashupName(),
                'plugin_ids' => $this->getMashupPluginIds(),
            ] : null,
            'supports_image_cache' => $this->supportsImageCache(),
        ];
    }
}
