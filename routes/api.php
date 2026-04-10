<?php

use App\Jobs\GenerateScreenJob;
use App\Models\Device;
use App\Models\DeviceLog;
use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Models\User;
use App\Services\DeviceSensorService;
use App\Services\ImageGenerationService;
use App\Services\PluginImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Route::get('/display', function (Request $request, DeviceSensorService $sensorService) {
    $mac_address = $request->header('id');
    $access_token = $request->header('access-token');
    $device = Device::where('api_key', $access_token)->first();

    if (! $device) {
        // Check if there's a user with assign_new_devices enabled
        $auto_assign_user = User::where('assign_new_devices', true)->first();

        if ($auto_assign_user && $mac_address) {
            // Create a new device and assign it to this user
            $device = Device::create([
                'mac_address' => mb_strtoupper($mac_address ?? ''),
                'api_key' => $access_token,
                'user_id' => $auto_assign_user->id,
                'name' => "{$auto_assign_user->name}'s TRMNL",
                'friendly_id' => Str::random(6),
                'default_refresh_interval' => 900,
                'mirror_device_id' => $auto_assign_user->assign_new_device_id,
            ]);
        } else {
            return response()->json([
                'message' => 'MAC Address not registered (or not set), or invalid access token',
            ], 404);
        }
    }

    $device->update([
        'last_rssi_level' => $request->header('rssi'),
        'last_battery_voltage' => $request->header('battery_voltage'),
        'last_firmware_version' => $request->header('fw-version'),
        'last_refreshed_at' => now(),
    ]);

    $batteryPercent = $request->header('battery-percent') ?? $request->header('percent-charged');
    if ($batteryPercent !== null) {
        $batteryPercent = (int) $batteryPercent;
        $batteryVoltage = $device->calculateVoltageFromPercent($batteryPercent);
        $device->update([
            'last_battery_voltage' => $batteryVoltage,
        ]);
    }

    $sensorHeader = $request->server('HTTP_SENSORS') ?? $request->header('http_sensors');
    if ($sensorHeader) {
        try {
            $sensorService->ingestFromHeader($device, $sensorHeader);
        } catch (Exception $e) {
            Log::warning('Failed to ingest device sensor header', [
                'device_id' => $device->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    if ($device->isPauseActive()) {
        $image_path = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'sleep');
        if (! $image_path) {
            // Generate from template if no device-specific image exists
            $image_uuid = ImageGenerationService::generateDefaultScreenImage($device, 'sleep');
            $image_path = 'images/generated/'.$image_uuid.'.png';
        }
        $filename = basename($image_path);
        $refreshTimeOverride = (int) now()->diffInSeconds($device->pause_until);
    } elseif ($device->isSleepModeActive()) {
        $image_path = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'sleep');
        if (! $image_path) {
            // Generate from template if no device-specific image exists
            $image_uuid = ImageGenerationService::generateDefaultScreenImage($device, 'sleep');
            $image_path = 'images/generated/'.$image_uuid.'.png';
        }
        $filename = basename($image_path);
        $refreshTimeOverride = $device->getSleepModeEndsInSeconds() ?? $device->default_refresh_interval;
    } else {
        // Get current screen image from a mirror device or continue if not available
        if (! $image_uuid = $device->mirrorDevice?->current_screen_image) {
            $refreshTimeOverride = null;
            // Skip if cloud proxy is enabled for the device
            if (! $device->proxy_cloud || $device->getNextPlaylistItem()) {
                $playlistItem = $device->getNextPlaylistItem();

                if ($playlistItem && ! $playlistItem->isMashup()) {
                    $refreshTimeOverride = $playlistItem->playlist()->first()->refresh_time;
                    $plugin = $playlistItem->plugin;

                    ImageGenerationService::resetIfNotCacheable($plugin, $device);
                    $plugin->refresh();

                    // Check and update stale data if needed
                    if ($plugin->isDataStale() || $plugin->current_image === null) {
                        $plugin->updateDataPayload();
                        try {
                            $markup = $plugin->render(device: $device);

                            GenerateScreenJob::dispatchSync($device->id, $plugin->id, $markup);
                        } catch (Exception $e) {
                            Log::error("Failed to render plugin {$plugin->id} ({$plugin->name}): ".$e->getMessage());
                            // Generate error display
                            $errorImageUuid = ImageGenerationService::generateDefaultScreenImage($device, 'error', $plugin->name);
                            $device->update(['current_screen_image' => $errorImageUuid]);
                        }
                    }

                    $plugin->refresh();

                    if ($plugin->current_image !== null) {
                        $playlistItem->update(['last_displayed_at' => now()]);
                        $device->update(['current_screen_image' => $plugin->current_image]);
                    }
                } elseif ($playlistItem) {
                    $refreshTimeOverride = $playlistItem->playlist()->first()->refresh_time;

                    // Get all plugins for the mashup
                    $plugins = Plugin::whereIn('id', $playlistItem->getMashupPluginIds())->get();

                    foreach ($plugins as $plugin) {
                        // Reset cache if Devices with different dimensions exist
                        ImageGenerationService::resetIfNotCacheable($plugin);
                        if ($plugin->isDataStale() || $plugin->current_image === null) {
                            $plugin->updateDataPayload();
                        }
                    }

                    try {
                        $markup = $playlistItem->render(device: $device);
                        GenerateScreenJob::dispatchSync($device->id, null, $markup);
                    } catch (Exception $e) {
                        Log::error("Failed to render mashup playlist item {$playlistItem->id}: ".$e->getMessage());
                        // For mashups, show error for the first plugin or a generic error
                        $firstPlugin = $plugins->first();
                        $pluginName = $firstPlugin ? $firstPlugin->name : 'Recipe';
                        $errorImageUuid = ImageGenerationService::generateDefaultScreenImage($device, 'error', $pluginName);
                        $device->update(['current_screen_image' => $errorImageUuid]);
                    }

                    $device->refresh();

                    if ($device->current_screen_image !== null) {
                        $playlistItem->update(['last_displayed_at' => now()]);
                    }
                }
            }

            $device->refresh();
            $image_uuid = $device->current_screen_image;
        }
        if (! $image_uuid) {
            $image_path = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'setup-logo');
            if (! $image_path) {
                // Generate from template if no device-specific image exists
                $image_uuid = ImageGenerationService::generateDefaultScreenImage($device, 'setup-logo');
                $image_path = 'images/generated/'.$image_uuid.'.png';
            }
            $filename = basename($image_path);
        } else {
            // Determine image format based on device settings
            $preferred_format = 'png'; // Default to PNG for newer firmware

            if (! $device->device_model_id) {
                // No device model, use device's image_format setting
                if (str_contains($device->image_format, 'bmp')) {
                    $preferred_format = 'bmp';
                }
                // For 'auto' or unknown formats, fall back to firmware version logic
                if (isset($device->last_firmware_version)
                    && version_compare($device->last_firmware_version, '1.5.2', '<')
                    && Storage::disk('public')->exists('images/generated/'.$image_uuid.'.bmp')) {
                    $preferred_format = 'bmp';
                }
            }

            // Check if a preferred format exists, otherwise fall back
            if (Storage::disk('public')->exists('images/generated/'.$image_uuid.'.'.$preferred_format)) {
                $image_path = 'images/generated/'.$image_uuid.'.'.$preferred_format;
            } elseif (Storage::disk('public')->exists('images/generated/'.$image_uuid.'.png')) {
                $image_path = 'images/generated/'.$image_uuid.'.png';
            } else {
                $image_path = 'images/generated/'.$image_uuid.'.bmp';
            }
            $filename = basename($image_path);
        }
    }

    $response = [
        'status' => 0,
        'image_url' => $image_path ? Storage::disk('public')->url($image_path) : null,
        'filename' => $filename,
        'refresh_rate' => $refreshTimeOverride ?? $device->default_refresh_interval,
        'reset_firmware' => false,
        'update_firmware' => $device->update_firmware,
        'firmware_url' => $device->firmware_url,
        'special_function' => $device->special_function ?? 'sleep',
        'maximum_compatibility' => $device->maximum_compatibility,
    ];

    if (config('services.trmnl.image_url_timeout')) {
        $response['image_url_timeout'] = config('services.trmnl.image_url_timeout');
    }
    // If update_firmware is true, reset it after returning it, to avoid upgrade loop
    if ($device->update_firmware) {
        $device->resetUpdateFirmwareFlag();
    }

    return response()->json($response);
});

Route::get('/setup', function (Request $request) {
    $mac_address = $request->header('id');
    $model_name = $request->header('model-id');

    if (! $mac_address) {
        return response()->json([
            'status' => 404,
            'message' => 'MAC Address not registered',
        ], 404);
    }

    $device = Device::where('mac_address', mb_strtoupper($mac_address))->first();

    if (! $device) {
        // Check if there's a user with assign_new_devices enabled
        $auto_assign_user = User::where('assign_new_devices', true)->first();

        if ($auto_assign_user) {
            // Check if device model exists by name
            $device_model = null;
            if ($model_name) {
                $device_model = DeviceModel::where('name', $model_name)->first();
            }

            // Create a new device and assign it to this user
            $device = Device::create([
                'mac_address' => mb_strtoupper($mac_address),
                'api_key' => Str::random(22),
                'user_id' => $auto_assign_user->id,
                'name' => "{$auto_assign_user->name}'s TRMNL",
                'friendly_id' => Str::random(6),
                'default_refresh_interval' => 900,
                'mirror_device_id' => $auto_assign_user->assign_new_device_id,
                'device_model_id' => $device_model?->id,
            ]);
        } else {
            return response()->json([
                'status' => 404,
                'message' => 'MAC Address not registered or invalid access token',
            ], 404);
        }
    }

    $image_path = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'setup-logo');

    return response()->json([
        'status' => 200,
        'api_key' => $device->api_key,
        'friendly_id' => $device->friendly_id,
        'image_url' => $image_path ? Storage::disk('public')->url($image_path) : null,
        'message' => 'Welcome to TRMNL BYOS',
    ]);
});

