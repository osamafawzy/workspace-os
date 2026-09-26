<?php

namespace Modules\Employees\Filament\Admin\Resources\Employees;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\CreateEmployee;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\ListEmployees;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\ViewEmployee;
use Modules\Employees\Filament\Admin\Resources\Employees\Schemas\EmployeeForm;
use Modules\Employees\Filament\Admin\Resources\Employees\Schemas\EmployeeInfolist;
use Modules\Employees\Filament\Admin\Resources\Employees\Tables\EmployeesTable;
use Modules\Employees\Models\Employee;

/**
 * Asset Management → Employees Data.
 *
 * Personal data (national ID, home address, emergency contacts) is not merely
 * hidden from people without `employees.view_sensitive`: the form, the profile
 * and the list are built without it, so it is never sent to their browser.
 */
class EmployeeResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = Employee::class;

    protected static string $navigationKey = 'employees';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmployeeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['department', 'account', 'site', 'location']);
    }

    /** Whether the signed-in user may see personal data. */
    public static function canViewSensitive(): bool
    {
        return auth()->user()?->can('viewSensitive', Employee::class) ?? false;
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'oid', 'employee_number', 'email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Employee $record */
        return $record->auditLabel();
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Employee $record */
        return array_filter([
            'Status' => $record->status->getLabel(),
            'Department' => $record->department?->name,
            'Job title' => $record->job_title,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'view' => ViewEmployee::route('/{record}'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
