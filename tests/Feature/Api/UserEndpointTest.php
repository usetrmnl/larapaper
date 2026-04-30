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
