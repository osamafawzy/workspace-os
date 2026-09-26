<?php

namespace Modules\Employees\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\Employees\Database\Factories\EmployeeFactory;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Department;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;

/**
 * Somebody who works here, and so somebody assets can be handed to.
 *
 * Personal data — the national ID, the home address and the emergency
 * contacts — is only shown to people with `employees.view_sensitive`. The
 * national ID is also encrypted in the database and never written to the
 * audit log.
 *
 * @property int $id
 * @property string $oid
 * @property string|null $employee_number
 * @property string $name
 * @property string|null $email
 * @property string|null $mobile
 * @property string|null $national_id
 * @property string|null $address
 * @property int|null $department_id
 * @property string|null $job_title
 * @property int|null $account_id
 * @property int|null $site_id
 * @property int|null $location_id
 * @property EmployeeStatus $status
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 * @property string|null $notes
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use Auditable, HasFactory;

    /** The fields only `employees.view_sensitive` shows. Emergency contacts are too. */
    public const SENSITIVE = ['national_id', 'address'];

    protected $fillable = [
        'oid',
        'employee_number',
        'name',
        'email',
        'mobile',
        'national_id',
        'address',
        'department_id',
        'job_title',
        'account_id',
        'site_id',
        'location_id',
        'status',
        'joined_at',
        'left_at',
        'notes',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'status' => EmployeeStatus::class,
            'joined_at' => 'date',
            'left_at' => 'date',
        ];
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    protected static function booted(): void
    {
        // Kept in step with the ID on every save, whichever screen or import
        // wrote it, so a duplicate can always be found.
        static::saving(function (Employee $employee): void {
            if ($employee->isDirty('national_id')) {
                $employee->national_id_hash = self::hashNationalId($employee->national_id);
            }
        });
    }

    /**
     * A keyed hash of a national ID, spaces and dashes ignored, for spotting the
     * same ID twice without being able to read it back.
     */
    public static function hashNationalId(?string $nationalId): ?string
    {
        $normalised = self::normaliseNationalId($nationalId);

        return $normalised === null ? null : hash_hmac('sha256', $normalised, (string) config('app.key'));
    }

    public static function normaliseNationalId(?string $nationalId): ?string
    {
        $normalised = strtoupper((string) preg_replace('/[\s\-.]/', '', (string) $nationalId));

        return $normalised === '' ? null : $normalised;
    }

    /** @var list<\Closure(Employee): bool> */
    protected static array $usageChecks = [];

    /**
     * Lets another module say "this employee cannot be deleted while I point
     * at them" — the Assets module, while they hold assets.
     *
     * @param  \Closure(Employee): bool  $check
     */
    public static function inUseWhen(\Closure $check): void
    {
        static::$usageChecks[] = $check;
    }

    public function isInUse(): bool
    {
        foreach (static::$usageChecks as $check) {
            if ($check($this)) {
                return true;
            }
        }

        return false;
    }

    /** "•••• 1234": enough to tell two IDs apart on a list, not enough to use. */
    public function maskedNationalId(): ?string
    {
        $id = self::normaliseNationalId($this->national_id);

        return $id === null ? null : '•••• '.mb_substr($id, -4);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<EmergencyContact, $this> */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class)->orderBy('slot');
    }

    /** @return HasOne<EmergencyContact, $this> */
    public function firstContact(): HasOne
    {
        return $this->hasOne(EmergencyContact::class)->where('slot', 1);
    }

    /** @return HasOne<EmergencyContact, $this> */
    public function secondContact(): HasOne
    {
        return $this->hasOne(EmergencyContact::class)->where('slot', 2);
    }

    /** @param  Builder<Employee>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', EmployeeStatus::Active);
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->oid})";
    }

    /**
     * Personal data is recorded as having changed, never with its value: the
     * audit log is readable by people who may not see it.
     */
    protected function auditRedacts(string $key): bool
    {
        return in_array($key, [...self::SENSITIVE, 'national_id_hash'], true);
    }

    /** @return array<int, string> */
    protected function auditExcluded(): array
    {
        return ['national_id_hash'];
    }
}
