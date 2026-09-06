<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
     * Every account is staff for now: there is one panel, one kind of user,
     * and no roles yet. When roles arrive this is the single place that
     * decides, so it becomes a permission check rather than a new concept.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
