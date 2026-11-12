<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistItemResource;
use App\Models\PlaylistItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlaylistItemController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = PlaylistItem::query()
            ->with(['playlist', 'plugin'])
            ->whereHas('playlist.device', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->orderBy('playlist_id')
            ->orderBy('order')
            ->get();

        return PlaylistItemResource::collection($items);
    }

    public function update(Request $request, PlaylistItem $playlistItem): PlaylistItemResource
    {
        abort_unless($playlistItem->playlist->device->user_id === $request->user()->id, 404);

        $request->validate(['visible' => 'boolean']);

        if ($request->has('visible')) {
            $playlistItem->update(['is_active' => $request->boolean('visible')]);
        }

        return new PlaylistItemResource($playlistItem->refresh());
    }
}
