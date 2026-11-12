<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class PluginSettingsController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $timezone = $user->preferredTimezone();

        // The shape of TRMNL's Account API, which python-trmnl (the Home Assistant integration) requires in full.
        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'first_name' => Str::before($user->name, ' '),
                'last_name' => Str::contains($user->name, ' ') ? Str::after($user->name, ' ') : '',
                'locale' => app()->getLocale(),
                'time_zone' => $timezone,
                'time_zone_iana' => $timezone,
                'utc_offset' => now($timezone)->getOffset(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $plugins = Plugin::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Plugin $plugin): array => [
                'id' => $plugin->trmnlp_id,
                'name' => $plugin->name,
                'plugin_id' => null,
            ]);

        return response()->json(['data' => $plugins]);
    }

    public function store(Request $request): JsonResponse
    {
        $plugin = Plugin::create([
            'user_id' => $request->user()->id,
            'trmnlp_id' => (string) Uuid::v7(),
            'name' => 'New TRMNLP Plugin',
        ]);

        return response()->json([
            'data' => [
                'id' => $plugin->trmnlp_id,
            ],
        ]);
    }

    public function destroy(Request $request, string $trmnlp_id): Response
    {
        Plugin::where('trmnlp_id', $trmnlp_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail()
            ->delete();

        return response()->noContent();
    }
}
