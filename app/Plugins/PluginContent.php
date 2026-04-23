<?php

namespace App\Plugins;

use App\Plugins\Enums\PluginOutput;

/**
 * Immutable value object returned by PluginHandler::produce().
 *
 * Exactly one of $html, $binary, or $uuid is populated depending on $type.
 */
final class PluginContent
{
    private function __construct(
        public readonly PluginOutput $type,
        public readonly ?string $html = null,
        public readonly ?string $binary = null,
        public readonly ?string $uuid = null,
        public readonly ?string $extension = null,
    ) {}

    public static function html(string $markup): self
    {
        return new self(
            type: PluginOutput::Html,
            html: $markup,
        );
    }

    public static function image(string $binary, string $extension): self
    {
        return new self(
            type: PluginOutput::Image,
            binary: $binary,
            extension: mb_strtolower($extension),
        );
    }

    public static function processedImage(string $uuid, string $extension): self
    {
        return new self(
            type: PluginOutput::ProcessedImage,
            uuid: $uuid,
            extension: mb_strtolower($extension),
        );
    }
}
