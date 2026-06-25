<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    // Create an admin user first (ensures ID 1 exists before registration tests)
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
    ]);
});

it('newly registered user via Fortify is unconfirmed', function (): void {
    $response = $this->post('/register', [
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'newuser@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->confirmed_at)->toBeNull();
});

it('newly registered user is immediately redirected away (unconfirmed)', function (): void {
    $this->post('/register', [
        'name' => 'New User',
        'email' => 'newuser2@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'newuser2@example.com')->first();
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
});
