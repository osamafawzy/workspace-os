<?php

namespace App\Filament\Pages;

use App\Support\Navigation\Navigation;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Where a planned sidebar entry leads until its module is built.
 *
 * One page serves them all, told which entry it is standing in for by the
 * `item` query string. It is honest about it: the page says what the module
 * will do and which phase delivers it, rather than showing an empty screen.
 */
class ComingSoon extends Page
{
    protected static ?string $slug = 'coming-soon';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.coming-soon';

    #[Url]
    public string $item = '';

    /** @return array{label: string, group: string|null, icon: string|null, sort: int, hidden: bool, phase: int|null, summary: string|null} */
    public function entry(): array
    {
        $entry = app(Navigation::class)->planned()[$this->item] ?? null;

        abort_if($entry === null, 404);

        return $entry;
    }

    public function getTitle(): string
    {
        return $this->entry()['label'];
    }

    /**
     * One sidebar entry per planned item, all pointing here.
     *
     * @return array<int, NavigationItem>
     */
    public static function plannedNavigationItems(): array
    {
        $items = [];

        foreach (array_keys(config('navigation.items', [])) as $key) {
            if (! isset(config("navigation.items.{$key}")['phase'])) {
                continue;
            }

            // Resolved late: the labels and order can be overridden from the
            // database, which is not there yet when the panel is registered.
            $entry = fn (): array => app(Navigation::class)->item($key);

            $items[] = NavigationItem::make(fn (): string => $entry()['label'])
                ->key("planned.{$key}")
                ->group(fn (): ?string => $entry()['group'])
                ->icon(fn (): ?string => $entry()['group'] === null ? $entry()['icon'] : null)
                ->sort(fn (): int => $entry()['sort'])
                ->badge(fn (): string => 'Phase '.$entry()['phase'], 'gray')
                ->hidden(fn (): bool => $entry()['hidden'])
                ->url(fn (): string => static::getUrl(['item' => $key]))
                ->isActiveWhen(fn (): bool => request()->routeIs(static::getRouteName()) && request()->query('item') === $key);
        }

        return $items;
    }
}
