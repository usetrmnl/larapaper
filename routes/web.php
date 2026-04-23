<?php

use App\Models\Plugin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {

    Route::livewire('/dashboard', 'device-dashboard')->name('dashboard');

    Route::livewire('/devices', 'devices.manage')->name('devices');
    Route::livewire('/devices/{device}/configure', 'devices.configure')->name('devices.configure');
    Route::livewire('/devices/{device}/logs', 'devices.logs')->name('devices.logs');

    Route::livewire('/device-models', 'device-models.index')->name('device-models.index');
    Route::livewire('/device-palettes', 'device-palettes.index')->name('device-palettes.index');

    Route::livewire('plugins', 'plugins.index')->name('plugins.index');

    Route::livewire('plugins/recipe/{plugin}', 'plugins.recipe')->name('plugins.recipe');
    Route::livewire('plugins/markup', 'plugins.markup')->name('plugins.markup');
    Route::livewire('plugins/api', 'plugins.api')->name('plugins.api');
    Route::livewire('plugins/type/{type}', 'plugins.type')->name('plugins.type');
    Route::livewire('plugins/type/{type}/{plugin}', 'plugins.type-instance')->name('plugins.type-instance');
    Route::livewire('playlists', 'playlists.index')->name('playlists.index');

    Route::get('plugin_settings/{trmnlp_id}/edit', function (Request $request, string $trmnlp_id) {
        $plugin = Plugin::query()
            ->where('user_id', $request->user()->id)
            ->where('trmnlp_id', $trmnlp_id)->firstOrFail();

        return redirect()->route('plugins.recipe', ['plugin' => $plugin]);
    });
});

require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
