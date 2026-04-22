<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PluginWebhookController extends Controller
{
    public function __invoke(Request $request, Plugin $plugin): JsonResponse
    {
        if ($plugin->data_strategy !== 'webhook') {
            return response()->json(['error' => 'Plugin does not use webhook strategy'], 400);
        }

        if (! $request->has('merge_variables')) {
            return response()->json(['error' => 'Request must contain merge_variables key'], 400);
        }

        $mergeVariables = $request->input('merge_variables');

        if (! Plugin::dataPayloadWithinWireLimit($mergeVariables)) {
            return response()->json(Plugin::oversizedDataPayloadErrorPayload(), 413);
        }

        $plugin->update([
            'data_payload' => $mergeVariables,
            'data_payload_updated_at' => now(),
        ]);

        return response()->json(['message' => 'Data updated successfully']);
    }
}
