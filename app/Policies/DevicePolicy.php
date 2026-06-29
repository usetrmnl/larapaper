<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function view(User $user, Device $device): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $device->user_id === null || $device->user_id === $user->id;
    }

    public function update(User $user, Device $device): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $device->user_id === null || $device->user_id === $user->id;
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->update($user, $device);
    }

    public function reassign(User $user, Device $device): bool
    {
        return $user->isAdmin();
    }
}
