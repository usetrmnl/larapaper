<?php

namespace App\Http\Controllers\Api;

use App\Actions\Api\ProcessPluginImageUpload;
use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Services\ImageGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PluginImageWebhookController extends Controller
{
    public function __construct(private ProcessPluginImageUpload $processUpload) {}

    public function __invoke(Request $request, Plugin $plugin): JsonResponse
    {
        if ($plugin->plugin_type !== 'image_webhook') {
            return response()->json(['error' => 'Plugin is not an image webhook plugin'], 400);
        }

        $result = $this->processUpload->handle($request);

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 400);
        }

        if (! in_array($result['extension'], ['png', 'bmp'], true)) {
            return response()->json(['error' => 'Unsupported image format. Expected PNG or BMP.'], 400);
        }

        $imageUuid = Str::uuid()->toString();
        $path = "images/generated/{$imageUuid}.{$result['extension']}";

        Storage::disk('public')->put($path, $result['content']);

        $plugin->update(['current_image' => $imageUuid]);

        ImageGenerationService::cleanupFolder();

        return response()->json([
            'message' => 'Image uploaded successfully',
            'image_url' => Storage::disk('public')->url($path),
        ]);
    }
}
