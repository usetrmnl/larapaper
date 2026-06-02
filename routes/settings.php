<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', 'settings/profile');
    Route::livewire('settings/preferences', 'pages::settings.preferences')->name('settings.preferences');
    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::redirect('settings/password', '/settings/security');
    Route::redirect('settings/two-factor', '/settings/security');
    Route::livewire('settings/support', 'pages::settings.support')->name('settings.support');
    Route::livewire('settings/lab', 'pages::settings.lab')->name('settings.lab');
    Route::livewire('settings/update', 'pages::settings.update')->name('settings.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware(['password.confirm'])
        ->name('security.edit');
});
