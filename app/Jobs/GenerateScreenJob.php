<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\Plugin;
use App\Plugins\Enums\PluginOutput;
use App\Services\ImageGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateScreenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $deviceId,
        private readonly ?int $pluginId,
        private readonly string $markup
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $plugin = $this->pluginId ? Plugin::find($this->pluginId) : null;
        $output = $plugin?->handler()?->output();

        // ProcessedImage: plugin stores device-ready image.
        if ($output === PluginOutput::ProcessedImage) {
            if ($plugin->current_image !== null) {
                Device::find($this->deviceId)->update(['current_screen_image' => $plugin->current_image]);
            }

            return;
        }

        // Html, Image, or default: same pipeline and plugin fields after generation.
        $newImageUuid = ImageGenerationService::generateImage($this->markup, $this->deviceId, $plugin);

        if ($plugin) {
            $plugin->update([
                'current_image' => $newImageUuid,
                'current_image_metadata' => $this->imageMetadataForDevice(),
                'data_payload_updated_at' => now(),
            ]);
        }

        ImageGenerationService::cleanupFolder();
    }

    private function imageMetadataForDevice(): array
    {
        $device = Device::with(['deviceModel', 'deviceModel.palette'])->findOrFail($this->deviceId);

        return ImageGenerationService::buildImageMetadataFromDevice($device);
    }
}
