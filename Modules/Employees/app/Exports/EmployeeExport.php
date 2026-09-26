<?php

namespace Modules\Employees\Exports;

use App\Support\Import\ImportColumn;
use App\Support\Spreadsheet\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Employees\Imports\EmployeeImporter;
use Modules\Employees\Models\Employee;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Employees to a spreadsheet, under the importer's headings, so an export
 * corrected in Excel imports back onto the same people.
 *
 * The national ID, address and emergency contact columns are only in the file
 * for somebody allowed to see them. For anyone else they are not blank — they
 * are not there, so an import of that file cannot empty anything.
 */
class EmployeeExport
{
    /**
     * @param  Builder<Employee>|Collection<int, Employee>  $records
     */
    public static function download(Builder|Collection $records, string $format): BinaryFileResponse
    {
        $importer = app(EmployeeImporter::class);
        $sensitive = $importer->mayRevealSensitive(auth()->user());
        $columns = array_values(array_filter(
            $importer->columns(),
            fn (ImportColumn $column): bool => $sensitive || ! in_array($column->field, $importer->sensitiveFields(), true),
        ));
        $relations = ['department', 'account', 'site', 'location', ...($sensitive ? ['emergencyContacts'] : [])];

        $employees = $records instanceof Builder
            ? $records->with($relations)->lazy(500)
            : $records->loadMissing($relations);

        return Spreadsheet::download(
            'employees-'.now()->format('Y-m-d-His'),
            $format,
            [...array_map(fn (ImportColumn $column): string => $column->label, $columns), 'Updated'],
            (function () use ($employees, $columns): \Generator {
                foreach ($employees as $employee) {
                    yield [
                        ...array_map(fn (ImportColumn $column): mixed => self::value($employee, $column->field), $columns),
                        $employee->updated_at?->format('Y-m-d H:i'),
                    ];
                }
            })(),
        );
    }

    protected static function value(Employee $employee, string $field): mixed
    {
        if (preg_match('/^ec([12])_(name|relationship|phone|address)$/', $field, $match)) {
            return $employee->emergencyContacts->firstWhere('slot', (int) $match[1])?->getAttribute($match[2]);
        }

        return match ($field) {
            'department' => $employee->department?->name,
            'account' => $employee->account?->name,
            'site' => $employee->site?->name,
            'location' => $employee->location?->name,
            'status' => $employee->status?->getLabel(),
            'joined_at' => $employee->joined_at?->format('Y-m-d'),
            'left_at' => $employee->left_at?->format('Y-m-d'),
            default => $employee->getAttribute($field),
        };
    }
}
