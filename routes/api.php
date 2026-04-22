<?php

use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceModelController;
use App\Http\Controllers\Api\DisplayAliasController;
use App\Http\Controllers\Api\DisplayStatusController;
use App\Http\Controllers\Api\DisplayUpdateController;
use App\Http\Controllers\Api\Firmware\CurrentScreenController;
use App\Http\Controllers\Api\Firmware\DeviceLogController;
use App\Http\Controllers\Api\Firmware\DisplayController;
use App\Http\Controllers\Api\Firmware\ScreenController;
use App\Http\Controllers\Api\Firmware\SetupController;
use App\Http\Controllers\Api\PluginArchiveController;
use App\Http\Controllers\Api\PluginImageWebhookController;
use App\Http\Controllers\Api\PluginWebhookController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Device endpoints (header-authenticated, used by TRMNL compatible device)
|--------------------------------------------------------------------------
*/
Route::get('/display', DisplayController::class);
Route::get('/setup', SetupController::class);
Route::post('/log', [DeviceLogController::class, 'store']);
Route::post('/screens', [ScreenController::class, 'store'])->name('screens.update');
Route::get('/current_screen', CurrentScreenController::class);

/*
|--------------------------------------------------------------------------
| User-authenticated API endpoints (Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', UserController::class);
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::get('/device-models', [DeviceModelController::class, 'index']);

    Route::get('/display/status', [DisplayStatusController::class, 'show'])->name('display.status');
    Route::post('/display/status', [DisplayStatusController::class, 'update'])->name('display.status.post');

    Route::post('/display/update', DisplayUpdateController::class)
        ->middleware('ability:update-screen')
        ->name('display.update');

    Route::get('/plugin_settings/{trmnlp_id}/archive', [PluginArchiveController::class, 'export']);
    Route::post('/plugin_settings/{trmnlp_id}/archive', [PluginArchiveController::class, 'import']);
});

/*
|--------------------------------------------------------------------------
| Public plugin endpoints (uuid-scoped route-model binding)
|--------------------------------------------------------------------------
*/
Route::post('/custom_plugins/{plugin:uuid}', PluginWebhookController::class)
    ->name('api.custom_plugins.webhook');

Route::post('/plugin_settings/{plugin:uuid}/image', PluginImageWebhookController::class)
    ->name('api.plugin_settings.image');

Route::get('/display/{plugin:uuid}/alias', DisplayAliasController::class)
    ->name('api.display.alias');
