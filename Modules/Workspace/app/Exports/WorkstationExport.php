<?php

namespace Modules\Workspace\Exports;

use App\Support\Import\ImportColumn;
use App\Support\Spreadsheet\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Workspace\Imports\WorkstationImporter;
use Modules\Workspace\Models\Workstation;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Workstations to a spreadsheet.
 *
 * The columns are the importer's, in the importer's order and under its
 * headings, so an export is also a ready-made import file: correct it in Excel,
 * import it back with "update existing", and the same desks change.
 */
class WorkstationExport
{
    /**
     * @param  Builder<Workstation>|Collection<int, Workstation>  $records
     */
    public static function download(Builder|Collection $records, string $format): BinaryFileResponse
    {
        $columns = app(WorkstationImporter::class)->columns();
        $relations = ['floor.building', 'area', 'switchPort.networkSwitch.rack', 'vlan'];

        $desks = $records instanceof Builder
            ? $records->with($relations)->withExists('mapObject as is_placed')->lazy(500)
            : $records->loadMissing([...$relations, 'mapObject']);

        return Spreadsheet::download(
            'workstations-'.now()->format('Y-m-d-His'),
            $format,
            [...array_map(fn (ImportColumn $column): string => $column->label, $columns), 'On Plan', 'Updated'],
            (function () use ($desks, $columns): \Generator {
                foreach ($desks as $desk) {
                    yield [
                        ...array_map(fn (ImportColumn $column): mixed => self::value($desk, $column->field), $columns),
                        $desk->isPlaced() ? 'Yes' : 'No',
                        $desk->updated_at?->format('Y-m-d H:i'),
                    ];
                }
            })(),
        );
    }

    protected static function value(Workstation $desk, string $field): mixed
    {
        return match ($field) {
            'building' => $desk->floor?->building?->name,
            'floor' => $desk->floor?->name,
            'area' => $desk->area?->name,
            'status' => $desk->status?->getLabel(),
            'switch' => $desk->networkSwitch()?->number,
            'port' => $desk->switchPort?->name,
            'port_number' => $desk->switchPort?->number,
            'rack' => $desk->rack()?->number,
            'vlan' => $desk->vlan?->number,
            default => $desk->getAttribute($field),
        };
    }
}
