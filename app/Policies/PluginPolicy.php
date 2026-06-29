<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Plugin;
use App\Models\User;

class PluginPolicy
{
    public function view(User $user, Plugin $plugin): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $plugin->user_id === $user->id || $plugin->is_shared;
    }

    public function update(User $user, Plugin $plugin): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $plugin->user_id === $user->id;
    }

    public function delete(User $user, Plugin $plugin): bool
    {
        return $this->update($user, $plugin);
    }

    public function share(User $user, Plugin $plugin): bool
    {
        return $user->isAdmin() || $plugin->user_id === $user->id;
    }

    public function reassign(User $user, Plugin $plugin): bool
    {
        return $user->isAdmin();
    }

    public function copy(User $user, Plugin $plugin): bool
    {
        return $plugin->is_shared || $user->isAdmin();
    }
}
