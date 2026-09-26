<?php

namespace Modules\Assets\Actions;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\HandoverForm;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Models\Employee;

/**
 * Hands assets to an employee and makes the handover form.
 *
 * All or nothing, in one transaction: every asset is locked and checked to be
 * free *inside* it, so two people handing out the same laptop at once cannot
 * both succeed. Each asset is marked Assigned to the employee (which writes
 * its history and opens its assignment), the assignments are tied to the form,
 * and one audit entry records the handover as a whole.
 */
class AssignAssets
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * @param  list<int>  $assetIds
     *
     * @throws ValidationException when the employee or any asset cannot take part
     */
    public function handle(Employee $employee, array $assetIds, ?User $by, ?string $notes = null): HandoverForm
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));

        if ($assetIds === []) {
            throw ValidationException::withMessages(['assets' => 'Choose at least one asset to hand over.']);
        }

        if ($employee->status === EmployeeStatus::Left) {
            throw ValidationException::withMessages(['employee' => "{$employee->name} has left; assets cannot be handed to them."]);
        }

        $notes = filled($notes) ? mb_substr(trim($notes), 0, 2000) : null;

        return DB::transaction(function () use ($employee, $assetIds, $by, $notes): HandoverForm {
            $assets = Asset::query()
                ->with(['assetType', 'assetModel.manufacturer'])
                ->whereKey($assetIds)
                ->lockForUpdate()
                ->get();

            $missing = array_diff($assetIds, $assets->modelKeys());

            if ($missing !== []) {
                throw ValidationException::withMessages(['assets' => 'Some of the chosen assets no longer exist.']);
            }

            $taken = $assets->reject(fn (Asset $asset): bool => $asset->isAssignable());

            if ($taken->isNotEmpty()) {
                throw ValidationException::withMessages(['assets' => $taken
                    ->map(fn (Asset $asset): string => "{$asset->serial_number} is ".mb_strtolower($asset->status->getLabel()).($asset->employee_id ? ' to somebody else' : '').'.')
                    ->implode(' ')]);
            }

            $form = HandoverForm::query()->create([
                'kind' => HandoverForm::HANDOVER,
                'employee_id' => $employee->getKey(),
                'generated_by' => $by?->getKey(),
                'generated_by_name' => $by?->name,
                'snapshot' => [
                    'company' => HandoverSnapshot::company(),
                    'employee' => HandoverSnapshot::employee($employee),
                    'contacts' => HandoverSnapshot::contacts($employee),
                    'assets' => $assets->sortBy('serial_number')->map(fn (Asset $asset): array => HandoverSnapshot::asset($asset))->values()->all(),
                    'issued_by' => $by?->name,
                    'issued_at' => now()->toIso8601String(),
                    'notes' => $notes,
                ],
            ]);

            foreach ($assets as $asset) {
                // Marks it Assigned, writes its history, opens its assignment.
                $asset->update(['employee_id' => $employee->getKey(), 'status' => AssetStatus::Assigned]);

                $asset->openAssignment()?->update([
                    'handover_form_id' => $form->getKey(),
                    'notes' => $notes,
                ]);
            }

            $this->audit->log('assets assigned', 'Assets', $employee, [], [
                'form' => $form->number,
                'assets' => $assets->pluck('serial_number')->sort()->values()->implode(', '),
            ], $employee->auditLabel());

            return $form->refresh();
        });
    }
}
