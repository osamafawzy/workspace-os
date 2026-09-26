<?php

namespace Modules\Workspace\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What state a desk is in.
 *
 * An enum rather than free text, because later screens act on it — the map
 * colours a faulty desk, the dashboard counts the offline ones — and "Faulty",
 * "faulty " and "Broken" cannot all mean the same thing to a query.
 */
enum WorkstationStatus: string implements HasColor, HasIcon, HasLabel
{
    /** In use: somebody works here and the machine is up. */
    case Active = 'active';

    /** Free: a working desk nobody is assigned to. */
    case Available = 'available';

    /** Should be up and is not answering. */
    case Offline = 'offline';

    /** Known broken: hardware, cabling or network. */
    case Faulty = 'faulty';

    /** Out of use on purpose while it is worked on. */
    case UnderMaintenance = 'maintenance';

    /** No longer a desk. Kept for its history rather than deleted. */
    case Removed = 'removed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Available => 'Available',
            self::Offline => 'Offline',
            self::Faulty => 'Faulty',
            self::UnderMaintenance => 'Under Maintenance',
            self::Removed => 'Removed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Available => 'info',
            self::Offline => 'gray',
            self::Faulty => 'danger',
            self::UnderMaintenance => 'warning',
            self::Removed => 'gray',
        };
    }

    /** The colour a desk is drawn in on the dark floor map. */
    public function mapColor(): string
    {
        return match ($this) {
            self::Active => '#22c55e',
            self::Available => '#38bdf8',
            self::Offline => '#94a3b8',
            self::Faulty => '#ef4444',
            self::UnderMaintenance => '#f59e0b',
            self::Removed => '#475569',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Active => Heroicon::OutlinedCheckCircle,
            self::Available => Heroicon::OutlinedPlusCircle,
            self::Offline => Heroicon::OutlinedSignalSlash,
            self::Faulty => Heroicon::OutlinedExclamationTriangle,
            self::UnderMaintenance => Heroicon::OutlinedWrenchScrewdriver,
            self::Removed => Heroicon::OutlinedArchiveBox,
        };
    }

    /**
     * Reads a status the way people write it in a spreadsheet: the stored
     * value, the label, any case, with or without spaces.
     */
    public static function fromLoose(?string $value): ?self
    {
        $wanted = preg_replace('/[^a-z]/', '', mb_strtolower((string) $value));

        if ($wanted === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($wanted === $case->value || $wanted === preg_replace('/[^a-z]/', '', mb_strtolower($case->getLabel()))) {
                return $case;
            }
        }

        return null;
    }
}
