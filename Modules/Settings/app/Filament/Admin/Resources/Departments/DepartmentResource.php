<?php

namespace Modules\Settings\Filament\Admin\Resources\Departments;

use Modules\Settings\Filament\Admin\Resources\Departments\Pages\ManageDepartments;
use Modules\Settings\Filament\Admin\Support\LookupResource;
use Modules\Settings\Models\Department;

class DepartmentResource extends LookupResource
{
    protected static ?string $model = Department::class;

    protected static string $navigationKey = 'departments';

    public static function getPages(): array
    {
        return [
            'index' => ManageDepartments::route('/'),
        ];
    }
}
