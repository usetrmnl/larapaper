<x-layouts::auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email') || app()->isLocal() ? 'admin@example.com' : old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    :value="app()->isLocal() ? 'admin@example.com' : ''"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <x-text-link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </x-text-link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                <span>{{ __('Don\'t have an account?') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
            </div>
        @endif

        @if (config('services.oidc.enabled'))
            <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <span class="w-full border-t border-zinc-300 dark:border-zinc-600"></span>
                </div>
                <div class="relative flex justify-center text-sm">
                <span class="bg-white dark:bg-zinc-900 px-2 text-zinc-500 dark:text-zinc-400">
                    {{ __('Or') }}
                </span>
                </div>
            </div>

            <div class="flex items-center justify-end">
                <flux:button
                    variant="outline"
                    type="button"
                    class="w-full"
                    href="{{ route('auth.oidc.redirect') }}"
                >
                    {{ __('Continue with OIDC') }}
                </flux:button>
            </div>
        @endif

    </div>
</x-layouts::auth>
