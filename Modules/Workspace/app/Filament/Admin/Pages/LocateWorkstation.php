<?php

namespace Modules\Workspace\Filament\Admin\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Workspace\Filament\Admin\Actions\WorkstationDetailsAction;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Modules\Workspace\Search\WorkstationSearch;

/**
 * A link that finds a desk: /admin/locate?q=WS-024.
 *
 * Nothing to look at — it works out which desk is meant and sends the browser
 * to it on its floor's map. For a link pasted into a ticket or a chat, where
 * whoever wrote it knew the desk but not which floor it is on. When the search
 * does not settle on one desk that is on a map, it lands on Search Workstation
 * with the search filled in, so the choice is made by a person.
 */
class LocateWorkstation extends Page
{
    protected static ?string $slug = 'locate';

    protected static ?string $title = 'Locate workstation';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'workspace::filament.pages.locate';

    public static function canAccess(): bool
    {
        return WorkstationResource::canViewAny();
    }

    public function mount(): void
    {
        $term = trim((string) request()->query('q', ''));
        $desk = $term === '' ? null : app(WorkstationSearch::class)->locate($term);

        if ($desk?->mapObject && $desk->floor && auth()->user()?->can('view', $desk->floor)) {
            $this->redirect(WorkstationDetailsAction::locateUrl($desk));

            return;
        }

        if ($desk) {
            Notification::make()
                ->title("{$desk->name} is not on its floor's map yet")
                ->warning()
                ->send();
        }

        $this->redirect(SearchWorkstation::getUrl(['q' => $term]));
    }
}