Route::post('/log', function (Request $request) {
    //    $mac_address = $request->header('id');
    $access_token = $request->header('access-token');

    $device = Device::where('api_key', $access_token) // where('mac_address', $mac_address)
        ->first();

    if (! $device) {
        return response()->json([
            'status' => 404,
            'message' => 'Device not found or invalid access token',
        ], 404);
    }

    $device->update([
        'last_log_request' => $request->json()->all(),
    ]);

    $logs = [];
    // Revised format: {"logs": [...]}
    if ($request->has('logs')) {
        $logs = $request->json('logs', []);
    }
    // Fall back to old format: {"log": {"logs_array": [...]}}
    elseif ($request->has('log.logs_array')) {
        $logs = $request->json('log.logs_array', []);
    }

    foreach ($logs as $log) {
        Log::info('Device Log', $log);
        DeviceLog::create([
            'device_id' => $device->id,
            'device_timestamp' => $log['creation_timestamp'] ?? now(),
            'log_entry' => $log,
        ]);
    }

    return response()->json([
        'status' => 200,
    ]);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/devices', function (Request $request) {
    $devices = $request->user()->devices()->get([
        'id',
        'name',
        'friendly_id',
        'mac_address',
        'last_battery_voltage as battery_voltage',
        'last_rssi_level as rssi',
    ]);

    return response()->json([
        'data' => $devices,
    ]);
})->middleware('auth:sanctum');

