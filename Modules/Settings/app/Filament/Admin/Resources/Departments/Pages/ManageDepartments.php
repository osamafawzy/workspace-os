<?php

namespace Modules\Settings\Filament\Admin\Resources\Departments\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Settings\Filament\Admin\Resources\Departments\DepartmentResource;

class ManageDepartments extends ManageRecords
{
    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New department'),
        ];
    }
}
