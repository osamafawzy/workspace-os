<?php

namespace Modules\Access\Filament\Admin\Resources\Roles;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    protected static string|\UnitEnum|null $navigationGroup = 'Access';

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