Route::get('/device-models', function (Request $request) {
    $deviceModels = DeviceModel::get([
        'id',
        'name',
        'label',
        'description',
        'width',
        'height',
        'bit_depth',
    ]);

    return response()->json([
        'data' => $deviceModels,
    ]);
})->middleware('auth:sanctum');

Route::post('/display/update', function (Request $request) {
    $request->validate([
        'device_id' => 'required|exists:devices,id',
        'markup' => 'required|string',
    ]);

    $deviceId = $request['device_id'];
    abort_unless($request->user()->devices->contains($deviceId), 403);

    $view = Blade::render($request['markup']);

    GenerateScreenJob::dispatchSync($deviceId, null, $view);

    response()->json([
        'message' => 'success',
    ]);
})
    ->name('display.update')
    ->middleware('auth:sanctum', 'ability:update-screen');

Route::post('/screens', function (Request $request) {
    $mac_address = $request->header('id');
    $access_token = $request->header('access-token');
    $device = Device::where('mac_address', mb_strtoupper($mac_address ?? ''))
        ->where('api_key', $access_token)
        ->first();

    if (! $device) {
        return response()->json([
            'message' => 'MAC Address not registered or invalid access token',
        ], 404);
    }

    $request->validate([
        'image' => 'array|required',
        'image.content' => 'string|required',
        'image.file_name' => 'string',
    ]);
    $content = $request['image']['content'];

    $view = Blade::render($content);
    GenerateScreenJob::dispatchSync($device->id, null, $view);

    return response()->json([
        'message' => 'success',
    ]);
})->name('screens.update');

