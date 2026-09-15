<?php

namespace Modules\Access\Filament\Admin\Resources\Roles\Schemas;

use App\Support\Permissions;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Modules\Access\Models\Role;

/**
 * The role screen: a name, the super admin switch, and the permissions laid
 * out in the groups each module registered them under.
 *
 * The permissions are one list on the role but several checkbox lists on the
 * screen, one per group, under `permission_groups`. The pages fold them back
 * into the single list with {@see fromGroups()} before saving, and spread it
 * out again with {@see toGroups()} when the form is filled.
 */
class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Role')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true),

                    Toggle::make('is_super_admin')
                        ->label('Super admin')
                        ->inline(false)
                        ->live()
                        ->helperText('Full access to everything, including permissions added later. Only a super admin can change this.')
                        // Disabled fields are not saved, so somebody who is
                        // not a super admin cannot switch this on however the
                        // request is put together. The pages drop it too.
                        ->disabled(fn (): bool => ! (auth()->user()?->isSuperAdmin() ?? false))
                        ->rules([
                            fn (?Role $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                if (! $record?->is_super_admin || $value) {
                                    return;
                                }

                                if ($record->users()->exists() && ! Role::otherSuperAdminsExist(exceptRole: $record)) {
                                    $fail('Nobody else holds super admin. Give another role the flag, or another user this role, first.');
                                }
                            },
                        ]),

                    Textarea::make('description')
                        ->label('Description')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ]),

            Section::make('Permissions')
                ->description('What users holding this role may do.')
                // A super admin is not checked against permissions at all, so
                // a wall of checkboxes here would only suggest otherwise.
                ->hidden(fn (Get $get): bool => (bool) $get('is_super_admin'))
                ->schema([
                    Group::make()
                        ->statePath('permission_groups')
                        ->schema(self::permissionFields()),
                ]),
        ]);
    }

    /** @return array<int, Fieldset> */
    protected static function permissionFields(): array
    {
        $fields = [];

        foreach (app(Permissions::class)->groups() as $group => $permissions) {
            $fields[] = Fieldset::make($group)
                ->schema([
                    CheckboxList::make(Str::slug($group))
                        ->hiddenLabel()
                        ->options($permissions)
                        ->bulkToggleable()
                        ->columns(2)
                        ->columnSpanFull(),
                ]);
        }

        return $fields;
    }

    /**
     * The role's flat permission list, spread into the per-group lists the
     * form shows.
     *
     * @param  array<int, string>  $permissions
     * @return array<string, array<int, string>>
     */
    public static function toGroups(array $permissions): array
    {
        $groups = [];

        foreach (app(Permissions::class)->groups() as $group => $available) {
            $groups[Str::slug($group)] = array_values(array_intersect(array_keys($available), $permissions));
        }

        return $groups;
    }

    /**
     * The per-group lists, folded back into one list of permission keys.
     * Anything that is not a registered permission is dropped on the way.
     *
     * @param  array<string, mixed>  $groups
     * @return array<int, string>
     */
    public static function fromGroups(array $groups): array
    {
        $chosen = [];

        foreach ($groups as $keys) {
            $chosen = [...$chosen, ...array_filter((array) $keys, 'is_string')];
        }

        return app(Permissions::class)->only($chosen);
    }
}
