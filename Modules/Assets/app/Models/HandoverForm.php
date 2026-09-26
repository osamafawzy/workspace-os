<?php

namespace Modules\Assets\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Employees\Models\Employee;

/**
 * A printed paper: a handover form when assets go out, a return receipt when
 * they come back.
 *
 * What it says is frozen in `snapshot` when it is made, so every reprint is
 * the same paper. The snapshot is encrypted because it carries the employee's
 * emergency contacts.
 *
 * @property int $id
 * @property string|null $number
 * @property string $kind
 * @property int $employee_id
 * @property array<string, mixed> $snapshot
 * @property string|null $generated_by_name
 * @property int $print_count
 * @property Carbon|null $last_printed_at
 * @property Carbon $created_at
 * @property-read Employee $employee
 */
class HandoverForm extends Model
{
    use Auditable;

    public const HANDOVER = 'handover';

    public const RETURN = 'return';

    protected $fillable = [
        'kind',
        'employee_id',
        'snapshot',
        'generated_by',
        'generated_by_name',
        'print_count',
        'last_printed_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'encrypted:array',
            'print_count' => 'integer',
            'last_printed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // HO-2026-000123: the year it was made and a number nobody else has.
        static::created(function (HandoverForm $form): void {
            $prefix = $form->kind === self::RETURN ? 'RT' : 'HO';
            $form->forceFill(['number' => sprintf('%s-%s-%06d', $prefix, $form->created_at->format('Y'), $form->getKey())])->saveQuietly();
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @return HasMany<AssetAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    /** @return HasMany<AssetReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(AssetReturn::class);
    }

    public function title(): string
    {
        return $this->kind === self::RETURN ? 'Asset Return Receipt' : 'IT Asset Handover Form';
    }

    public function kindLabel(): string
    {
        return $this->kind === self::RETURN ? 'Return receipt' : 'Handover form';
    }

    public function assetCount(): int
    {
        return count($this->snapshot['assets'] ?? []);
    }

    public function auditLabel(): string
    {
        return (string) ($this->number ?? $this->kindLabel());
    }

    /** The snapshot holds personal data; the log records that a form exists, not what it says. */
    protected function auditRedacts(string $key): bool
    {
        return $key === 'snapshot';
    }

    public function auditModule(): string
    {
        return 'Assets';
    }
}
