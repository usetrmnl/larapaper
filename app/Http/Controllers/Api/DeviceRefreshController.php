<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PrerenderPluginScreenJob;
use App\Models\Device;
use App\Models\Playlist;
use App\Models\Plugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Poll and render the polling recipes in a device's active playlists now, whether or
 * not their data is stale, so the device shows fresh screens from its next request.
 * A mirror refreshes the device it mirrors. TRMNL's API refreshes one plugin at a
 * time (PluginRefreshController); this refreshes all a device shows.
 */
class DeviceRefreshController extends Controller
{
    public function __invoke(Request $request, Device $device): JsonResponse
    {
        abort_unless($device->user_id === $request->user()->id, 404);

        $device = $device->mirrorDevice ?? $device;

        $this->plugins($device)->each(fn (Plugin $plugin) => PrerenderPluginScreenJob::dispatch($plugin, $device));

        return response()->json(['data' => ['status' => 'queued']], 202);
    }

    /**
     * The polling recipes the device shows now. Mashups are left out, as the display
     * cycle renders them on every request.
     *
     * @return Collection<int, Plugin>
     */
    private function plugins(Device $device): Collection
    {
        return $device->playlists()->where('is_active', true)->get()
            ->filter(fn (Playlist $playlist): bool => $playlist->isActiveNow())
            ->flatMap(fn (Playlist $playlist): Collection => $playlist->getActiveItems())
            ->reject->isMashup()
            ->map->plugin
            ->filter(fn (?Plugin $plugin): bool => $plugin?->plugin_type === 'recipe' && $plugin->data_strategy === 'polling')
            ->unique('id')
            ->values();
    }
}
