<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Registration;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OidcController extends Controller
{
    /**
     * Redirect the user to the OIDC provider authentication page.
     */
    public function redirect()
    {
        if (! config('services.oidc.enabled')) {
            return redirect()->route('login')->withErrors(['oidc' => 'OIDC authentication is not enabled.']);
        }

        // Check if all required OIDC configuration is present
        $requiredConfig = ['endpoint', 'client_id', 'client_secret'];
        foreach ($requiredConfig as $key) {
            if (! config("services.oidc.{$key}")) {
                Log::error("OIDC configuration missing: {$key}");

                return redirect()->route('login')->withErrors(['oidc' => 'OIDC is not properly configured.']);
            }
        }

        try {
            return Socialite::driver('oidc')->redirect();
        } catch (Exception $e) {
            Log::error('OIDC redirect error: '.$e->getMessage());

            return redirect()->route('login')->withErrors(['oidc' => 'Failed to initiate OIDC authentication.']);
        }
    }

    /**
     * Obtain the user information from the OIDC provider.
     */
    public function callback(Request $request)
    {
        if (! config('services.oidc.enabled')) {
            return redirect()->route('login')->withErrors(['oidc' => 'OIDC authentication is not enabled.']);
        }

        // Check if all required OIDC configuration is present
        $requiredConfig = ['endpoint', 'client_id', 'client_secret'];
        foreach ($requiredConfig as $key) {
            if (! config("services.oidc.{$key}")) {
                Log::error("OIDC configuration missing: {$key}");

                return redirect()->route('login')->withErrors(['oidc' => 'OIDC is not properly configured.']);
            }
        }

        try {
            $oidcUser = Socialite::driver('oidc')->user();

            $user = $this->findOrCreateUser($oidcUser);

            if (! $user instanceof User) {
                return redirect()->route('login')->withErrors([
                    'oidc' => 'Registration is closed.',
                ]);
            }

            Auth::login($user, true);

            return redirect()->intended(route('dashboard', absolute: false));

        } catch (Exception $e) {
            Log::error('OIDC callback error: '.$e->getMessage());

            return redirect()->route('login')->withErrors(['oidc' => 'Failed to authenticate with OIDC provider.']);
        }
    }

    /**
     * Find or create a user based on OIDC information.
     */
    protected function findOrCreateUser(mixed $oidcUser): ?User
    {
        // First, try to find user by OIDC subject ID
        $user = User::where('oidc_sub', $oidcUser->getId())->first();

        if ($user) {
            // Update user information from OIDC
            $user->update([
                'name' => $oidcUser->getName() ?: $user->name,
                'email' => $oidcUser->getEmail() ?: $user->email,
            ]);

            return $user;
        }

        // If not found by OIDC sub, try to find by email
        if ($oidcUser->getEmail()) {
            $user = User::where('email', $oidcUser->getEmail())->first();

            if ($user) {
                // Link the existing user with OIDC
                $user->update([
                    'oidc_sub' => $oidcUser->getId(),
                    'name' => $oidcUser->getName() ?: $user->name,
                ]);

                return $user;
            }
        }

        if (! Registration::isOpen()) {
            return null;
        }

        return User::create([
            'oidc_sub' => $oidcUser->getId(),
            'name' => $oidcUser->getName() ?: 'OIDC User',
            'email' => $oidcUser->getEmail() ?: $oidcUser->getId().'@oidc.local',
            'password' => bcrypt(Str::random(32)), // Random password since we're using OIDC
            'email_verified_at' => now(), // OIDC users are considered verified
        ]);
    }
}
