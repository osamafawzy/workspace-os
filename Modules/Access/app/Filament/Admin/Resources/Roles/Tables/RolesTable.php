<?php

namespace Modules\Access\Filament\Admin\Resources\Roles\Tables;

use App\Support\Permissions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Access\Models\Role;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Role $record): ?string => $record->description),

                IconColumn::make('is_super_admin')
                    ->label('Super admin')
                    ->boolean(),

                TextColumn::make('permissions')
                    ->label('Permissions')
                    ->state(function (Role $record): string {
                        if ($record->is_super_admin) {
                            return 'Everything';
                        }

                        return count(app(Permissions::class)->only($record->permissions ?? []))
                            .' of '.count(app(Permissions::class)->keys());
                    }),

                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->sortable(),
            ])
            // Per-row only: deleting a role is checked against that role, so
            // the last super admin role cannot go. A bulk delete would not ask.
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No roles yet');
    }
}
