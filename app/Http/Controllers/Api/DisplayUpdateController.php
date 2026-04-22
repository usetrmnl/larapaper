<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateDisplayRequest;
use App\Jobs\GenerateScreenJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Blade;

class DisplayUpdateController extends Controller
{
    public function __invoke(UpdateDisplayRequest $request): JsonResponse
    {
        $deviceId = $request->integer('device_id');
        abort_unless($request->user()->devices->contains($deviceId), 403);

        $view = Blade::render($request->input('markup'));

        GenerateScreenJob::dispatchSync($deviceId, null, $view);

        return response()->json([
            'message' => 'success',
        ]);
    }
}
