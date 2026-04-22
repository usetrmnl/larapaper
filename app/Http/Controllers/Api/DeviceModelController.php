<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceModelResource;
use App\Models\DeviceModel;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceModelController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DeviceModelResource::collection(DeviceModel::all());
    }
}
