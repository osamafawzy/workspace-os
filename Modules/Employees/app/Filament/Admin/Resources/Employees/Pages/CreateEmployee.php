<?php

namespace Modules\Employees\Filament\Admin\Resources\Employees\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
