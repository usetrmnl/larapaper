<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PrerenderPluginScreenJob;
use App\Models\Device;
use App\Models\Plugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Refresh one recipe now, as TRMNL's POST /api/plugin_settings/{id}/refreshes does:
 * a polling recipe fetches its data again, and the recipe is rendered for the first
 * device that shows it. Unlike TRMNL, it answers without a job_id to poll.
 */
class PluginRefreshController extends Controller
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $plugin = Plugin::query()
            ->where('user_id', $request->user()->id)
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('trmnlp_id', $id))
            ->firstOrFail();

        abort_unless($plugin->plugin_type === 'recipe', 422, 'Only recipes can be refreshed.');

        $device = Device::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('mirror_device_id')
            ->whereHas('playlists', fn ($query) => $query->where('is_active', true)
                ->whereHas('items', fn ($query) => $query->where('is_active', true)->where('plugin_id', $plugin->id)))
            ->orderBy('id')
            ->first();

        if ($device instanceof Device) {
            PrerenderPluginScreenJob::dispatch($plugin, $device);
        } else {
            dispatch(fn () => $plugin->updateDataPayload());
        }

        return response()->json(['data' => ['status' => 'queued']], 202);
    }
}
