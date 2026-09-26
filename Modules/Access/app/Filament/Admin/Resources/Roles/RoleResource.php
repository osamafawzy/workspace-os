<?php

namespace Modules\Access\Filament\Admin\Resources\Roles;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Access\Filament\Admin\Resources\Roles\Pages\CreateRole;
use Modules\Access\Filament\Admin\Resources\Roles\Pages\EditRole;
use Modules\Access\Filament\Admin\Resources\Roles\Pages\ListRoles;
use Modules\Access\Filament\Admin\Resources\Roles\Schemas\RoleForm;
use Modules\Access\Filament\Admin\Resources\Roles\Tables\RolesTable;
use Modules\Access\Models\Role;

/**
 * Named sets of permissions, handed out to users.
 */
class RoleResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = Role::class;

    protected static string $navigationKey = 'roles';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
