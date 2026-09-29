<?php

declare(strict_types=1);

use App\Models\User;

test('registration is open when no users exist', function (): void {
    $this->get(route('register'))->assertOk();

    $this->get(route('login'))->assertOk()->assertSee('Sign up');

    $this->get(route('home'))->assertOk()->assertSee('Register');
});

test('registration closes after the first user exists', function (): void {
    User::factory()->create();

    $this->get(route('register'))->assertNotFound();

    $this->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::query()->count())->toBe(1);

    $this->get(route('login'))->assertOk()->assertDontSee('Sign up');
    $this->get(route('home'))->assertOk()->assertDontSee('Register');
});

test('registration stays open for additional users when multi-user registration is enabled', function (): void {
    config(['app.multiuser_registration.enabled' => true]);

    User::factory()->create();

    $this->get(route('register'))->assertOk();
});
