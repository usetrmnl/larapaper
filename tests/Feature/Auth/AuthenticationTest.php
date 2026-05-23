<?php

use App\Models\User;
use Laravel\Fortify\Features;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('login screen can be rendered', function (): void {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertDontSee('Sign in with a passkey');
});

test('login screen shows passkey sign-in when passkeys are enabled', function (): void {
    config(['app.passkeys.enabled' => true]);

    Features::passkeys([
        'confirmPassword' => true,
    ]);

    $features = array_values(array_filter(config('fortify.features', [])));
    $passkeysFeature = Features::passkeys();
    if (! in_array($passkeysFeature, $features, true)) {
        $features[] = $passkeysFeature;
    }
    config(['fortify.features' => $features]);

    $this->skipUnlessFortifyHas(Features::passkeys());

    $response = $this->get('/login');

    $response->assertOk();
    $response->assertSee('Sign in with a passkey');
});

test('users can authenticate using the login screen', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('users can logout', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect(route('home'));
});
