<?php

namespace Modules\Access\Filament\Admin\Resources\Users\Schemas;

use App\Models\User;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Modules\Access\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->description('The details this person signs in with.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->minLength(8)
                        ->maxLength(255)
                        ->confirmed()
                        // Empty on the edit screen means "leave it alone", not
                        // "set it to nothing". The model hashes whatever does
                        // get through.
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation): ?string => $operation === 'edit'
                            ? 'Leave empty to keep the current password.'
                            : 'At least 8 characters.'),

                    TextInput::make('password_confirmation')
                        ->label('Confirm password')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => filled($get('password')))
                        ->dehydrated(false),
                ]),

            Section::make('Roles')
                ->description('What this person may do. Somebody with no role cannot sign in at all.')
                ->schema([
                    CheckboxList::make('roles')
                        ->hiddenLabel()
                        ->relationship(
                            'roles',
                            'name',
                            // Only a super admin can make somebody else one.
                            // Otherwise "edit users" would be a way to hand
                            // yourself, or a friend, everything.
                            modifyQueryUsing: fn (Builder $query): Builder => self::actorIsSuperAdmin()
                                ? $query->orderByDesc('is_super_admin')->orderBy('name')
                                : $query->where('is_super_admin', false)->orderBy('name'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Role $role): string => $role->is_super_admin
                            ? $role->name.' — full access'
                            : $role->name)
                        ->columns(2)
                        ->rules([
                            fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                if (! $record?->isSuperAdmin() || Role::otherSuperAdminsExist(exceptUser: $record)) {
                                    return;
                                }

                                $keepsIt = Role::query()
                                    ->whereKey((array) $value)
                                    ->where('is_super_admin', true)
                                    ->exists();

                                if (! $keepsIt) {
                                    $fail('This is the only super admin. Make somebody else a super admin before taking it away from this account.');
                                }
                            },
                        ]),
                ]),
        ]);
    }

    protected static function actorIsSuperAdmin(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
