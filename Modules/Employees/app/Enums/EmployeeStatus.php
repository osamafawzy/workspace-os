<?php

namespace Modules\Employees\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether somebody works here.
 *
 * Deliberately few: what later screens need to know is whether an employee can
 * be given assets (active), is away but still holds them (on leave), or has
 * gone and should have handed everything back (left).
 */
enum EmployeeStatus: string implements HasColor, HasLabel
{
    case Active = 'active';

    case OnLeave = 'on_leave';

    case Left = 'left';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnLeave => 'On Leave',
            self::Left => 'Left',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::OnLeave => 'warning',
            self::Left => 'gray',
        };
    }

    /**
     * A status as a person or an HR export writes it: "Active", "on leave",
     * "Terminated", "Resigned", "Inactive"… Null when it is none of them.
     */
    public static function fromLoose(string $value): ?self
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($value));

        return match ($key) {
            'active', 'employed', 'current', 'working' => self::Active,
            'onleave', 'leave', 'leaveofabsence', 'loa', 'suspended' => self::OnLeave,
            'left', 'terminated', 'resigned', 'inactive', 'exited', 'withdrawn', 'separated', 'former' => self::Left,
            default => null,
        };
    }
}
