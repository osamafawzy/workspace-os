<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Access\Models\Concerns\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Properties, not the #[Fillable] / #[Hidden] attributes: those arrived in
     * Laravel 13, and Laravel 12 ignores them without a word — which left the
     * password hash in every serialized user.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
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
        ];
    }

    /**
     * Who gets into the admin panel.
     *
     * Without this contract Filament falls back to "local environment only",
     * which means the panel answers 403 to every user the moment it is
     * deployed anywhere real — and the failure looks like a permissions bug
     * rather than a missing opt-in.
     *
     * An account with no role is a login that goes nowhere, so it does not
     * get in. What a user can do once inside is decided by the policies, from
     * the permissions their roles carry.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles->isNotEmpty();
    }
}
