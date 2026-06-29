<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('settings.preferences')" wire:navigate>{{ __('Preferences') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            @if (auth()?->user()?->oidc_sub === null)
                <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Password & 2FA') }}</flux:navlist.item>
            @endif
            <flux:navlist.item :href="route('settings.api-tokens')" wire:navigate>{{ __('API Tokens') }}</flux:navlist.item>
            @if (config('app.version'))
                <flux:navlist.item :href="route('settings.update')" wire:navigate>{{ __('Updates') }}</flux:navlist.item>
            @endif

            <flux:navlist.group :heading="__('Experimental')" class="mt-2">
                <flux:navlist.item :href="route('settings.lab')" wire:navigate>{{ __('Lab') }}</flux:navlist.item>
            </flux:navlist.group>

            <flux:navlist.group :heading="__('Support')" class="mt-2">
                 <flux:navlist.item :href="route('settings.support')" wire:navigate>{{ __('Support & Referral') }}</flux:navlist.item>
            </flux:navlist.group>

            @if (config('app.multi_user_mode') && auth()?->user()?->isAdmin())
                <flux:navlist.group :heading="__('Admin')" class="mt-2">
                    <flux:navlist.item :href="route('settings.admin.users')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
                </flux:navlist.group>
            @endif
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
