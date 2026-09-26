<?php

namespace Modules\Assets\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** What shape an asset is in, as whoever last handled it judged. */
enum AssetCondition: string implements HasColor, HasLabel
{
    case New = 'new';

    case Good = 'good';

    case Fair = 'fair';

    case Damaged = 'damaged';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New, self::Good => 'success',
            self::Fair => 'warning',
            self::Damaged => 'danger',
        };
    }

    public static function fromLoose(string $value): ?self
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($value));

        return match ($key) {
            'new', 'brandnew', 'unused' => self::New,
            'good', 'working', 'ok', 'excellent' => self::Good,
            'fair', 'used', 'worn', 'average' => self::Fair,
            'damaged', 'broken', 'poor', 'faulty' => self::Damaged,
            default => null,
        };
    }
}
