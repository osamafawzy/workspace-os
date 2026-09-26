<?php

namespace Modules\Assets\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where an asset is in its life. */
enum AssetStatus: string implements HasColor, HasLabel
{
    /** In stock and ready to hand out. */
    case Available = 'available';

    /** With an employee. */
    case Assigned = 'assigned';

    /** Handed back and waiting to be checked before it goes out again. */
    case Returned = 'returned';

    case InRepair = 'in_repair';

    case Lost = 'lost';

    /** Out of service for good: disposed of, scrapped, sold. Kept for its history. */
    case Retired = 'retired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Assigned => 'Assigned',
            self::Returned => 'Returned',
            self::InRepair => 'In Repair',
            self::Lost => 'Lost',
            self::Retired => 'Retired',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Assigned => 'info',
            self::Returned => 'warning',
            self::InRepair => 'warning',
            self::Lost => 'danger',
            self::Retired => 'gray',
        };
    }

    /** The colour on charts, matching the badge colours. */
    public function chartColor(): string
    {
        return match ($this) {
            self::Available => '#22c55e',
            self::Assigned => '#3b82f6',
            self::Returned => '#f59e0b',
            self::InRepair => '#f97316',
            self::Lost => '#ef4444',
            self::Retired => '#94a3b8',
        };
    }

    public static function fromLoose(string $value): ?self
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($value));

        return match ($key) {
            'available', 'instock', 'stock', 'spare', 'free', 'new' => self::Available,
            'assigned', 'inuse', 'issued', 'allocated', 'deployed' => self::Assigned,
            'returned' => self::Returned,
            'inrepair', 'repair', 'maintenance', 'faulty', 'broken' => self::InRepair,
            'lost', 'stolen', 'missing' => self::Lost,
            'retired', 'disposed', 'scrapped', 'writtenoff', 'sold' => self::Retired,
            default => null,
        };
    }
}
