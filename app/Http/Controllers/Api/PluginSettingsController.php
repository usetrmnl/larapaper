<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class PluginSettingsController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $plugins = Plugin::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Plugin $plugin) => [
                'id' => $plugin->trmnlp_id,
                'name' => $plugin->name,
                'plugin_id' => null,
            ]);

        return response()->json($plugins);
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
