<?php

namespace Modules\Settings\Filament\Admin\Resources\Accounts;

use Modules\Settings\Filament\Admin\Resources\Accounts\Pages\ManageAccounts;
use Modules\Settings\Filament\Admin\Support\LookupResource;
use Modules\Settings\Models\Account;

class AccountResource extends LookupResource
{
    protected static ?string $model = Account::class;

    protected static string $navigationKey = 'accounts';

    public static function getPages(): array
    {
        return [
            'index' => ManageAccounts::route('/'),
        ];
    }
}
