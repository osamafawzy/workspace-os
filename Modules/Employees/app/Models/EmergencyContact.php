<?php

namespace Modules\Employees\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who to call about an employee: slot 1 first, slot 2 if nobody answers.
 *
 * Personal data about somebody who does not work here, so it is shown only
 * with `employees.view_sensitive`, like the employee's own address.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $slot
 * @property string $name
 * @property string|null $relationship
 * @property string|null $phone
 * @property string|null $address
 */
class EmergencyContact extends Model
{
    use Auditable;

    public const SLOTS = [1, 2];

    protected $fillable = [
        'employee_id',
        'slot',
        'name',
        'relationship',
        'phone',
        'address',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function auditLabel(): string
    {
        return ($this->employee ? "{$this->employee->auditLabel()} · " : '')."emergency contact {$this->slot}";
    }

    public function auditModule(): string
    {
        return 'Employees';
    }

    /**
     * That a contact changed is recorded; who they are and how to reach them
     * is not — the audit log is readable by people who may not see it.
     */
    protected function auditRedacts(string $key): bool
    {
        return in_array($key, ['name', 'relationship', 'phone', 'address'], true);
    }
}
