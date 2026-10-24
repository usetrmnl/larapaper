<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements PasskeyUser // implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * How long Auto-Join stays permitted after it is enabled.
     */
    public const int AUTO_JOIN_WINDOW_MINUTES = 15;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'assign_new_devices_until',
        'assign_new_device_id',
        'oidc_sub',
        'timezone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'assign_new_devices_until' => 'datetime',
        ];
    }

    /**
     * Whether devices may currently auto-join this user's account or re-run
     * setup for an already activated device.
     */
    public function hasActiveAutoJoin(): bool
    {
        return $this->assign_new_devices_until?->isFuture() ?? false;
    }

    /**
     * Permit Auto-Join for the next AUTO_JOIN_WINDOW_MINUTES minutes.
     */
    public function enableAutoJoin(): void
    {
        $this->update(['assign_new_devices_until' => now()->addMinutes(self::AUTO_JOIN_WINDOW_MINUTES)]);
    }

    public function disableAutoJoin(): void
    {
        $this->update(['assign_new_devices_until' => null]);
    }

    /**
     * Scope to users whose Auto-Join window is still open.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeWithActiveAutoJoin(Builder $query): Builder
    {
        return $query->where('assign_new_devices_until', '>', now());
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    public function preferredTimezone(): string
    {
        return $this->timezone ?? config('app.timezone');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function plugins(): HasMany
    {
        return $this->hasMany(Plugin::class);
    }

    public function routeNotificationForWebhook(): ?string
    {
        return config('services.webhook.notifications.url');
    }
}
