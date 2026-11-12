<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('authenticated user can fetch current user from api', function (): void {
    $user = User::factory()->create([
        'name' => 'API User',
        'email' => 'api-user@example.com',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', 'api-user@example.com')
        ->assertJsonPath('name', 'API User');
});

test('guest cannot fetch api user', function (): void {
    $this->getJson('/api/user')->assertUnauthorized();
});

test('a bad token gets 401 JSON without an Accept header', function (): void {
    $this->get('/api/me', ['Authorization' => 'Bearer not-a-token'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('a guest on a web page is still sent to the login page', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});
