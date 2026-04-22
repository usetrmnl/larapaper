<?php

namespace App\Http\Controllers\Api\Firmware;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScreenRequest;
use App\Jobs\GenerateScreenJob;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Blade;

class ScreenController extends Controller
{
    public function store(StoreScreenRequest $request): JsonResponse
    {
        $device = Device::where('mac_address', mb_strtoupper((string) $request->header('id')))
            ->where('api_key', $request->header('access-token'))
            ->first();

        if (! $device) {
            return response()->json([
                'message' => 'MAC Address not registered or invalid access token',
            ], 404);
        }

        $view = Blade::render($request->input('image.content'));
        GenerateScreenJob::dispatchSync($device->id, null, $view);

        return response()->json([
            'message' => 'success',
        ]);
    }
}
