<?php

namespace Modules\Assets\Actions;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\HandoverForm;
use Modules\Assets\Models\ReleaseBatch;
use Modules\Assets\Models\ReleaseBatchItem;
use Modules\Employees\Models\Employee;

/**
 * "Print New Data Forms and Archiving": releases a checked batch.
 *
 * In one transaction, with the batch locked and every check run again inside
 * it — the register may have changed since the checks were last run:
 * each row becomes an asset in the register, rows with an OID are handed to
 * their employee with a handover form each (through the same Assign action as
 * the Assign screen, so the papers and the ledger are the same), and the batch
 * is archived. Nothing is half-released: a problem found now undoes it all.
 */
class ReleaseBatchAssets
{
    public function __construct(
        protected CheckReleaseBatch $checks,
        protected AssignAssets $assign,
        protected AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, HandoverForm> the handover forms made, one per employee
     *
     * @throws ValidationException
     */
    public function handle(ReleaseBatch $batch, ?User $by): Collection
    {
        // Run once outside the transaction, so what they find stays on the
        // rows to be fixed even when the release is refused.
        if ($batch->isDraft()) {
            $this->checks->handle($batch, null, $by);

            if (! $this->checks->passes($batch)) {
                throw ValidationException::withMessages(['batch' => 'The checks found problems. Fix the rows marked in red, then release.']);
            }
        }

        return DB::transaction(function () use ($batch, $by): Collection {
            $batch = ReleaseBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if (! $batch->isDraft()) {
                throw ValidationException::withMessages(['batch' => "{$batch->number} has already been released."]);
            }

            // And again with the batch locked: somebody may have added an asset
            // with one of these serials in between.
            $this->checks->handle($batch, null, $by);

            if (! $this->checks->passes($batch)) {
                throw ValidationException::withMessages(['batch' => 'Something changed while releasing: the checks now find problems. Run them again to see.']);
            }

            $items = $batch->items()->orderBy('id')->get();
            $forms = collect();

            foreach ($items as $item) {
                $asset = Asset::query()->create([
                    'asset_type_id' => $batch->asset_type_id,
                    'asset_model_id' => $batch->asset_model_id,
                    'serial_number' => $item->serial_number,
                    'asset_tag' => $item->asset_tag,
                    'computer_name' => $item->computer_name,
                    'status' => AssetStatus::Available,
                    'condition' => $item->condition,
                    'site_id' => $batch->site_id,
                    'location_id' => $batch->location_id,
                    'account_id' => $batch->account_id,
                    'purchase_date' => $batch->purchase_date,
                    'warranty_expires_at' => $batch->warranty_expires_at,
                    'notes' => collect(["Released in {$batch->number}.", $item->notes])->filter()->implode(' '),
                ]);

                $item->forceFill(['asset_id' => $asset->getKey()])->saveQuietly();
            }

            // One form per employee, covering everything they get from this batch.
            $byEmployee = $items->filter(fn (ReleaseBatchItem $item): bool => $item->employee_oid !== null)
                ->groupBy(fn (ReleaseBatchItem $item): string => mb_strtolower($item->employee_oid));

            foreach ($byEmployee as $oid => $employeeItems) {
                // A numeric OID comes back from groupBy() as an integer key.
                $employee = Employee::query()->whereRaw('lower(oid) = ?', [(string) $oid])->firstOrFail();
                $form = $this->assign->handle($employee, $employeeItems->pluck('asset_id')->all(), $by, "Released in {$batch->number}.");

                ReleaseBatchItem::query()->whereKey($employeeItems->modelKeys())->update([
                    'employee_id' => $employee->getKey(),
                    'handover_form_id' => $form->getKey(),
                ]);

                $forms->push($form);
            }

            $batch->forceFill([
                'status' => ReleaseBatch::ARCHIVED,
                'released_at' => now(),
                'released_by' => $by?->getKey(),
                'released_by_name' => $by?->name,
            ])->save();

            $this->audit->log('released', 'Assets', $batch, [], [
                'assets' => $items->count(),
                'to employees' => $byEmployee->count(),
                'forms' => $forms->pluck('number')->implode(', ') ?: null,
            ], $batch->number);

            return $forms;
        });
    }
}
