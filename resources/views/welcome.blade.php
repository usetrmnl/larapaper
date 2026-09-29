<x-layouts::auth.card>
    <div class="flex flex-col gap-6">
        <x-auth-header title="LaraPaper" description="Server is up and running." />
    </div>
    <header class="mt-6 w-full max-w-[335px] text-sm not-has-[nav]:hidden lg:max-w-4xl">
        @if (Route::has('login'))
            <nav class="flex items-center justify-end gap-4">
                @auth
                    <a
                        href="{{ url('/dashboard') }}"
                        class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                    >
                        Dashboard
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        class="inline-block rounded-sm border border-transparent px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#19140035] dark:text-[#EDEDEC] dark:hover:border-[#3E3E3A]"
                    >
                        Log in
                    </a>

                    @if (Route::has('register') && \App\Support\Registration::isOpen())
                        <a
                            href="{{ route('register') }}"
                            class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                        >
                            Register
                        </a>
                    @endif
                @endauth
            </nav>
        @endif
    </header>
    @auth
        @if (config('app.version'))
            <flux:text class="text-xs"
                >Version:
                <a
                    href="https://github.com/{{ config('app.github_repo') }}/releases/tag/{{ config('app.version') }}"
                    target="_blank"
                >{{ config('app.version') }}</a>
            </flux:text>
        @endif
        <livewire:update-check />
    @endauth
</x-layouts::auth.card>
