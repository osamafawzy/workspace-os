<?php

namespace Modules\Access\Filament\Admin\Resources\Roles\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Access\Filament\Admin\Resources\Roles\RoleResource;
use Modules\Access\Filament\Admin\Resources\Roles\Schemas\RoleForm;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['permissions'] = RoleForm::fromGroups($data['permission_groups'] ?? []);
        unset($data['permission_groups']);

        if (! auth()->user()?->isSuperAdmin()) {
            $data['is_super_admin'] = false;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
