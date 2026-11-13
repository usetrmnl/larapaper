<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistItemResource;
use App\Jobs\RenderPlaylistItemImageJob;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlaylistItemController extends Controller
{
    public function index(Request $request, Playlist $playlist): AnonymousResourceCollection
    {
        abort_unless($playlist->device->user_id === $request->user()->id, 404);

        $items = $playlist->items()
            ->with('plugin')
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return PlaylistItemResource::collection($items);
    }

    /**
     * Drop the cached generated image so the next display cycle re-renders the plugin.
     */
    public function clearCache(Request $request, PlaylistItem $playlistItem): PlaylistItemResource
    {
        $this->authorizeCacheAction($request, $playlistItem);

        $playlistItem->plugin->clearCurrentImage();

        return new PlaylistItemResource($playlistItem);
    }

    /**
     * Queue a pre-render of the item's image when nothing usable is cached.
     */
    public function warmCache(Request $request, PlaylistItem $playlistItem): JsonResponse
    {
        $this->authorizeCacheAction($request, $playlistItem);

        RenderPlaylistItemImageJob::dispatch($playlistItem->id, force: false);

        return (new PlaylistItemResource($playlistItem))->response()->setStatusCode(202);
    }

    /**
     * Queue a re-fetch and re-render that replaces the item's cached image.
     */
    public function refreshCache(Request $request, PlaylistItem $playlistItem): JsonResponse
    {
        $this->authorizeCacheAction($request, $playlistItem);

        RenderPlaylistItemImageJob::dispatch($playlistItem->id, force: true);

        return (new PlaylistItemResource($playlistItem))->response()->setStatusCode(202);
    }

    private function authorizeCacheAction(Request $request, PlaylistItem $playlistItem): void
    {
        $playlistItem->loadMissing(['playlist.device', 'plugin']);

        abort_unless($playlistItem->playlist->device->user_id === $request->user()->id, 404);
        abort_unless($playlistItem->supportsImageCache(), 422, 'Image cache is only available for recipe items.');
        abort_unless($playlistItem->plugin->user_id === $request->user()->id, 403);
    }
}
