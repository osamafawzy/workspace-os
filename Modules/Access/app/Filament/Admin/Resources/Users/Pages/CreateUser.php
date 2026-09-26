<?php

namespace Modules\Access\Filament\Admin\Resources\Users\Pages;

use App\Support\Audit\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Modules\Access\Filament\Admin\Resources\Users\UserResource;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        $roles = $this->record->roles()->orderBy('name')->pluck('name')->all();

        if ($roles !== []) {
            app(AuditLogger::class)->log('roles changed', 'Access', $this->record, ['roles' => []], ['roles' => $roles], $this->record->email);
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
