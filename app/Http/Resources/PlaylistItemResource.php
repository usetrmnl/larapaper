<?php

namespace App\Http\Resources;

use App\Models\PlaylistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A playlist item in the shape of TRMNL's Account API (GET /api/playlists/items).
 * A LaraPaper plugin is both TRMNL's plugin and plugin setting.
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
            'device_id' => $this->playlist->device_id,
            'plugin_setting_id' => $this->plugin_id,
            'mashup_id' => null,
            'mirror' => false,
            'row_order' => $this->order,
            'visible' => $this->is_active,
            'rendered_at' => $this->last_displayed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'plugin_setting' => [
                'id' => $this->plugin_id,
                'name' => $this->getMashupName() ?? $this->plugin->name,
                'plugin_id' => $this->plugin_id,
            ],
        ];
    }
}
