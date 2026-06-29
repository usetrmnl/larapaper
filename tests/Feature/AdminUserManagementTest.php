<?php
// tests/Feature/AdminUserManagementTest.php
declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

it('non-admin cannot access admin users page', function (): void {
    User::factory()->confirmed()->create(); // consume id=1 (auto-admin)
    $user = User::factory()->confirmed()->create();

    $this->actingAs($user)
        ->get(route('settings.admin.users'))
        ->assertForbidden();
});

it('admin can access admin users page', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('settings.admin.users'))
        ->assertOk();
});

it('admin can confirm a user via Livewire action', function (): void {
    $admin = User::factory()->admin()->create();
    $pending = User::factory()->unconfirmed()->create();

    $this->actingAs($admin);

    Livewire::test('pages::settings.admin.users')
        ->call('confirmUser', $pending->id)
        ->assertHasNoErrors();

    expect($pending->fresh()->confirmed_at)->not->toBeNull();
});

it('admin can revoke a user confirmation via Livewire action', function (): void {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->confirmed()->create();

    $this->actingAs($admin);

    Livewire::test('pages::settings.admin.users')
        ->call('revokeUser', $user->id)
        ->assertHasNoErrors();

    expect($user->fresh()->confirmed_at)->toBeNull();
});

it('admin cannot revoke their own confirmation', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test('pages::settings.admin.users')
        ->call('revokeUser', $admin->id)
        ->assertForbidden();
});

it('admin can promote a user to admin', function (): void {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->confirmed()->create();

    $this->actingAs($admin);

    Livewire::test('pages::settings.admin.users')
        ->call('makeAdmin', $user->id)
        ->assertHasNoErrors();

    expect($user->fresh()->is_admin)->toBeTrue();
});

it('admin can revoke admin from another admin', function (): void {
    User::factory()->confirmed()->create(); // consume id=1
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test('pages::settings.admin.users')
        ->call('revokeAdmin', $otherAdmin->id)
        ->assertHasNoErrors();

    expect($otherAdmin->fresh()->is_admin)->toBeFalse();
});

it('admin cannot revoke their own admin status', function (): void {
    User::factory()->confirmed()->create(); // consume id=1
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test('pages::settings.admin.users')
        ->call('revokeAdmin', $admin->id)
        ->assertForbidden();
});

it('admin can delete another user', function (): void {
    User::factory()->confirmed()->create(); // consume id=1
    $admin = User::factory()->admin()->create();
    $user = User::factory()->confirmed()->create();

    $this->actingAs($admin);

    Livewire::test('pages::settings.admin.users')
        ->call('deleteUser', $user->id)
        ->assertHasNoErrors();

    expect($user->fresh())->toBeNull();
});

it('admin cannot delete themselves', function (): void {
    User::factory()->confirmed()->create(); // consume id=1
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test('pages::settings.admin.users')
        ->call('deleteUser', $admin->id)
        ->assertForbidden();
});

it('non-admin is blocked from admin users page at route level', function (): void {
    User::factory()->confirmed()->create(); // consume id=1
    $user = User::factory()->confirmed()->create();

    $this->actingAs($user)
        ->get(route('settings.admin.users'))
        ->assertForbidden();
});
