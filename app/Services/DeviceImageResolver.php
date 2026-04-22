<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Storage;

class DeviceImageResolver
{
    /**
     * Resolve the best available image path for a device given a generated image UUID.
     *
     * Picks png or bmp depending on the device model / firmware, and falls back to
     * whichever format exists on disk.
     */
    public function resolve(Device $device, string $imageUuid): string
    {
        $preferred = 'png';

        if (! $device->device_model_id) {
            if (str_contains((string) $device->image_format, 'bmp')) {
                $preferred = 'bmp';
            }

            if (isset($device->last_firmware_version)
                && version_compare($device->last_firmware_version, '1.5.2', '<')
                && Storage::disk('public')->exists("images/generated/{$imageUuid}.bmp")) {
                $preferred = 'bmp';
            }
        }

        foreach ([$preferred, 'png', 'bmp'] as $extension) {
            $path = "images/generated/{$imageUuid}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return "images/generated/{$imageUuid}.bmp";
    }
}
