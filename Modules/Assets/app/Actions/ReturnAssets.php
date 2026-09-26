<?php

namespace Modules\Assets\Actions;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetReturn;
use Modules\Assets\Models\HandoverForm;
use Modules\Employees\Models\Employee;

/**
 * Takes assets back and makes a return receipt for each employee they came
 * from.
 *
 * All or nothing, in one transaction, every asset locked and checked to be
 * with somebody inside it. For each asset: its open assignment is closed, a
 * return is recorded (date, condition, who brought it, who received it, where
 * it went), and the asset is marked Returned with nobody — which writes its
 * history. Assets from several employees in one go get a receipt each, because
 * each employee signs for their own.
 */
class ReturnAssets
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * @param  array<int, string|AssetCondition>  $conditions  asset id => condition it came back in
     * @param  array{returned_at?: mixed, returned_by_name?: ?string, site_id?: ?int, location_id?: ?int, notes?: ?string}  $details
     * @return Collection<int, HandoverForm> the receipts, one per employee
     *
     * @throws ValidationException
     */
    public function handle(array $conditions, array $details, ?User $by): Collection
    {
        if ($conditions === []) {
            throw ValidationException::withMessages(['assets' => 'Choose at least one asset to return.']);
        }

        $conditions = collect($conditions)->mapWithKeys(function (mixed $condition, int|string $id): array {
            $parsed = $condition instanceof AssetCondition ? $condition : AssetCondition::tryFrom((string) $condition);

            if (! $parsed) {
                throw ValidationException::withMessages(['conditions' => 'Say what condition every returned asset is in.']);
            }

            return [(int) $id => $parsed];
        });

        $returnedAt = filled($details['returned_at'] ?? null) ? Carbon::parse($details['returned_at']) : now();

        if ($returnedAt->isFuture() && ! $returnedAt->isToday()) {
            throw ValidationException::withMessages(['returned_at' => 'The return date cannot be in the future.']);
        }

        $notes = filled($details['notes'] ?? null) ? mb_substr(trim((string) $details['notes']), 0, 2000) : null;

        return DB::transaction(function () use ($conditions, $details, $by, $returnedAt, $notes): Collection {
            $assets = Asset::query()
                ->with(['assetType', 'assetModel.manufacturer', 'employee'])
                ->whereKey($conditions->keys())
                ->lockForUpdate()
                ->get();

            if ($assets->count() !== $conditions->count()) {
                throw ValidationException::withMessages(['assets' => 'Some of the chosen assets no longer exist.']);
            }

            $free = $assets->whereNull('employee_id');

            if ($free->isNotEmpty()) {
                throw ValidationException::withMessages(['assets' => $free->pluck('serial_number')->implode(', ').' '.($free->count() === 1 ? 'is' : 'are').' not with anybody.']);
            }

            $receipts = collect();

            foreach ($assets->groupBy('employee_id') as $employeeAssets) {
                /** @var Employee $employee */
                $employee = $employeeAssets->first()->employee;
                $returnedBy = filled($details['returned_by_name'] ?? null) ? mb_substr(trim((string) $details['returned_by_name']), 0, 150) : $employee->name;

                $receipt = HandoverForm::query()->create([
                    'kind' => HandoverForm::RETURN,
                    'employee_id' => $employee->getKey(),
                    'generated_by' => $by?->getKey(),
                    'generated_by_name' => $by?->name,
                    'snapshot' => [
                        'company' => HandoverSnapshot::company(),
                        'employee' => HandoverSnapshot::employee($employee),
                        'contacts' => [],
                        'assets' => $employeeAssets->sortBy('serial_number')->map(fn (Asset $asset): array => [
                            ...HandoverSnapshot::asset($asset),
                            'condition' => $conditions[$asset->getKey()]->getLabel(),
                            'assigned_at' => $asset->assigned_at?->toDateString(),
                        ])->values()->all(),
                        'returned_at' => $returnedAt->toIso8601String(),
                        'returned_by' => $returnedBy,
                        'received_by' => $by?->name,
                        'notes' => $notes,
                    ],
                ]);

                foreach ($employeeAssets as $asset) {
                    $assignment = $asset->openAssignment();

                    // An asset held since before assignments were recorded,
                    // or given by an import, still has a spell to close.
                    if (! $assignment) {
                        $asset->syncAssignments();
                        $assignment = $asset->openAssignment();
                    }

                    $assignment->update(['returned_at' => $returnedAt]);

                    AssetReturn::query()->create([
                        'asset_assignment_id' => $assignment->getKey(),
                        'asset_id' => $asset->getKey(),
                        'employee_id' => $employee->getKey(),
                        'handover_form_id' => $receipt->getKey(),
                        'returned_at' => $returnedAt,
                        'condition' => $conditions[$asset->getKey()],
                        'returned_by_name' => $returnedBy,
                        'received_by' => $by?->getKey(),
                        'received_by_name' => $by?->name,
                        'site_id' => $details['site_id'] ?? null,
                        'location_id' => $details['location_id'] ?? null,
                        'notes' => $notes,
                    ]);

                    $asset->update(array_filter([
                        'employee_id' => null,
                        'status' => AssetStatus::Returned,
                        'condition' => $conditions[$asset->getKey()],
                        'site_id' => $details['site_id'] ?? null,
                        'location_id' => $details['location_id'] ?? null,
                    ], fn (mixed $value, string $key): bool => $value !== null || $key === 'employee_id', ARRAY_FILTER_USE_BOTH));
                }

                $this->audit->log('assets returned', 'Assets', $employee, [], [
                    'receipt' => $receipt->number,
                    'assets' => $employeeAssets->pluck('serial_number')->sort()->values()->implode(', '),
                ], $employee->auditLabel());

                $receipts->push($receipt->refresh());
            }

            return $receipts;
        });
    }
}
