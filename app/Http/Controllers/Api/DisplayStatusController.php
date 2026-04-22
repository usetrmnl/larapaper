<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DisplayStatusRequest;
use App\Http\Requests\Api\UpdateDisplayStatusRequest;
use App\Http\Resources\DeviceStatusResource;
use App\Models\Device;

class DisplayStatusController extends Controller
{
    public function show(DisplayStatusRequest $request): DeviceStatusResource
    {
        return new DeviceStatusResource($this->authorizedDevice($request));
    }

    public function update(UpdateDisplayStatusRequest $request): DeviceStatusResource
    {
        $device = $this->authorizedDevice($request);
        $device->update($request->updatableFields());

        return new DeviceStatusResource($device->refresh());
    }

    private function authorizedDevice(DisplayStatusRequest|UpdateDisplayStatusRequest $request): Device
    {
        $deviceId = $request->integer('device_id');
        abort_unless($request->user()->devices->contains($deviceId), 403);

        return Device::findOrFail($deviceId);
    }
}