Route::get('/display/status', function (Request $request) {
    $request->validate([
        'device_id' => 'required|exists:devices,id',
    ]);

    $deviceId = $request['device_id'];
    abort_unless($request->user()->devices->contains($deviceId), 403);

    return response()->json(
        Device::find($deviceId)->only([
            'id',
            'mac_address',
            'name',
            'friendly_id',
            'last_rssi_level',
            'last_battery_voltage',
            'last_firmware_version',
            'battery_percent',
            'wifi_strength',
            'current_screen_image',
            'default_refresh_interval',
            'sleep_mode_enabled',
            'sleep_mode_from',
            'sleep_mode_to',
            'special_function',
            'pause_until',
            'updated_at',
        ]),
    );
})
    ->name('display.status')
    ->middleware('auth:sanctum');

Route::post('/display/status', function (Request $request) {
    $request->validate([
        'device_id' => 'required|exists:devices,id',
        'name' => 'string|max:255',
        'default_refresh_interval' => 'integer|min:1',
        'sleep_mode_enabled' => 'boolean',
        'sleep_mode_from' => 'nullable|required_if:sleep_mode_enabled,true|date_format:H:i',
        'sleep_mode_to' => 'nullable|required_if:sleep_mode_enabled,true|date_format:H:i',
        'pause_until' => 'nullable|date|after:now',
    ]);

    $deviceId = $request['device_id'];
    abort_unless($request->user()->devices->contains($deviceId), 403);

    $fieldsToUpdate = $request->only(['name', 'default_refresh_interval', 'sleep_mode_enabled', 'sleep_mode_from', 'sleep_mode_to', 'pause_until']);
    Device::find($deviceId)->update($fieldsToUpdate);

    return response()->json(
        Device::find($deviceId)->only([
            'id',
            'mac_address',
            'name',
            'friendly_id',
            'last_rssi_level',
            'last_battery_voltage',
            'last_firmware_version',
            'battery_percent',
            'wifi_strength',
            'current_screen_image',
            'default_refresh_interval',
            'sleep_mode_enabled',
            'sleep_mode_from',
            'sleep_mode_to',
            'special_function',
            'pause_until',
            'updated_at',
        ]),
    );
})
    ->name('display.status.post')
    ->middleware('auth:sanctum');

Route::get('/current_screen', function (Request $request) {
    $access_token = $request->header('access-token');
    $device = Device::where('api_key', $access_token)->first();

    if (! $device) {
        return response()->json([
            'status' => 404,
            'message' => 'Device not found or invalid access token',
        ], 404);
    }

    $image_uuid = $device->current_screen_image;

    if (! $image_uuid) {
        $image_path = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'setup-logo');
        if (! $image_path) {
            // Generate from template if no device-specific image exists
            $image_uuid = ImageGenerationService::generateDefaultScreenImage($device, 'setup-logo');
            $image_path = 'images/generated/'.$image_uuid.'.png';
        }
        $filename = basename($image_path);
    } else {
        // Determine image format based on device settings
        $preferred_format = 'png'; // Default to PNG for newer firmware

        if (! $device->device_model_id) {
            // No device model, use device's image_format setting
            if (str_contains($device->image_format, 'bmp')) {
                $preferred_format = 'bmp';
            }
            // For 'auto' or unknown formats, fall back to firmware version logic
            if (isset($device->last_firmware_version)
                && version_compare($device->last_firmware_version, '1.5.2', '<')
                && Storage::disk('public')->exists('images/generated/'.$image_uuid.'.bmp')) {
                $preferred_format = 'bmp';
            }
        }

        // Check if preferred format exists, otherwise fall back
        if (Storage::disk('public')->exists('images/generated/'.$image_uuid.'.'.$preferred_format)) {
            $image_path = 'images/generated/'.$image_uuid.'.'.$preferred_format;
        } elseif (Storage::disk('public')->exists('images/generated/'.$image_uuid.'.png')) {
            $image_path = 'images/generated/'.$image_uuid.'.png';
        } else {
            $image_path = 'images/generated/'.$image_uuid.'.bmp';
        }
        $filename = basename($image_path);
    }

    $response = [
        'status' => 200,
        'image_url' => $image_path ? Storage::disk('public')->url($image_path) : null,
        'filename' => $filename,
        'refresh_rate' => $refreshTimeOverride ?? $device->default_refresh_interval,
        'reset_firmware' => false,
        'update_firmware' => false,
        'firmware_url' => $device->firmware_url,
        'special_function' => $device->special_function ?? 'sleep',
    ];

    if (config('services.trmnl.image_url_timeout')) {
        $response['image_url_timeout'] = config('services.trmnl.image_url_timeout');
    }

    return response()->json($response);
});

