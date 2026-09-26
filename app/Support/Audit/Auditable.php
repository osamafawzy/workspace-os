<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Records every create, update and delete of the model in the audit log.
 *
 * Only the fields that actually changed are stored, before and after.
 * Timestamps are left out, and so is anything in `$hidden`: a password change
 * is recorded as having happened, never with the hash.
 *
 * Writes made with query-builder updates or inserts do not fire model events
 * and so are not seen here. Those are logged by the action that makes them.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $model->writeAudit('created', [], $model->auditValues($model->getAttributes()));
        });

        static::updated(function (Model $model): void {
            $changed = $model->auditValues($model->getChanges());

            if ($changed === []) {
                return;
            }

            $old = [];

            foreach (array_keys($changed) as $key) {
                $old[$key] = $model->auditRedacts($key) ? '(hidden)' : $model->auditScalar($model->getOriginal($key));
            }

            $model->writeAudit($model->auditAction('updated', $changed), $old, $changed);
        });

        static::deleted(function (Model $model): void {
            $model->writeAudit('deleted', $model->auditValues($model->getAttributes()), []);
        });
    }

    /**
     * The action name to record. Override to say something more specific than
     * "updated" — a desk whose only change is its position was "moved".
     *
     * @param  array<string, mixed>  $changed
     */
    public function auditAction(string $event, array $changed): string
    {
        return $event;
    }

    /** The module the entry is filed under. Defaults to the model's module. */
    public function auditModule(): string
    {
        return Str::of(static::class)->startsWith('Modules\\')
            ? Str::of(static::class)->after('Modules\\')->before('\\')->toString()
            : 'Core';
    }

    /** How the record is named in the log. */
    public function auditLabel(): string
    {
        foreach (['name', 'title', 'email'] as $attribute) {
            if (filled($this->getAttribute($attribute))) {
                return (string) $this->getAttribute($attribute);
            }
        }

        return class_basename(static::class).' #'.$this->getKey();
    }

    /** @return array<int, string> */
    protected function auditExcluded(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditValues(array $attributes): array
    {
        $skip = [
            ...$this->auditExcluded(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ];

        $values = [];

        foreach (array_keys($attributes) as $key) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            $values[$key] = $this->auditRedacts($key) ? '(hidden)' : $this->auditScalar($this->getAttribute($key));
        }

        return $values;
    }

    protected function auditRedacts(string $key): bool
    {
        return in_array($key, $this->getHidden(), true);
    }

    protected function auditScalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            is_object($value) && method_exists($value, 'toArray') => $value->toArray(),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function writeAudit(string $action, array $old, array $new): void
    {
        app(AuditLogger::class)->log($action, $this->auditModule(), $this, $old, $new, $this->auditLabel());
    }
}
