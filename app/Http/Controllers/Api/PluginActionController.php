<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Plugins\PluginRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic entrypoint for inbound plugin webhooks.
 *
 * Looks up the PluginHandler by the Plugin's plugin_type and delegates to
 * PluginHandler::handleWebhook(). Handlers decide status codes + payloads.
 */
class PluginActionController extends Controller
{
    public function __construct(private readonly PluginRegistry $registry) {}

    public function __invoke(Request $request, Plugin $plugin): JsonResponse
    {
        $handler = $this->registry->get($plugin->plugin_type);

        if ($handler === null) {
            return response()->json([
                'error' => "No handler registered for plugin type [{$plugin->plugin_type}]",
            ], 400);
        }

        $result = $handler->handleWebhook($request, $plugin);

        return $result instanceof JsonResponse
            ? $result
            : response()->json($result);
    }
}
