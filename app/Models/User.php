<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'assign_new_devices',
        'assign_new_device_id',
        'oidc_sub',
        'timezone',
        'is_admin',
        'confirmed_at',
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
            'assign_new_devices' => 'boolean',
            'is_admin' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
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

    public function isAdmin(): bool
    {
        return $this->id === 1 || $this->is_admin;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->id === 1) {
                $user->is_admin = true;
                if ($user->confirmed_at === null) {
                    $user->confirmed_at = now();
                }
            }
        });

        static::created(function (User $user): void {
            if ($user->id === 1) {
                $updates = ['is_admin' => true];
                if ($user->confirmed_at === null) {
                    $updates['confirmed_at'] = now()->toDateTimeString();
                }
                \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', 1)
                    ->update($updates);
            }
        });
    }
}
