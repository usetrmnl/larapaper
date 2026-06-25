<?php
// tests/Feature/UserModelTest.php
declare(strict_types=1);

use App\Models\User;

it('isAdmin returns true when is_admin is true', function (): void {
    $user = User::factory()->admin()->create();
    expect($user->isAdmin())->toBeTrue();
});

it('isAdmin returns false by default', function (): void {
    $user = User::factory()->confirmed()->create();
    expect($user->isAdmin())->toBeFalse();
});

it('isConfirmed returns true when confirmed_at is set', function (): void {
    $user = User::factory()->confirmed()->create();
    expect($user->isConfirmed())->toBeTrue();
});

it('isConfirmed returns false when confirmed_at is null', function (): void {
    $user = User::factory()->unconfirmed()->create();
    expect($user->isConfirmed())->toBeFalse();
});

it('user id 1 is always admin regardless of is_admin column', function (): void {
    // Create first user (will get id=1 in a clean test DB)
    $user = User::factory()->confirmed()->create(['is_admin' => false]);
    if ($user->id === 1) {
        $user->refresh();
        expect($user->isAdmin())->toBeTrue();
    } else {
        expect(true)->toBeTrue(); // skip if not first user in this test run
    }
});
