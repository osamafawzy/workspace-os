<?php

namespace Modules\Access\Filament\Admin\Resources\Users;

use App\Models\User;
use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Access\Filament\Admin\Resources\Users\Pages\CreateUser;
use Modules\Access\Filament\Admin\Resources\Users\Pages\EditUser;
use Modules\Access\Filament\Admin\Resources\Users\Pages\ListUsers;
use Modules\Access\Filament\Admin\Resources\Users\Schemas\UserForm;
use Modules\Access\Filament\Admin\Resources\Users\Tables\UsersTable;

/**
 * The people who can sign in to this panel.
 *
 * Only admin accounts live here — the public building view has no accounts at
 * all. What each person may do comes from the roles ticked on their record.
 */
class UserResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = User::class;

    protected static string $navigationKey = 'user-permission';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /** Every row shows its roles, and every policy check reads them. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
