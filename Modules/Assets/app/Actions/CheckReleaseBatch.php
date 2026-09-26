<?php

namespace Modules\Assets\Actions;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\ReleaseBatch;
use Modules\Assets\Models\ReleaseBatchItem;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Models\Employee;

/**
 * The three checks a batch of new data goes through before it is released.
 *
 *  - employees: every OID is an employee who has not left;
 *  - new:       every row is complete and no two rows share a serial or tag;
 *  - old:       no serial or tag is already on an asset in the register.
 *
 * Each check replaces its own findings on every row and records when it ran,
 * so the batch screen shows exactly what the last run of each found. Nothing
 * else is written: a check is safe to run as often as anybody likes.
 */
class CheckReleaseBatch
{
    /**
     * @param  list<string>|null  $checks  which to run; all of them when null
     * @return array<string, int> check => rows with a problem
     */
    public function handle(ReleaseBatch $batch, ?array $checks = null, ?User $by = null): array
    {
        $checks ??= array_keys(ReleaseBatch::CHECKS);
        $batch->loadMissing('assetType');
        $items = $batch->items()->orderBy('id')->get();
        $problems = [];

        foreach ($checks as $check) {
            $found = match ($check) {
                'employees' => $this->employees($items),
                'new' => $this->newData($batch, $items),
                'old' => $this->oldData($items),
            };

            foreach ($items as $item) {
                $item->findings = [...($item->findings ?? []), $check => $found[$item->getKey()] ?? []];
            }

            $problems[$check] = count(array_filter($found, fn (array $messages): bool => collect($messages)->contains('level', 'error')));

            $batch->checks = [...($batch->checks ?? []), $check => [
                'at' => now()->toIso8601String(),
                'by' => $by?->name,
                'problems' => $problems[$check],
                'rows' => $items->count(),
            ]];
        }

        // Written without touching the rows' "edited, so unchecked" rule.
        foreach ($items as $item) {
            ReleaseBatchItem::query()->whereKey($item->getKey())->update(['findings' => json_encode($item->findings)]);
        }

        $batch->saveQuietly();

        return $problems;
    }

    /** Whether every check has run since the rows last changed, and found nothing wrong. */
    public function passes(ReleaseBatch $batch): bool
    {
        $items = $batch->items()->get();

        if ($items->isEmpty()) {
            return false;
        }

        foreach ($items as $item) {
            foreach (array_keys(ReleaseBatch::CHECKS) as $check) {
                if (! array_key_exists($check, $item->findings ?? [])) {
                    return false;
                }
            }

            if ($item->hasErrors()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, ReleaseBatchItem>  $items
     * @return array<int, list<array{level: string, text: string}>>
     */
    protected function employees(Collection $items): array
    {
        $oids = $items->pluck('employee_oid')->filter()->unique()->values();
        $employees = Employee::query()->whereIn('oid', $oids)->get()->keyBy(fn (Employee $employee): string => mb_strtolower($employee->oid));
        $found = [];

        foreach ($items as $item) {
            $messages = [];

            if ($item->employee_oid === null) {
                $messages[] = ['level' => 'warning', 'text' => 'No OID: goes into stock, not to anybody.'];
            } else {
                $employee = $employees->get(mb_strtolower($item->employee_oid));

                if (! $employee) {
                    $messages[] = ['level' => 'error', 'text' => "No employee has OID {$item->employee_oid}."];
                } elseif ($employee->status === EmployeeStatus::Left) {
                    $messages[] = ['level' => 'error', 'text' => "{$employee->name} ({$employee->oid}) has left."];
                } elseif ($employee->status === EmployeeStatus::OnLeave) {
                    $messages[] = ['level' => 'warning', 'text' => "{$employee->name} is on leave."];
                }
            }

            $found[$item->getKey()] = $messages;
        }

        return $found;
    }

    /**
     * @param  Collection<int, ReleaseBatchItem>  $items
     * @return array<int, list<array{level: string, text: string}>>
     */
    protected function newData(ReleaseBatch $batch, Collection $items): array
    {
        $serials = $items->groupBy(fn (ReleaseBatchItem $item): string => mb_strtolower($item->serial_number));
        $tags = $items->filter(fn (ReleaseBatchItem $item): bool => $item->asset_tag !== null)->groupBy(fn (ReleaseBatchItem $item): string => mb_strtolower($item->asset_tag));
        $needsName = (bool) $batch->assetType?->has_computer_name;
        $found = [];

        foreach ($items as $index => $item) {
            $messages = [];

            if ($item->serial_number === '') {
                $messages[] = ['level' => 'error', 'text' => 'Serial number is empty.'];
            } elseif ($serials[mb_strtolower($item->serial_number)]->count() > 1) {
                $messages[] = ['level' => 'error', 'text' => "Serial {$item->serial_number} is on more than one row."];
            }

            if ($item->asset_tag !== null && $tags[mb_strtolower($item->asset_tag)]->count() > 1) {
                $messages[] = ['level' => 'error', 'text' => "Tag {$item->asset_tag} is on more than one row."];
            }

            if ($needsName && $item->computer_name === null) {
                $messages[] = ['level' => 'warning', 'text' => "No computer name for a {$batch->assetType->name}."];
            }

            $found[$item->getKey()] = $messages;
        }

        return $found;
    }

    /**
     * @param  Collection<int, ReleaseBatchItem>  $items
     * @return array<int, list<array{level: string, text: string}>>
     */
    protected function oldData(Collection $items): array
    {
        $bySerial = Asset::query()
            ->whereIn('serial_number', $items->pluck('serial_number')->filter())
            ->pluck('serial_number')
            ->map(fn (string $serial): string => mb_strtolower($serial))
            ->flip();

        $byTag = Asset::query()
            ->whereIn('asset_tag', $items->pluck('asset_tag')->filter())
            ->get(['asset_tag', 'serial_number'])
            ->keyBy(fn (Asset $asset): string => mb_strtolower($asset->asset_tag));

        $found = [];

        foreach ($items as $item) {
            $messages = [];

            if ($item->serial_number !== '' && isset($bySerial[mb_strtolower($item->serial_number)])) {
                $messages[] = ['level' => 'error', 'text' => "Serial {$item->serial_number} is already in the register."];
            }

            if ($item->asset_tag !== null && $byTag->has(mb_strtolower($item->asset_tag))) {
                $messages[] = ['level' => 'error', 'text' => "Tag {$item->asset_tag} is already on {$byTag[mb_strtolower($item->asset_tag)]->serial_number}."];
            }

            $found[$item->getKey()] = $messages;
        }

        return $found;
    }
}
