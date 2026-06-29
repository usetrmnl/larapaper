<?php

declare(strict_types=1);

use App\Models\User;

it('unconfirmed user is redirected from dashboard to login', function (): void {
    $user = User::factory()->unconfirmed()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('unconfirmed user sees awaiting approval message', function (): void {
    $user = User::factory()->unconfirmed()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSessionHas('status', 'Your account is awaiting admin approval.');
});

it('confirmed user can access dashboard', function (): void {
    $user = User::factory()->confirmed()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

it('unconfirmed user session is terminated on redirect', function (): void {
    $user = User::factory()->unconfirmed()->create();

    $this->actingAs($user)->get(route('dashboard'));

    $this->assertGuest();
});
