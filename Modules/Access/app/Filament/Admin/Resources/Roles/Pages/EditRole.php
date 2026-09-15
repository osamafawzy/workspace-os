<?php

namespace Modules\Access\Filament\Admin\Resources\Roles\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Access\Filament\Admin\Resources\Roles\RoleResource;
use Modules\Access\Filament\Admin\Resources\Roles\Schemas\RoleForm;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permission_groups'] = RoleForm::toGroups($data['permissions'] ?? []);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Absent when the role is a super admin: the permissions section is
        // hidden, and whatever the role held before is left as it was.
        if (array_key_exists('permission_groups', $data)) {
            $data['permissions'] = RoleForm::fromGroups($data['permission_groups'] ?? []);
        }

        unset($data['permission_groups']);

        if (! auth()->user()?->isSuperAdmin()) {
            unset($data['is_super_admin']);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
