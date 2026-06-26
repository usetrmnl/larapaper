<?php

use App\Models\User;
use Flux\Flux;
use Livewire\Component;

new class extends Component
{
    public function mount(): void
    {
        abort_unless(config('app.multi_user_mode') && auth()->user()->isAdmin(), 403);
    }

    public function confirmUser(int $userId): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        User::findOrFail($userId)->update(['confirmed_at' => now()]);

        Flux::toast(variant: 'success', text: 'User confirmed.');
    }

    public function revokeUser(int $userId): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_if($userId === auth()->id(), 403, 'Cannot revoke yourself.');

        User::findOrFail($userId)->update(['confirmed_at' => null]);

        Flux::toast(variant: 'success', text: 'User confirmation revoked.');
    }

    public function makeAdmin(int $userId): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        User::findOrFail($userId)->update(['is_admin' => true]);

        Flux::toast(variant: 'success', text: 'User promoted to admin.');
    }

    public function revokeAdmin(int $userId): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_if($userId === 1, 403, 'Cannot remove admin from the primary admin.');
        abort_if($userId === auth()->id(), 403, 'Cannot remove your own admin status.');

        User::findOrFail($userId)->update(['is_admin' => false]);

        Flux::toast(variant: 'success', text: 'Admin status removed.');
    }

    public function deleteUser(int $userId): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_if($userId === auth()->id(), 403, 'Cannot delete yourself.');
        abort_if($userId === 1, 403, 'Cannot delete the primary admin.');

        User::findOrFail($userId)->delete();

        Flux::toast(variant: 'success', text: 'User deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'users' => User::orderBy('confirmed_at')->orderBy('created_at')->get(),
        ];
    }
};
?>

<section class="w-full py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        @include('partials.settings-heading')

        <x-pages::settings.layout heading="User Management" subheading="Confirm accounts and manage admin access.">
            <div class="space-y-4">
                @foreach ($users as $user)
                    <div class="flex items-center justify-between p-3 border border-zinc-200 dark:border-zinc-700 rounded-lg
                                {{ $user->confirmed_at === null ? 'border-amber-400 dark:border-amber-500' : '' }}">
                        <div>
                            <div class="font-medium text-sm">{{ $user->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $user->email }}</div>
                            <div class="flex gap-2 mt-1">
                                @if ($user->confirmed_at === null)
                                    <flux:badge color="amber" size="sm">Pending</flux:badge>
                                @else
                                    <flux:badge color="green" size="sm">Confirmed</flux:badge>
                                @endif
                                @if ($user->is_admin)
                                    <flux:badge color="blue" size="sm">Admin</flux:badge>
                                @endif
                            </div>
                        </div>
                        <div class="flex gap-2">
                            @if ($user->confirmed_at === null)
                                <flux:button size="sm" variant="primary" wire:click="confirmUser({{ $user->id }})">
                                    Confirm
                                </flux:button>
                            @else
                                <flux:button size="sm" variant="ghost" wire:click="revokeUser({{ $user->id }})"
                                             wire:confirm="Revoke confirmation? This will block the user's access."
                                             :disabled="$user->id === auth()->id()">
                                    Revoke
                                </flux:button>
                            @endif

                            @if (! $user->is_admin)
                                <flux:button size="sm" variant="ghost" wire:click="makeAdmin({{ $user->id }})">
                                    Make Admin
                                </flux:button>
                            @elseif ($user->id !== 1 && $user->id !== auth()->id())
                                <flux:button size="sm" variant="ghost" wire:click="revokeAdmin({{ $user->id }})"
                                             wire:confirm="Remove admin status from {{ $user->name }}?">
                                    Remove Admin
                                </flux:button>
                            @endif

                            @if ($user->id !== auth()->id() && $user->id !== 1)
                                <flux:button size="sm" variant="danger" wire:click="deleteUser({{ $user->id }})"
                                             wire:confirm="Permanently delete {{ $user->name }}? This cannot be undone.">
                                    Delete
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-pages::settings.layout>
    </div>
</section>
