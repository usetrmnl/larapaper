<?php

namespace App\Http\Controllers\Api\Firmware;

use App\Http\Controllers\Api\DisplayUpdateController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScreenRequest;
use App\Jobs\GenerateScreenJob;
use App\Support\UserBladeExecution;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Blade;

/**
 * @deprecated Use POST /api/display/update ({@see DisplayUpdateController}) instead.
 */
class ScreenController extends Controller
{
    /**
     * Update a device screen. Requires a Sanctum token with the update-screen
     * ability; the device is selected by the `id` (MAC address) header among
     * the token user's own devices.
     */
    public function store(StoreScreenRequest $request): JsonResponse
    {
        $device = $request->user()->devices()
            ->where('mac_address', mb_strtoupper((string) $request->header('id')))
            ->first();

        if (! $device) {
            return $this->withDeprecationHeaders(response()->json([
                'message' => 'MAC Address not registered',
            ], 404));
        }

        $markup = (string) $request->input('image.content');

        if (UserBladeExecution::allowed()) {
            $view = Blade::render($markup);
        } else {
            $view = $markup;
        }
        GenerateScreenJob::dispatchSync($device->id, null, $view);

        return $this->withDeprecationHeaders(response()->json([
            'message' => 'success',
        ]));
    }

    private function withDeprecationHeaders(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders([
            'Deprecation' => 'true',
            'Link' => '<'.route('display.update').'>; rel="successor-version"',
        ]);
    }
}
