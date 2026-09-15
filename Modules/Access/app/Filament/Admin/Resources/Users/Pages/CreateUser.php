<?php

namespace Modules\Access\Filament\Admin\Resources\Users\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Access\Filament\Admin\Resources\Users\UserResource;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
