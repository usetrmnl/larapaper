<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Services\ImageGenerationService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DisplayAliasController extends Controller
{
    public function __invoke(Request $request, Plugin $plugin): BinaryFileResponse|JsonResponse
    {
        if (! $plugin->alias) {
            return response()->json([
                'message' => 'Alias is not active for this plugin',
            ], 403);
        }

        $deviceModelName = $request->query('device-model', 'og_png');
        $deviceModel = DeviceModel::where('name', $deviceModelName)->first();

        if (! $deviceModel) {
            return response()->json([
                'message' => "Device model '{$deviceModelName}' not found",
            ], 404);
        }

        ImageGenerationService::resetIfNotCacheable($plugin, $deviceModel);
        $plugin->refresh();

        $fileExtension = match ($deviceModel->mime_type) {
            'image/bmp' => 'bmp',
            default => 'png',
        };

        if ($this->canUseCache($plugin, $deviceModelName)) {
            $cachedPath = "images/generated/{$plugin->current_image}.{$fileExtension}";
            if (Storage::disk('public')->exists($cachedPath)) {
                return response()->file(Storage::disk('public')->path($cachedPath), [
                    'Content-Type' => $deviceModel->mime_type,
                ]);
            }
        }

        try {
            if ($plugin->isDataStale()) {
                $plugin->updateDataPayload();
                $plugin->refresh();
            }

            $deviceModel->load('palette');

            $virtualDevice = new Device;
            $virtualDevice->setRelation('deviceModel', $deviceModel);
            $virtualDevice->setRelation('user', $plugin->user);
            $virtualDevice->setRelation('palette', $deviceModel->palette);

            $markup = $plugin->render(device: $virtualDevice);

            $imageUuid = ImageGenerationService::generateImageFromModel(
                markup: $markup,
                deviceModel: $deviceModel,
                user: $plugin->user,
                palette: $deviceModel->palette,
                plugin: $plugin,
            );

            if ($deviceModelName === 'og_png') {
                $update = ['current_image' => $imageUuid];
                if ($plugin->plugin_type === 'recipe') {
                    $update['current_image_metadata'] = ImageGenerationService::buildImageMetadataFromDeviceModel($deviceModel);
                }
                $plugin->update($update);
            }

            $imagePath = Storage::disk('public')->path("images/generated/{$imageUuid}.{$fileExtension}");

            return response()->file($imagePath, [
                'Content-Type' => $deviceModel->mime_type,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to generate alias image for plugin {$plugin->id} ({$plugin->name}): ".$e->getMessage());

            return response()->json([
                'message' => 'Failed to generate image',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function canUseCache(Plugin $plugin, string $deviceModelName): bool
    {
        return $deviceModelName === 'og_png'
            && ! $plugin->isDataStale()
            && $plugin->current_image !== null;
    }
}
