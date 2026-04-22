<?php

namespace App\Actions\Api;

use Illuminate\Http\Request;

class ProcessPluginImageUpload
{
    /**
     * Extract image binary + extension from a plugin webhook request.
     *
     * Supports multipart uploads, base64 data URIs, and raw binary bodies.
     * Returns ['content' => string, 'extension' => string] on success, or
     * ['error' => string] when the payload is invalid.
     *
     * @return array{content: string, extension: string}|array{error: string}
     */
    public function handle(Request $request): array
    {
        if ($request->hasFile('image')) {
            $file = $request->file('image');

            return [
                'content' => $file->get(),
                'extension' => mb_strtolower($file->getClientOriginalExtension()),
            ];
        }

        if ($request->has('image')) {
            $imageData = $request->input('image');
            if (! preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
                return ['error' => 'Invalid image format. Expected base64 data URI.'];
            }

            return [
                'content' => base64_decode(mb_substr($imageData, mb_strpos($imageData, ',') + 1)),
                'extension' => mb_strtolower($matches[1]),
            ];
        }

        $image = $request->getContent();
        $contentType = $request->header('Content-Type', '');
        $trimmed = mb_trim($image);

        if (empty($image) || $trimmed === '' || $trimmed === '{}') {
            return ['error' => 'No image data provided'];
        }

        if (str_contains($contentType, 'application/json')) {
            return ['error' => 'No image data provided'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $image);
        finfo_close($finfo);

        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/bmp' => 'bmp',
            default => null,
        };

        if (! $extension) {
            return ['error' => 'Unsupported image format. Expected PNG or BMP.'];
        }

        return [
            'content' => $image,
            'extension' => $extension,
        ];
    }
}
