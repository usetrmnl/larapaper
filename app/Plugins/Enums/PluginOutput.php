<?php

namespace App\Plugins\Enums;

/**
 * Describes the stage of the e-paper rendering pipeline a plugin's output enters.
 */
enum PluginOutput: string
{
    /**
     * HTML markup. Requires BrowserStage + ImageStage (full trmnl-pipeline-php run).
     */
    case Html = 'html';

    /**
     * Raw PNG/JPEG/BMP bytes. Skips BrowserStage; still requires ImageStage
     * (resize, dither, palette, bit-depth) to be e-paper ready.
     */
    case Image = 'image';

    /**
     * Already formatted for the target device (dithered, palette-mapped, correct
     * dimensions/bit-depth). Pipeline is bypassed entirely; written straight to
     * images/generated and assigned to plugins.current_image.
     */
    case ProcessedImage = 'processed_image';
}
