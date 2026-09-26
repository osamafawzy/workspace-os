<?php

namespace Modules\Settings\Filament\Admin\Resources\Accounts\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Settings\Filament\Admin\Resources\Accounts\AccountResource;

class ManageAccounts extends ManageRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New account'),
        ];
    }
}
