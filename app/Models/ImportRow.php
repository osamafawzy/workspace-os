<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an uploaded spreadsheet and what the check made of it.
 *
 * @property int $id
 * @property int $import_batch_id
 * @property int $row_number
 * @property array<string, string> $data
 * @property string $status
 * @property list<array{level: string, text: string}>|null $messages
 * @property int|null $record_id
 */
class ImportRow extends Model
{
    /** A row that will create a new record. */
    public const NEW = 'new';

    /** A row matching an existing record, which will be updated. */
    public const UPDATE = 'update';

    /** A row matching an existing record, which is being left alone. */
    public const UNCHANGED = 'unchanged';

    /** The same record as an earlier row in the same file. */
    public const DUPLICATE = 'duplicate';

    /** Missing required values, or values that are not valid. */
    public const INVALID = 'invalid';

    public const IMPORTED = 'imported';

    public const UPDATED = 'updated';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'data',
        'status',
        'messages',
        'record_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'messages' => 'array',
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /** @return list<string> */
    public function errors(): array
    {
        return collect($this->messages ?? [])->where('level', 'error')->pluck('text')->values()->all();
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return collect($this->messages ?? [])->where('level', 'warning')->pluck('text')->values()->all();
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::NEW => 'New',
            self::UPDATE => 'Will update',
            self::UNCHANGED => 'Already exists',
            self::DUPLICATE => 'Duplicate',
            self::INVALID => 'Invalid',
            self::IMPORTED => 'Imported',
            self::UPDATED => 'Updated',
            self::SKIPPED => 'Skipped',
            self::FAILED => 'Failed',
        ];
    }

    /** @return array<string, string> */
    public static function statusColors(): array
    {
        return [
            self::NEW => 'success',
            self::UPDATE => 'info',
            self::UNCHANGED => 'gray',
            self::DUPLICATE => 'warning',
            self::INVALID => 'danger',
            self::IMPORTED => 'success',
            self::UPDATED => 'info',
            self::SKIPPED => 'gray',
            self::FAILED => 'danger',
        ];
    }
}
