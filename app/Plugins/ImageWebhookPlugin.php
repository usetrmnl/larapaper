<?php

namespace App\Plugins;

use App\Actions\Api\ProcessPluginImageUpload;
use App\Models\Plugin;
use App\Plugins\Enums\PluginOutput;
use App\Services\ImageGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageWebhookPlugin extends PluginHandler
{
    public const KEY = 'image_webhook';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Image Webhook';
    }

    public function description(): string
    {
        return 'Create a new instance that accepts images via webhook';
    }

    public function icon(): string
    {
        return 'photo';
    }

    public function output(): PluginOutput
    {
        return PluginOutput::ProcessedImage;
    }

    public function defaultAttributes(): array
    {
        return [
            'data_strategy' => 'static',
            'data_stale_minutes' => 60,
        ];
    }

    public function settingsPartial(): ?string
    {
        return 'plugins.image-webhook.settings';
    }

    public function isDataStale(Plugin $plugin): ?bool
    {
        return false;
    }

    public function handleWebhook(Request $request, Plugin $plugin): JsonResponse|array
    {
        $result = app(ProcessPluginImageUpload::class)->handle($request);

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
