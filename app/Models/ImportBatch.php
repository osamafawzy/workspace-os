<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded spreadsheet, checked and waiting to be imported — or already
 * imported, kept as the record of what it did.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $importer
 * @property string $file_name
 * @property string $status
 * @property array<string, mixed>|null $options
 * @property array<string, int>|null $summary
 */
class ImportBatch extends Model
{
    public const CHECKED = 'checked';

    public const IMPORTED = 'imported';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'importer',
        'file_name',
        'status',
        'options',
        'summary',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'summary' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function isChecked(): bool
    {
        return $this->status === self::CHECKED;
    }

    public function count(string $key): int
    {
        return (int) ($this->summary[$key] ?? 0);
    }
}
