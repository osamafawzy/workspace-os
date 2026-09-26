<?php

namespace Modules\Workspace\Filament\Admin\Pages;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Modules\Workspace\Filament\Admin\Actions\WorkstationDetailsAction;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Modules\Workspace\Models\Workstation;
use Modules\Workspace\Search\WorkstationSearch;

/**
 * Floor Management → Search Workstation.
 *
 * One box for everything written on a desk or its cable, results as you type,
 * and from each result its details or straight to it on the floor map. The
 * search is in the address bar (?q=), so a result list can be sent to somebody.
 */
class SearchWorkstation extends Page
{
    use HasConfigurableNavigation;

    protected static string $navigationKey = 'search-workstation';

    protected static ?string $slug = 'search-workstation';

    protected static ?string $title = 'Search Workstation';

    protected string $view = 'workspace::filament.pages.search-workstation';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public static function canAccess(): bool
    {
        return WorkstationResource::canViewAny();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    #[Computed]
    public function results(): array
    {
        $term = trim($this->search);

        if ($term === '') {
            return ['rows' => [], 'total' => 0];
        }

        $search = app(WorkstationSearch::class);
        $desks = $search->search($term);
        $user = auth()->user();

        return [
            'rows' => $desks->map(fn (Workstation $desk): array => [
                'id' => $desk->getKey(),
                'name' => $desk->name,
                'floor' => $desk->floor?->fullName(),
                'status' => $desk->status,
                'computer' => $desk->computer_name,
                'port' => $desk->switchPort?->label(),
                'ip' => $desk->ip_address,
                'placed' => $desk->mapObject !== null,
                'matched' => $search->matchedFields($desk, $term),
                'locateUrl' => $desk->mapObject && $desk->floor && ($user?->can('view', $desk->floor) ?? false)
                    ? WorkstationDetailsAction::locateUrl($desk)
                    : null,
            ])->all(),
            'total' => $desks->count() < WorkstationSearch::LIMIT ? $desks->count() : $search->count($term),
        ];
    }

    public function updatedSearch(): void
    {
        unset($this->results);
    }

    public function detailsAction(): Action
    {
        return WorkstationDetailsAction::make('details');
    }
}
