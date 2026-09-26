<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Assets\Enums\AssetCondition;
use Modules\Employees\Models\Employee;

/**
 * One piece of a new delivery: its labels, and who it is going to.
 *
 * @property int $id
 * @property int $release_batch_id
 * @property string $serial_number
 * @property string|null $asset_tag
 * @property string|null $computer_name
 * @property string|null $employee_oid
 * @property AssetCondition $condition
 * @property string|null $notes
 * @property array<string, list<array{level: string, text: string}>>|null $findings
 * @property int|null $employee_id
 * @property int|null $asset_id
 * @property int|null $handover_form_id
 */
class ReleaseBatchItem extends Model
{
    protected $fillable = [
        'release_batch_id',
        'serial_number',
        'asset_tag',
        'computer_name',
        'employee_oid',
        'condition',
        'notes',
    ];

    protected $attributes = [
        'condition' => 'new',
    ];

    protected function casts(): array
    {
        return [
            'condition' => AssetCondition::class,
            'findings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ReleaseBatchItem $item): void {
            $item->serial_number = trim((string) $item->serial_number);

            foreach (['asset_tag', 'computer_name', 'employee_oid'] as $field) {
                $item->{$field} = filled($item->{$field}) ? trim((string) $item->{$field}) : null;
            }

            // An edited row has not been checked in its new form.
            if ($item->exists && $item->isDirty(['serial_number', 'asset_tag', 'computer_name', 'employee_oid'])) {
                $item->findings = null;
            }
        });
    }

    /** @return BelongsTo<ReleaseBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReleaseBatch::class, 'release_batch_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<HandoverForm, $this> */
    public function handoverForm(): BelongsTo
    {
        return $this->belongsTo(HandoverForm::class);
    }

    /** @return list<array{level: string, text: string}> */
    public function allFindings(): array
    {
        return array_merge(...array_values($this->findings ?? [])) ?: [];
    }

    public function hasErrors(): bool
    {
        return collect($this->allFindings())->contains('level', 'error');
    }
}