Route::post('custom_plugins/{plugin_uuid}', function (string $plugin_uuid) {
    $plugin = Plugin::where('uuid', $plugin_uuid)->firstOrFail();

    // Check if plugin uses webhook strategy
    if ($plugin->data_strategy !== 'webhook') {
        return response()->json(['error' => 'Plugin does not use webhook strategy'], 400);
    }

    $request = request();
    if (! $request->has('merge_variables')) {
        return response()->json(['error' => 'Request must contain merge_variables key'], 400);
    }

    $plugin->update([
        'data_payload' => $request->input('merge_variables'),
        'data_payload_updated_at' => now(),
    ]);

    return response()->json(['message' => 'Data updated successfully']);
})->name('api.custom_plugins.webhook');

Route::post('plugin_settings/{uuid}/image', function (Request $request, string $uuid) {
    $plugin = Plugin::where('uuid', $uuid)->firstOrFail();

    // Check if plugin is image_webhook type
    if ($plugin->plugin_type !== 'image_webhook') {
        return response()->json(['error' => 'Plugin is not an image webhook plugin'], 400);
    }

    // Accept image from either multipart form or raw binary
    $image = null;
    $extension = null;

    if ($request->hasFile('image')) {
        $file = $request->file('image');
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $image = $file->get();
    } elseif ($request->has('image')) {
        // Base64 encoded image
        $imageData = $request->input('image');
        if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
            $extension = mb_strtolower($matches[1]);
            $image = base64_decode(mb_substr($imageData, mb_strpos($imageData, ',') + 1));
        } else {
            return response()->json(['error' => 'Invalid image format. Expected base64 data URI.'], 400);
        }
    } else {
        // Try raw binary
        $image = $request->getContent();
        $contentType = $request->header('Content-Type', '');
        $trimmedContent = mb_trim($image);

        // Check if content is empty or just empty JSON
        if (empty($image) || $trimmedContent === '' || $trimmedContent === '{}') {
            return response()->json(['error' => 'No image data provided'], 400);
        }

        // If it's a JSON request without image field, return error
        if (str_contains($contentType, 'application/json')) {
            return response()->json(['error' => 'No image data provided'], 400);
        }

        // Detect image type from content
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $image);
        finfo_close($finfo);

        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/bmp' => 'bmp',
            default => null,
        };

        if (! $extension) {
            return response()->json(['error' => 'Unsupported image format. Expected PNG or BMP.'], 400);
        }
    }

    // Validate extension
    $allowedExtensions = ['png', 'bmp'];
    if (! in_array($extension, $allowedExtensions)) {
        return response()->json(['error' => 'Unsupported image format. Expected PNG or BMP.'], 400);
    }

    // Generate a new UUID for each image upload to prevent device caching
    $imageUuid = Str::uuid()->toString();
    $filename = $imageUuid.'.'.$extension;
    $path = 'images/generated/'.$filename;

    // Save image to storage
    Storage::disk('public')->put($path, $image);

    // Update plugin's current_image field with the new UUID
    $plugin->update([
        'current_image' => $imageUuid,
    ]);

    // Clean up old images
    ImageGenerationService::cleanupFolder();

    return response()->json([
        'message' => 'Image uploaded successfully',
        'image_url' => $path ? Storage::disk('public')->url($path) : null,
    ]);
})->name('api.plugin_settings.image');

Route::get('plugin_settings/{trmnlp_id}/archive', function (Request $request, string $trmnlp_id) {
    if (! $trmnlp_id || mb_trim($trmnlp_id) === '') {
        return response()->json([
            'message' => 'trmnlp_id is required',
        ], 400);
    }

    // Find the plugin by trmnlp_id and ensure it belongs to the authenticated user
    $plugin = Plugin::where('trmnlp_id', $trmnlp_id)
        ->where('user_id', auth()->user()->id)
        ->firstOrFail();

    // Use the export service to create the ZIP file
    /** @var App\Services\PluginExportService $exporter */
    $exporter = app(App\Services\PluginExportService::class);

    return $exporter->exportToZip($plugin, auth()->user());
})->middleware('auth:sanctum');

