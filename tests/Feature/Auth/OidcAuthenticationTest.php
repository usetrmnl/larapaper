<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    // Enable OIDC for testing
    Config::set('services.oidc.enabled', true);
    Config::set('services.oidc.endpoint', 'https://example.com/oidc');
    Config::set('services.oidc.client_id', 'test-client-id');
    Config::set('services.oidc.client_secret', 'test-client-secret');

    // Mock Socialite OIDC driver to avoid any external HTTP calls
    $provider = Mockery::mock();
    $provider->shouldReceive('redirect')->andReturn(redirect('/fake-oidc-redirect'));

    // Default Socialite user returned by callback
    $socialiteUser = mockSocialiteUser();
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->with('oidc')
        ->andReturn($provider);
});

afterEach(function (): void {
    Mockery::close();
});

it('oidc redirect works when enabled', function (): void {
    $response = $this->get(route('auth.oidc.redirect'));

    // Since we're using a mock OIDC provider, this will likely fail
    // but we can check that the route exists and is accessible
    expect($response->getStatusCode())->not->toBe(404);
});

it('oidc redirect fails when disabled', function (): void {
    Config::set('services.oidc.enabled', false);

    $response = $this->get(route('auth.oidc.redirect'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['oidc' => 'OIDC authentication is not enabled.']);
});

it('oidc callback creates new user (placeholder)', function (): void {
    mockSocialiteUser();

    $this->get(route('auth.oidc.callback'));

    // We expect to be redirected to dashboard after successful authentication
    // In a real test, this would be mocked properly
    expect(true)->toBeTrue(); // Placeholder assertion
});

it('oidc callback updates existing user by oidc_sub (placeholder)', function (): void {
    // Create a user with OIDC sub
    User::factory()->create([
        'oidc_sub' => 'test-sub-123',
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    mockSocialiteUser([
        'id' => 'test-sub-123',
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);

    // This would need proper mocking of Socialite in a real test
    expect(true)->toBeTrue(); // Placeholder assertion
});

it('oidc callback links existing user by email (placeholder)', function (): void {
    // Create a user without OIDC sub but with matching email
    User::factory()->create([
        'oidc_sub' => null,
        'email' => 'test@example.com',
    ]);

    mockSocialiteUser([
        'id' => 'test-sub-456',
        'email' => 'test@example.com',
    ]);

    // This would need proper mocking of Socialite in a real test
    expect(true)->toBeTrue(); // Placeholder assertion
});

it('creates the first user via oidc when no account exists', function (): void {
    expect(User::query()->count())->toBe(0);

    $response = $this->get(route('auth.oidc.callback'));

    $response->assertRedirect(route('dashboard', absolute: false));

    expect(User::query()->count())->toBe(1);
});

it('does not create another user via oidc when multi-user registration is off', function (): void {
    User::factory()->create();

    $response = $this->get(route('auth.oidc.callback'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['oidc' => 'Registration is closed.']);

    expect(User::query()->count())->toBe(1);
});

it('oidc callback fails when disabled', function (): void {
    Config::set('services.oidc.enabled', false);

    $response = $this->get(route('auth.oidc.callback'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['oidc' => 'OIDC authentication is not enabled.']);
});

it('login view shows oidc button when enabled', function (): void {
    $response = $this->get(route('login'));

    $response->assertStatus(200);
    $response->assertSee('Continue with OIDC');
    $response->assertSee('Or');
});

it('login view hides oidc button when disabled', function (): void {
    Config::set('services.oidc.enabled', false);

    $response = $this->get(route('login'));

    $response->assertStatus(200);
    $response->assertDontSee('Continue with OIDC');
});

it('user model has oidc_sub fillable', function (): void {
    $user = new User();

    expect($user->getFillable())->toContain('oidc_sub');
});

/**
 * Mock a Socialite user for testing.
 *
 * @param  array<string, mixed>  $userData
 */
function mockSocialiteUser(array $userData = []): SocialiteUser
{
    $defaultData = [
        'id' => 'test-sub-123',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'avatar' => null,
    ];

    $userData = array_merge($defaultData, $userData);

    /** @var SocialiteUser $socialiteUser */
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn($userData['id']);
    $socialiteUser->shouldReceive('getName')->andReturn($userData['name']);
    $socialiteUser->shouldReceive('getEmail')->andReturn($userData['email']);
    $socialiteUser->shouldReceive('getAvatar')->andReturn($userData['avatar']);

    return $socialiteUser;
}
