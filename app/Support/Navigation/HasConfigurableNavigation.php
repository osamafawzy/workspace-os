<?php

namespace App\Support\Navigation;

use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Lets a Filament resource or page take its place in the sidebar from the
 * navigation registry rather than from its own static properties.
 *
 * Use it and set `$navigationKey` to the item's key in config/navigation.php.
 * Label, group, order and whether it shows at all then follow
 * Settings → Navigation. Hiding an item only takes it out of the sidebar —
 * who may open the screen is still decided by its policy.
 */
trait HasConfigurableNavigation
{
    /** @return array<string, mixed>|null */
    protected static function navigationEntry(): ?array
    {
        return app(Navigation::class)->item(static::$navigationKey);
    }

    public static function getNavigationLabel(): string
    {
        return static::navigationEntry()['label'] ?? parent::getNavigationLabel();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $entry = static::navigationEntry();

        return $entry === null ? parent::getNavigationGroup() : $entry['group'];
    }

    public static function getNavigationSort(): ?int
    {
        return static::navigationEntry()['sort'] ?? parent::getNavigationSort();
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        $entry = static::navigationEntry();

        if ($entry === null) {
            return parent::getNavigationIcon();
        }

        // Grouped items take no icon: the group has it.
        return $entry['group'] === null ? $entry['icon'] : null;
    }

    public static function getNavigationParentItem(): ?string
    {
        return null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && ! (static::navigationEntry()['hidden'] ?? false);
    }
}