Route::post('plugin_settings/{trmnlp_id}/archive', function (Request $request, string $trmnlp_id) {
    if (! $trmnlp_id) {
        return response()->json([
            'message' => 'trmnl_id is required',
        ]);
    }

    $validated = $request->validate([
        'file' => 'required|file|mimes:zip',
    ]);

    /** @var Illuminate\Http\UploadedFile $file */
    $file = $request->file('file');
    // Apply archive to existing plugin using the import service
    /** @var PluginImportService $importer */
    $importer = app(PluginImportService::class);
    $plugin = $importer->importFromZip($file, auth()->user());

    return response()->json([
        'message' => 'Plugin settings archive processed successfully',
        'data' => [
            'settings_yaml' => $plugin['trmnlp_yaml'],
        ],
    ]);
})->middleware('auth:sanctum');

Route::get('/display/{uuid}/alias', function (Request $request, string $uuid) {
    $plugin = Plugin::where('uuid', $uuid)->firstOrFail();

    // Check if alias is active
    if (! $plugin->alias) {
        return response()->json([
            'message' => 'Alias is not active for this plugin',
        ], 403);
    }

    // Get device model name from query parameter, default to 'og_png'
    $deviceModelName = $request->query('device-model', 'og_png');
    $deviceModel = DeviceModel::where('name', $deviceModelName)->first();

    if (! $deviceModel) {
        return response()->json([
            'message' => "Device model '{$deviceModelName}' not found",
        ], 404);
    }

    ImageGenerationService::resetIfNotCacheable($plugin, $deviceModel);
    $plugin->refresh();

    // Check if we can use cached image (only for og_png and if data is not stale)
    $useCache = $deviceModelName === 'og_png' && ! $plugin->isDataStale() && $plugin->current_image !== null;

    if ($useCache) {
        // Return cached image
        $imageUuid = $plugin->current_image;
        $fileExtension = $deviceModel->mime_type === 'image/bmp' ? 'bmp' : 'png';
        $imagePath = 'images/generated/'.$imageUuid.'.'.$fileExtension;

        // Check if image exists, otherwise fall back to generation
        if (Storage::disk('public')->exists($imagePath)) {
            return response()->file(Storage::disk('public')->path($imagePath), [
                'Content-Type' => $deviceModel->mime_type,
            ]);
        }
    }

    // Generate new image
    try {
        // Update data if needed
        if ($plugin->isDataStale()) {
            $plugin->updateDataPayload();
            $plugin->refresh();
        }

        // Load device model with palette relationship
        $deviceModel->load('palette');

        // Create a virtual device for rendering (Plugin::render needs a Device object)
        $virtualDevice = new Device();
        $virtualDevice->setRelation('deviceModel', $deviceModel);
        $virtualDevice->setRelation('user', $plugin->user);
        $virtualDevice->setRelation('palette', $deviceModel->palette);

        // Render the plugin markup
        $markup = $plugin->render(device: $virtualDevice);

        // Generate image using the new method that doesn't require a device
        $imageUuid = ImageGenerationService::generateImageFromModel(
            markup: $markup,
            deviceModel: $deviceModel,
            user: $plugin->user,
            palette: $deviceModel->palette
        );

        // Update plugin cache if using og_png (recipes only get metadata for cache comparison)
        if ($deviceModelName === 'og_png') {
            $update = ['current_image' => $imageUuid];
            if ($plugin->plugin_type === 'recipe') {
                $update['current_image_metadata'] = ImageGenerationService::buildImageMetadataFromDeviceModel($deviceModel);
            }
            $plugin->update($update);
        }

        // Return the generated image
        $fileExtension = $deviceModel->mime_type === 'image/bmp' ? 'bmp' : 'png';
        $imagePath = Storage::disk('public')->path('images/generated/'.$imageUuid.'.'.$fileExtension);

        return response()->file($imagePath, [
            'Content-Type' => $deviceModel->mime_type,
        ]);
    } catch (Exception $e) {
        Log::error("Failed to generate alias image for plugin {$plugin->id} ({$plugin->name}): ".$e->getMessage());

        return response()->json([
            'message' => 'Failed to generate image',
            'error' => $e->getMessage(),
        ], 500);
    }
})->name('api.display.alias');
